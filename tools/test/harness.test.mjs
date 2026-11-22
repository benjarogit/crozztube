import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { randomBytes } from 'node:crypto';
import { execFileSync, spawnSync } from 'node:child_process';
import { git, load, localPath, localState, setMode, digest, verify, assertVerification, begin, publish, published, stopDecision, writeJson, runtimeEvidence } from '../harness/workflow.mjs';
import { ADAPTER_DENIAL, evaluateHarnessCommand, shouldEvaluate, harnessCommands } from '../harness/opencode-adapter.mjs';

const source = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const branch = 'codex/viewer-community-features';
test('corrupt runtime receipts are not accepted and do not break status inspection', t => {
  const { root } = fixture(t);
  const file = localPath(root, 'hook-receipts.jsonl');
  fs.mkdirSync(path.dirname(file), { recursive: true });
  fs.writeFileSync(file, '{"event":"PreToolUse"}\npartial receipt');
  assert.equal(runtimeEvidence(root), false);
});
function fixture(t) {
  const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'crozztube-workflow-'));
  const root = path.join(directory, 'work');
  const remote = path.join(directory, 'remote.git');
  fs.mkdirSync(root);
  execFileSync('git', ['init', '--bare', '--quiet', remote]);
  execFileSync('git', ['init', '--quiet', '-b', branch, root]);
  git(root, ['config', 'user.name', 'Harness Fixture']);
  git(root, ['config', 'user.email', 'harness@example.invalid']);
  git(root, ['config', 'core.hooksPath', path.join(directory, 'no-shared-hooks')]);
  git(root, ['remote', 'add', 'origin', remote]);
  fs.mkdirSync(path.join(root, 'harness'));
  writeJson(path.join(root, 'harness/plan.json'), { schemaVersion: 1, branch, remote: 'origin', steps: [
    { id: 1, checks: ['unit'] }, { id: 2, checks: ['second'] }, { id: 3, checks: ['third'] },
  ], checks: { unit: { argv: [process.execPath, '-e', 'process.exit(0)'] } } });
  writeJson(path.join(root, 'harness/progress.json'), { schemaVersion: 1, activeStep: 1, steps: [
    { id: 1, status: 'active' }, { id: 2, status: 'pending' }, { id: 3, status: 'pending' },
  ] });
  fs.writeFileSync(path.join(root, 'app.txt'), 'original source\n');
  fs.mkdirSync(path.join(root, '.codex'));
  fs.mkdirSync(path.join(root, '.cursor'));
  fs.mkdirSync(path.join(root, 'tools/harness'), { recursive: true });
  for (const file of ['.codex/hooks.json', '.cursor/hooks.json', 'tools/harness/codex-hook.mjs', 'tools/harness/workflow.mjs', 'tools/harness/opencode-adapter.mjs']) fs.copyFileSync(path.join(source, file), path.join(root, file));
  fs.mkdirSync(path.join(root, '.opencode/plugins/crozztube-harness'), { recursive: true });
  fs.copyFileSync(path.join(source, '.opencode/plugins/crozztube-harness/index.ts'), path.join(root, '.opencode/plugins/crozztube-harness/index.ts'));
  git(root, ['add', '.']); git(root, ['commit', '-qm', 'Fixture baseline']);
  setMode(root, 'implementing');
  t.after(() => fs.rmSync(directory, { recursive: true, force: true }));
  return { root, remote };
}
function hook(root, event, input = {}) {
  const result = spawnSync(process.execPath, [path.join(root, 'tools/harness/codex-hook.mjs'), event], {
    cwd: root, input: JSON.stringify({ hook_event_name: event, session_id: 'isolated-unit-fixture', ...input }), encoding: 'utf8', timeout: 30_000,
  });
  assert.equal(result.status, 0, result.stderr);
  return JSON.parse(result.stdout);
}
function probe(root) {
  const output = hook(root, 'PreToolUse', { tool_name: 'exec_command', tool_input: { cmd: 'node tools/harness/cli.mjs deny-probe' } });
  assert.equal(output.hookSpecificOutput.permissionDecision, 'deny');
}
function stage(root) { git(root, ['add', '.']); }

test('only one ordered step is current; direct progress edits cannot start a later step', t => {
  const { root } = fixture(t);
  assert.throws(() => begin(root, 2), /accepted checks/);
  assert.throws(() => begin(root, 3), /next sequential/);
  const progress = load(root).progress;
  progress.steps[1].status = 'active';
  writeJson(path.join(root, 'harness/progress.json'), progress);
  assert.throws(() => load(root), /out of sequence/);
});

test('proof is bound to source, check definitions, untracked files and deletions', t => {
  const { root } = fixture(t);
  verify(root); assertVerification(root);
  const before = digest(root);
  fs.writeFileSync(path.join(root, 'new.txt'), 'new source');
  assert.notEqual(digest(root), before);
  assert.throws(() => assertVerification(root), /stale/);
  fs.unlinkSync(path.join(root, 'new.txt'));
  fs.unlinkSync(path.join(root, 'app.txt'));
  assert.throws(() => assertVerification(root), /stale/);
});

test('failed checks invalidate previous success and keep the step blocked', t => {
  const { root } = fixture(t);
  verify(root);
  const plan = load(root).plan;
  plan.checks.unit.argv = [process.execPath, '-e', 'process.exit(7)'];
  writeJson(path.join(root, 'harness/plan.json'), plan);
  assert.throws(() => verify(root), /remains open/);
  assert.equal(localState(root).mode, 'blocked');
  assert.equal(fs.existsSync(localPath(root, 'verification.json')), false);
  assert.throws(() => begin(root, 2));
});

test('tested increments may be published while full acceptance stays blocked', t => {
  const { root } = fixture(t);
  const plan = load(root).plan;
  plan.steps[0].checkpointChecks = ['unit'];
  plan.steps[0].checks = ['external-live-check'];
  writeJson(path.join(root, 'harness/plan.json'), plan);
  stage(root); probe(root);
  verify(root, { checkpoint: true });
  publish(root, 'test: component checkpoint');
  assert.equal(load(root).record.status, 'active');
  assert.throws(() => publish(root, 'not complete', { complete: true, note: 'Only incremental checks passed.' }), /Checkpoint checks cannot complete/);
  assert.throws(() => begin(root, 2));
  assert.throws(() => verify(root), /not been implemented/);
});

test('missing future checks are failures, never successful placeholders', t => {
  const { root } = fixture(t);
  const plan = load(root).plan;
  plan.steps[0].checks = ['not-implemented'];
  writeJson(path.join(root, 'harness/plan.json'), plan);
  assert.throws(() => verify(root), /not been implemented/);
  assert.throws(() => publish(root, 'do not commit'), /verify/);
});

test('acceptance requires a runtime hook, a note and explicitly staged files', t => {
  const { root } = fixture(t);
  verify(root);
  assert.throws(() => publish(root, 'complete', { complete: true, note: 'checked' }), /real trusted project hook/);
  probe(root);
  assert.throws(() => publish(root, 'complete', { complete: true }), /concrete acceptance/);
  fs.writeFileSync(path.join(root, 'app.txt'), 'changed source');
  verify(root);
  assert.throws(() => publish(root, 'complete', { complete: true, note: 'checked' }), /stage all/);
});

test('checkpoint commits and pushes exactly reviewed paths without completing the step', t => {
  const { root } = fixture(t);
  fs.writeFileSync(path.join(root, 'app.txt'), 'tested increment');
  stage(root); verify(root);
  publish(root, 'test: checked increment');
  assert.equal(load(root).record.status, 'active');
  assert.equal(git(root, ['rev-parse', 'HEAD']), git(root, ['ls-remote', '--heads', 'origin', branch]).split(/\s+/)[0]);
  assert.throws(() => begin(root, 2), /accepted checks/);
});

test('a fully checked, accepted and remotely confirmed step unlocks only its successor', t => {
  const { root } = fixture(t);
  probe(root); verify(root);
  publish(root, 'test: accepted step', { complete: true, note: 'Isolated gate and publication checks passed.' });
  assert.equal(published(root, load(root), true), true);
  assert.equal(localState(root).mode, 'discussion');
  assert.throws(() => begin(root, 3), /next sequential/);
  begin(root, 2);
  assert.equal(load(root).step.id, 2);
  assert.equal(load(root).record.status, 'active');
  assert.throws(() => verify(root), /not been implemented/);
});

test('failed push retains the local commit but blocks completion and the next step; retry recovers', t => {
  const { root, remote } = fixture(t);
  probe(root); verify(root);
  git(root, ['remote', 'set-url', 'origin', path.join(root, 'missing-remote.git')]);
  assert.throws(() => publish(root, 'test: publish failure', { complete: true, note: 'Checked fixture.' }), /Push or remote/);
  assert.equal(localState(root).mode, 'blocked');
  assert.equal(published(root, load(root)), false);
  assert.throws(() => begin(root, 2));
  git(root, ['remote', 'set-url', 'origin', remote]);
  setMode(root, 'implementing');
  publish(root, 'test: recover publication', { complete: true, note: 'Checked fixture.' });
  assert.equal(published(root, load(root), true), true);
});

test('pause and discussion never force continuation; implementation gets one repair only', t => {
  const { root } = fixture(t);
  const first = stopDecision(root, { turn_id: 'test-turn' });
  assert.equal(first.decision, 'block');
  const second = stopDecision(root, { turn_id: 'test-turn', stop_hook_active: true });
  assert.equal(second.decision, undefined);
  assert.equal(localState(root).mode, 'blocked');
  assert.deepEqual(stopDecision(root, { turn_id: 'test-turn' }), {});
  setMode(root, 'paused', 'Explicit test pause.');
  assert.deepEqual(stopDecision(root, {}), {});
  setMode(root, 'discussion', 'Read-only question.');
  assert.deepEqual(stopDecision(root, {}), {});
  assert.throws(() => begin(root, 2));
});

test('hook contract handles actual shell argument shapes and preserves user interruptions', t => {
  const { root } = fixture(t);
  const result = hook(root, 'PreToolUse', { tool_name: 'Bash', tool_input: { command: 'node tools/harness/cli.mjs deny-probe' } });
  assert.equal(result.hookSpecificOutput.permissionDecision, 'deny');
  const cursorResult = hook(root, 'beforeShellExecution', { command: 'node tools/harness/cli.mjs deny-probe' });
  assert.equal(cursorResult.permission, 'deny');
  const transition = hook(root, 'PreToolUse', { tool_name: 'exec_command', tool_input: { cmd: 'node tools/harness/cli.mjs begin 2' } });
  assert.equal(transition.hookSpecificOutput.permissionDecision, 'deny');
  assert.equal(runtimeEvidence(root), true);
  fs.appendFileSync(path.join(root, 'tools/harness/codex-hook.mjs'), '\n// changed\n');
  assert.equal(runtimeEvidence(root), false);
  hook(root, 'UserPromptSubmit', { prompt: 'Wie funktioniert das?' });
  assert.equal(localState(root).mode, 'discussion');
  hook(root, 'UserPromptSubmit', { prompt: 'Warte mal bitte' });
  assert.equal(localState(root).mode, 'paused');
  const receipts = fs.readFileSync(localPath(root, 'hook-receipts.jsonl'), 'utf8');
  assert.equal(receipts.includes('Wie funktioniert'), false);
});

test('checks cannot rewrite source after passing and then certify the rewritten state', t => {
  const { root } = fixture(t);
  const plan = load(root).plan;
  plan.checks.unit.argv = [process.execPath, '-e', "require('node:fs').writeFileSync('app.txt', 'untested change')"];
  writeJson(path.join(root, 'harness/plan.json'), plan);
  assert.throws(() => verify(root), /Checks changed the source/);
  assert.equal(fs.existsSync(localPath(root, 'verification.json')), false);
});

test('changing only the index also invalidates a source verification', t => {
  const { root } = fixture(t);
  fs.writeFileSync(path.join(root, 'app.txt'), 'changed but not staged');
  verify(root);
  stage(root);
  assert.throws(() => assertVerification(root), /stale/);
});

test('secret scan detects a new credential and does not mistake benign modifier code for one', t => {
  const { root } = fixture(t);
  fs.copyFileSync(path.join(source, '.gitleaks.toml'), path.join(root, '.gitleaks.toml'));
  fs.mkdirSync(path.join(root, 'build/assets'), { recursive: true });
  fs.writeFileSync(path.join(root, 'build/assets/main-fixture.js'), 'const x=t.metaKey,a=t.ctrlKey,s=t.altKey;');
  stage(root);
  const executable = localPath(source, 'bin/gitleaks');
  assert.equal(fs.existsSync(executable), true, 'Run harness setup first.');
  const scan = () => spawnSync(process.execPath, [path.join(source, 'tools/harness/scan-secrets.mjs')], { cwd: root, env: { ...process.env, GITLEAKS_BIN: executable }, encoding: 'utf8', timeout: 30_000 });
  assert.equal(scan().status, 0);
  const fake = 'ghp_' + randomBytes(24).toString('hex').slice(0, 36);
  fs.writeFileSync(path.join(root, 'accidental.txt'), `github_token = "${fake}"\n`);
  stage(root);
  const rejected = scan();
  assert.equal(rejected.status, 1);
  assert.equal((rejected.stdout + rejected.stderr).includes(fake), false);
});

test('the OpenCode adapter forwards only gate-critical harness commands', () => {
  assert.equal(shouldEvaluate('node tools/harness/cli.mjs deny-probe'), true);
  assert.equal(shouldEvaluate('node tools/harness/cli.mjs begin 2'), true);
  assert.equal(shouldEvaluate('node tools/harness/cli.mjs status'), false);
  assert.equal(shouldEvaluate('git status --short'), false);
  assert.equal(shouldEvaluate('grep -r deny-probe.txt docs'), false);
  assert.equal(shouldEvaluate(''), false);
});

test('the OpenCode adapter turns a real hook denial into a runtime receipt', t => {
  const { root } = fixture(t);
  const probe = evaluateHarnessCommand({ root, command: 'node tools/harness/cli.mjs deny-probe', sessionID: 'adapter-probe-unit' });
  assert.equal(probe.deny, true);
  assert.match(probe.message, /CROZZTUBE_HARNESS_PROBE_BLOCKED/);
  assert.equal(runtimeEvidence(root), true);
});

test('the OpenCode adapter allows non-probe commands and blocks a premature transition', t => {
  const { root } = fixture(t);
  const allowed = evaluateHarnessCommand({ root, command: 'grep -r deny-probe docs', sessionID: 'adapter-allow-unit' });
  assert.equal(allowed.deny, false);
  assert.equal(runtimeEvidence(root), false);
  const transition = evaluateHarnessCommand({ root, command: 'node tools/harness/cli.mjs begin 2', sessionID: 'adapter-begin-unit' });
  assert.equal(transition.deny, true);
  assert.match(transition.message, /not accepted/);
});

test('runtime evidence is bound to the OpenCode adapter and plugin files', t => {
  const { root } = fixture(t);
  assert.equal(evaluateHarnessCommand({ root, command: 'node tools/harness/cli.mjs deny-probe', sessionID: 'adapter-digest-unit' }).deny, true);
  assert.equal(runtimeEvidence(root), true);
  fs.appendFileSync(path.join(root, 'tools/harness/opencode-adapter.mjs'), '\n// changed\n');
  assert.equal(runtimeEvidence(root), false);
  fs.appendFileSync(path.join(root, '.opencode/plugins/crozztube-harness/index.ts'), '\n// changed\n');
  fs.mkdirSync(path.join(root, '.opencode/plugins/extra'), { recursive: true });
  fs.writeFileSync(path.join(root, '.opencode/plugins/extra/index.ts'), 'export const extra = true;\n');
  assert.equal(runtimeEvidence(root), false);
});

test('the OpenCode adapter fails closed when the project hook cannot run', t => {
  const { root } = fixture(t);
  const failed = evaluateHarnessCommand({
    root,
    command: 'node tools/harness/cli.mjs deny-probe',
    sessionID: 'adapter-failure-unit',
    hookPath: path.join(root, 'tools/harness/missing-hook.mjs'),
  });
  assert.equal(failed.deny, true);
  assert.equal(failed.message, ADAPTER_DENIAL);
  assert.equal(runtimeEvidence(root), false);
});

// These are isolated fixture protocol checks, not proof of host plugin loading.
test('critical command recognition is shared across ordinary quoted tokens and separators', () => {
  const commands = [
    'node "tools/harness/cli.mjs" begin 2',
    "node 'tools/harness/cli.mjs' 'begin' '2'",
    'node tools/harness/cli.mjs begin "2"',
    'node tools/harness/cli.mjs begin 2; true',
    'node tools/harness/cli.mjs begin 2&&true',
    'node tools/harness/cli.mjs begin 2|cat',
    'node tools/harness/cli.mjs begin 2\ntrue',
  ];
  for (const command of commands) {
    assert.equal(shouldEvaluate(command), true, command);
    assert.deepEqual(harnessCommands(command), { probe: false, transitions: [2] }, command);
  }
  assert.deepEqual(harnessCommands('node tools/harness/cli.mjs begin 2; node tools/harness/cli.mjs begin 3'), {
    probe: false, transitions: [2, 3],
  });
});

test('quoted and compound forward-step commands cannot fall through to allow', t => {
  const { root } = fixture(t);
  for (const command of [
    'node "tools/harness/cli.mjs" begin 2',
    "node 'tools/harness/cli.mjs' 'begin' '2'",
    'node tools/harness/cli.mjs begin "2"',
    'node tools/harness/cli.mjs begin 2; true',
    'node tools/harness/cli.mjs begin 2&&true',
    'node tools/harness/cli.mjs begin 2|cat',
  ]) {
    const result = evaluateHarnessCommand({ root, command, sessionID: 'isolated-quoted-transition-fixture', node: process.execPath });
    assert.equal(result.deny, true, command);
    assert.match(result.message, /not accepted/, command);
  }
  assert.equal(load(root).step.id, 1);
  assert.equal(runtimeEvidence(root), false);
});

test('quoted and compound probes preserve the denial in both hook contracts', t => {
  const { root } = fixture(t);
  for (const command of [
    'node "tools/harness/cli.mjs" deny-probe',
    "node 'tools/harness/cli.mjs' 'deny-probe'; true",
    'node tools/harness/cli.mjs deny-probe&&true',
  ]) {
    const result = evaluateHarnessCommand({ root, command, sessionID: 'isolated-quoted-probe-fixture', node: process.execPath });
    assert.equal(result.deny, true, command);
    assert.match(result.message, /CROZZTUBE_HARNESS_PROBE_BLOCKED/, command);
    const codex = hook(root, 'PreToolUse', { tool_name: 'exec_command', tool_input: { cmd: command } });
    assert.equal(codex.hookSpecificOutput.permissionDecision, 'deny', command);
  }
});

test('a valid first transition cannot conceal a later invalid transition', t => {
  const { root } = fixture(t);
  probe(root); verify(root);
  publish(root, 'test: compound-command fixture prerequisite', { complete: true, note: 'Synthetic fixture prerequisite only; no host evidence.' });
  assert.equal(evaluateHarnessCommand({ root, command: 'node tools/harness/cli.mjs begin 2', sessionID: 'isolated-compound-fixture', node: process.execPath }).deny, false);
  const result = evaluateHarnessCommand({ root, command: 'node tools/harness/cli.mjs begin 2; node tools/harness/cli.mjs begin 3', sessionID: 'isolated-compound-fixture', node: process.execPath });
  assert.equal(result.deny, true);
  assert.match(result.message, /not accepted/);
  assert.equal(load(root).step.id, 1);
});

test('missing or unknown permission schemas fail closed even with exit zero and valid JSON', t => {
  const { root } = fixture(t);
  const hookPath = path.join(root, 'synthetic-protocol-child.mjs');
  for (const payload of [{}, null, [], true, 1, 'allow', { permission: 'unexpected' }, { permission: 1 }]) {
    fs.writeFileSync(hookPath, `console.log(${JSON.stringify(JSON.stringify(payload))});\n`);
    const result = evaluateHarnessCommand({ root, command: 'node tools/harness/cli.mjs begin 2', sessionID: 'isolated-invalid-schema-fixture', hookPath, node: process.execPath });
    assert.deepEqual(result, { deny: true, message: ADAPTER_DENIAL }, JSON.stringify(payload));
  }
  assert.equal(runtimeEvidence(root), false);
});

test('only an explicit recognized permission object releases a matched command', t => {
  const { root } = fixture(t);
  const hookPath = path.join(root, 'synthetic-protocol-child.mjs');
  fs.writeFileSync(hookPath, 'console.log(JSON.stringify({ permission: "allow" }));\n');
  assert.deepEqual(evaluateHarnessCommand({ root, command: 'node tools/harness/cli.mjs begin 2', hookPath, node: process.execPath }), { deny: false });
  fs.writeFileSync(hookPath, 'console.log(JSON.stringify({ permission: "deny", agent_message: "Synthetic explicit denial" }));\n');
  assert.deepEqual(evaluateHarnessCommand({ root, command: 'node tools/harness/cli.mjs begin 2', hookPath, node: process.execPath }), { deny: true, message: 'Synthetic explicit denial' });
  assert.equal(runtimeEvidence(root), false);
});

test('invalid JSON, empty output, child failure and timeout all deny matched commands', t => {
  const { root } = fixture(t);
  const hookPath = path.join(root, 'synthetic-protocol-child.mjs');
  for (const script of ["console.log('not JSON')", "console.log('')", 'process.exit(2)']) {
    fs.writeFileSync(hookPath, script);
    assert.deepEqual(evaluateHarnessCommand({ root, command: 'node tools/harness/cli.mjs begin 2', hookPath, node: process.execPath }), { deny: true, message: ADAPTER_DENIAL });
  }
  fs.writeFileSync(hookPath, 'setInterval(() => {}, 1000);\n');
  assert.deepEqual(evaluateHarnessCommand({ root, command: 'node tools/harness/cli.mjs begin 2', hookPath, node: process.execPath, timeout: 50 }), { deny: true, message: ADAPTER_DENIAL });
  assert.equal(runtimeEvidence(root), false);
});

test('unrelated commands remain outside forwarding even if the configured child is absent', t => {
  const { root } = fixture(t);
  for (const command of ['git status --short', 'node tools/harness/cli.mjs status', 'grep -r deny-probe docs', 'grep -r deny-probe.txt docs', '']) {
    assert.equal(shouldEvaluate(command), false, command);
    assert.deepEqual(evaluateHarnessCommand({ root, command, hookPath: path.join(root, 'missing-hook.mjs'), node: process.execPath }), { deny: false }, command);
  }
  assert.equal(runtimeEvidence(root), false);
});
