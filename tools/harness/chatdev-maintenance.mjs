import fs from 'node:fs';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
import { git, load, digest, indexDigest, assertStaged, localPath, localState, writeJson, setMode } from './workflow.mjs';

export const agentsAppend = '\n## Shared engineering organization\n\nRead `harness/chatdev/CORE.md` and use the shared role definitions and local\njournal through `tools/chatdev/cli.mjs`. This adds no gate or permission and\nnever supersedes the authoritative harness plan/progress or existing hooks.\n';
export const readmeAppend = '\n## Approved ChatDev tooling integration\n\nThe isolated `codex/chatdev-integration` branch uses `chatdev-verify` and\n`chatdev-checkpoint --message "..."` for the narrowly approved orchestration\ntooling only. See `tools/chatdev/README.md`. Existing feature gates, hooks,\nplan/progress and Impeccable maintenance remain unchanged. No feature step is\naccepted or advanced and no main-branch merge is authorized by this path.\n';
const integrationImport = "import { verifyChatdev, publishChatdev } from './chatdev-maintenance.mjs';\nimport { verifySeries, publishSeries } from './series-maintenance.mjs';\n";
const integrationCommands = "  else if (command === 'series-verify') verifySeries(root);\n  else if (command === 'series-checkpoint') publishSeries(root, option('--message'));\n  else if (command === 'chatdev-verify') verifyChatdev(root);\n  else if (command === 'chatdev-checkpoint') publishChatdev(root, option('--message'));\n";
// Owner-approved receipt repair only; this is not general hook-edit permission.
const receiptHook = 'tools/harness/codex-hook.mjs';
const approvedReceiptHookBlob = '3a14f3764f1c0bb7fd35502c4b4cba368b54e197';
export function integratedCli(base) {
  if (!base.includes("import { verifyMaintenance, publishMaintenance } from './maintenance.mjs';\n") || !base.includes("  else if (command === 'maintenance-verify')")) throw Error('Unexpected base CLI: inspect integration manually.');
  return base.replace("import { verifyMaintenance, publishMaintenance } from './maintenance.mjs';\n", "import { verifyMaintenance, publishMaintenance } from './maintenance.mjs';\n" + integrationImport)
    .replace("  else if (command === 'maintenance-verify')", integrationCommands + "  else if (command === 'maintenance-verify')");
}
export function chatdevScope(root) {
  const { plan } = load(root);
  const branch = git(root, ['branch', '--show-current']);
  if (branch !== 'codex/chatdev-integration') throw Error('Use the dedicated codex/chatdev-integration branch.');
  if (plan.remote !== 'origin' || plan.branch !== 'main') throw Error('Unexpected plan branch or remote; no gate override permitted.');
  const base = git(root, ['rev-parse', 'origin/main']);
  git(root, ['merge-base', '--is-ancestor', base, 'HEAD']);
  // diff-tree suppresses merge diffs by default. Keep this dedicated tooling
  // history linear so merge-only product changes cannot evade the path audit.
  if (git(root, ['rev-list', '--merges', base + '..HEAD'])) throw Error('Merge commits are not permitted in the ChatDev tooling history.');
  if (git(root, ['rev-parse', '--is-inside-work-tree']) !== 'true') throw Error('A Git worktree is required.');
  const merge = path.resolve(root, git(root, ['rev-parse', '--git-path', 'MERGE_HEAD']));
  if (fs.existsSync(merge)) throw Error('Finish or resolve the pending merge outside this tooling gate.');
  const allowed = file => /^(?:tools\/chatdev\/|harness\/chatdev\/)/.test(file)
    || /^tools\/test\/chatdev(?:-[a-z0-9-]+)?\.test\.mjs$/.test(file)
    || ['docs/chatdev-integration.md','.cursor/rules/chatdev.mdc','.github/workflows/chatdev.yml','AGENTS.md','harness/README.md','tools/harness/chatdev-maintenance.mjs','tools/harness/series-maintenance.mjs','tools/harness/cli.mjs',receiptHook].includes(file);
  const changed = git(root, ['diff', '--name-only', '-z', base, '--']).split('\0').filter(Boolean);
  const untracked = git(root, ['ls-files', '--others', '--exclude-standard', '-z']).split('\0').filter(Boolean);
  const historical = [];
  for (const commit of git(root, ['rev-list', base + '..HEAD']).split('\n').filter(Boolean)) {
    historical.push(...git(root, ['diff-tree', '--no-commit-id', '--name-only', '-r', '-z', commit]).split('\0').filter(Boolean));
  }
  const forbidden = [...changed, ...untracked, ...historical].filter(file => !allowed(file));
  if (forbidden.length) throw Error('Outside ChatDev tooling scope: ' + forbidden.join(', '));
  const baselineHook = git(root, ['ls-tree', base, '--', receiptHook]);
  const baselineMatch = /^(100644|100755) blob ([a-f0-9]{40})\t/.exec(baselineHook);
  if (!baselineMatch) throw Error('Outside ChatDev tooling scope: receipt hook baseline must remain a regular tracked file.');
  const [mode, baselineBlob] = baselineMatch.slice(1);
  for (const commit of git(root, ['rev-list', base + '..HEAD']).split('\n').filter(Boolean)) {
    const entry = git(root, ['ls-tree', commit, '--', receiptHook]);
    if (![`${mode} blob ${baselineBlob}\t${receiptHook}`, `${mode} blob ${approvedReceiptHookBlob}\t${receiptHook}`].includes(entry)) throw Error('Outside ChatDev tooling scope: unapproved historical receipt hook blob or mode.');
  }
  for (const file of [...changed, ...untracked]) {
    const absolute = path.join(root, file);
    if (!fs.existsSync(absolute) || !fs.lstatSync(absolute).isFile() || fs.lstatSync(absolute).isSymbolicLink()) throw Error('Tooling paths must remain regular files: ' + file);
  }
  const hookPath = path.join(root, receiptHook);
  if (!fs.existsSync(hookPath) || !fs.lstatSync(hookPath).isFile() || fs.lstatSync(hookPath).isSymbolicLink()) throw Error('Outside ChatDev tooling scope: receipt hook must remain a regular file.');
  const currentHookBlob = git(root, ['hash-object', '--', receiptHook]);
  const actualMode = fs.statSync(hookPath).mode & 0o111 ? '100755' : '100644';
  if (![baselineBlob, approvedReceiptHookBlob].includes(currentHookBlob) || actualMode !== mode) throw Error('Outside ChatDev tooling scope: only the exact approved receipt hook repair is permitted.');
  const source = file => git(root, ['show', `${base}:${file}`]);
  // git() trims trailing newlines; normalize only that framing for exact integration comparison.
  const actual = file => fs.readFileSync(path.join(root, file), 'utf8').trimEnd();
  for (const [file, expected] of [
    ['AGENTS.md', source('AGENTS.md') + '\n' + agentsAppend],
    ['harness/README.md', source('harness/README.md') + '\n' + readmeAppend],
    ['tools/harness/cli.mjs', integratedCli(source('tools/harness/cli.mjs') + '\n')],
  ]) {
    const unchanged = actual(file) === source(file);
    if (!unchanged && actual(file) !== expected.trimEnd()) throw Error('Only the exact approved append/integration is permitted: ' + file);
  }
  return { branch, base, plan };
}
function currentProof(root) {
  const scope = chatdevScope(root);
  assertStaged(root);
  const file = localPath(root, 'chatdev-verification.json');
  if (!fs.existsSync(file)) throw Error('Run chatdev-verify first.');
  const proof = JSON.parse(fs.readFileSync(file, 'utf8'));
  if (proof.branch !== scope.branch || proof.base !== scope.base || proof.digest !== digest(root) || proof.indexDigest !== indexDigest(root)) throw Error('ChatDev proof is stale.');
  return { ...scope, proof };
}
export function verifyChatdev(root) {
  const { branch, base } = chatdevScope(root);
  assertStaged(root);
  const receipt = localPath(root, 'chatdev-verification.json');
  fs.rmSync(receipt, { force: true });
  const before = digest(root), index = indexDigest(root);
  const checks = [
    ['node', '--test', 'tools/test/harness.test.mjs', 'tools/test/chatdev.test.mjs', 'tools/test/chatdev-maintenance.test.mjs', 'tools/test/chatdev-hook-receipts.test.mjs', 'tools/test/chatdev-pr.test.mjs', 'tools/test/chatdev-manager.test.mjs', 'tools/test/chatdev-series.test.mjs'],
    ['node', 'tools/harness/static-check.mjs'],
  ];
  for (const argv of checks) {
    const result = spawnSync(argv[0], argv.slice(1), { cwd: root, stdio: 'inherit', timeout: 180_000 });
    if (result.status !== 0) throw Error('ChatDev check failed; no verified publication.');
  }
  chatdevScope(root);
  if (digest(root) !== before || indexDigest(root) !== index) throw Error('Checks changed the reviewed snapshot.');
  writeJson(receipt, { branch, base, digest: before, indexDigest: index, checkedAt: new Date().toISOString(), purpose: 'chatdev-tooling-only' });
  console.log('PASS: ChatDev-only snapshot verified; feature progress and existing gates unchanged.');
}
export function publishChatdev(root, message) {
  if (localState(root).mode !== 'implementing') throw Error('Resume implementation before publishing ChatDev tooling.');
  const { branch, base, plan, proof } = currentProof(root);
  if (proof.purpose !== 'chatdev-tooling-only') throw Error('Incorrect proof purpose.');
  if (!message?.trim()) throw Error('A ChatDev commit message is required.');
  // Revalidate the base against the actual remote before any local commit.
  const remoteBase = git(root, ['ls-remote', '--exit-code', '--heads', plan.remote, 'refs/heads/main']).split(/\s+/)[0];
  if (remoteBase !== base) throw Error('Published main moved; refresh and review the tooling base before publishing.');
  if (git(root, ['diff', '--cached', '--name-only'])) git(root, ['commit', '-m', message]);
  try {
    git(root, ['push', '--set-upstream', plan.remote, `HEAD:refs/heads/${branch}`]);
    const head = git(root, ['rev-parse', 'HEAD']);
    const remote = git(root, ['ls-remote', '--exit-code', '--heads', plan.remote, `refs/heads/${branch}`]).split(/\s+/)[0];
    if (head !== remote) throw Error('Remote publication differs.');
    writeJson(localPath(root, 'chatdev-publication.json'), { branch, commit: head, base, checkedAt: proof.checkedAt });
    setMode(root, 'discussion', 'ChatDev tooling committed and confirmed pushed; no feature accepted or advanced.');
    console.log('Confirmed ChatDev tooling push: ' + head);
  } catch {
    setMode(root, 'blocked', 'ChatDev commit exists but remote publication could not be confirmed.');
    throw Error('ChatDev push or confirmation failed. Resume and retry the same checkpoint.');
  }
}
