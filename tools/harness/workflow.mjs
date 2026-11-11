import fs from 'node:fs';
import path from 'node:path';
import { createHash } from 'node:crypto';
import { execFileSync, spawnSync } from 'node:child_process';

export function git(root, args) {
  return execFileSync('git', args, { cwd: root, encoding: 'utf8', maxBuffer: 20_000_000, timeout: 45_000, stdio: ['pipe', 'pipe', 'pipe'] }).trimEnd();
}
export const repository = () => git(process.cwd(), ['rev-parse', '--show-toplevel']);
export const readJson = file => JSON.parse(fs.readFileSync(file, 'utf8'));
export function writeJson(file, value) {
  fs.mkdirSync(path.dirname(file), { recursive: true, mode: 0o700 });
  const temporary = `${file}.${process.pid}.tmp`;
  fs.writeFileSync(temporary, JSON.stringify(value, null, 2) + '\n', { mode: 0o600 });
  fs.renameSync(temporary, file);
}
export function localPath(root, name) {
  return path.resolve(root, git(root, ['rev-parse', '--git-path', `crozztube-harness/${name}`]));
}
export function localState(root) {
  const file = localPath(root, 'state.json');
  return fs.existsSync(file) ? readJson(file) : { mode: 'discussion', reason: 'No implementation turn started.' };
}
export function setMode(root, mode, reason = '') {
  if (!['implementing', 'discussion', 'paused', 'blocked'].includes(mode)) throw Error('Unknown harness mode.');
  if (mode !== 'implementing' && !reason.trim()) throw Error('A non-implementation mode needs a reason.');
  writeJson(localPath(root, 'state.json'), { ...localState(root), mode, reason, updatedAt: new Date().toISOString() });
}
export function load(root) {
  const plan = readJson(path.join(root, 'harness/plan.json'));
  const progress = readJson(path.join(root, 'harness/progress.json'));
  if (plan.schemaVersion !== 1 || progress.schemaVersion !== 1) throw Error('Unsupported harness schema.');
  if (!Array.isArray(plan.steps) || !plan.steps.length || !Array.isArray(progress.steps) || progress.steps.length !== plan.steps.length) throw Error('Incomplete step inventory.');
  for (const [index, step] of plan.steps.entries()) {
    const record = progress.steps[index];
    if (step.id !== index + 1 || record?.id !== step.id || !['pending', 'active', 'accepted'].includes(record.status)) throw Error('Invalid step sequence.');
    if (step.id < progress.activeStep && record.status !== 'accepted') throw Error('An earlier step is incomplete.');
    if (step.id > progress.activeStep && record.status !== 'pending') throw Error('A later step was started out of sequence.');
  }
  const step = plan.steps.find(item => item.id === progress.activeStep);
  const record = progress.steps.find(item => item.id === progress.activeStep);
  if (!step || !record || record.status === 'pending') throw Error('Exactly one current step is required.');
  return { plan, progress, step, record };
}
export function assertBranch(root, plan) {
  if (git(root, ['branch', '--show-current']) !== plan.branch) throw Error(`Use the approved branch ${plan.branch}.`);
}
export function files(root) {
  return [...new Set(git(root, ['ls-files', '-co', '--exclude-standard', '-z']).split('\0').filter(Boolean))].sort();
}
export function digest(root) {
  const hash = createHash('sha256');
  for (const file of files(root)) {
    // The publication declaration is written after verification. Every source,
    // check definition, hook and other documentation file is still included.
    if (file === 'harness/progress.json') continue;
    hash.update(file + '\0');
    const absolute = path.join(root, file);
    if (!fs.existsSync(absolute)) { hash.update('deleted\0'); continue; }
    const stat = fs.lstatSync(absolute);
    hash.update(String(stat.mode) + '\0');
    hash.update(stat.isSymbolicLink() ? fs.readlinkSync(absolute) : fs.readFileSync(absolute));
  }
  return hash.digest('hex');
}
export function assertStaged(root) {
  if (git(root, ['diff', '--name-only'])) throw Error('Review and stage all current changes before publication.');
  if (git(root, ['ls-files', '--others', '--exclude-standard'])) throw Error('Untracked source files must be reviewed and staged or deliberately ignored.');
}
export const clean = root => !git(root, ['status', '--porcelain=v1']);
export function remoteHead(root, plan) {
  const line = git(root, ['ls-remote', '--exit-code', '--heads', plan.remote, `refs/heads/${plan.branch}`]);
  const id = line.split(/\s+/)[0];
  if (!/^[a-f0-9]{40,64}$/.test(id)) throw Error('Remote branch could not be verified.');
  return id;
}
export function published(root, { plan, record }, fresh = false) {
  const file = localPath(root, 'publication.json');
  const receipt = fs.existsSync(file) ? readJson(file) : null;
  const head = git(root, ['rev-parse', 'HEAD']);
  if (record.status !== 'accepted' || !clean(root)) return false;
  if (fresh) return remoteHead(root, plan) === head;
  return receipt?.commit === head && receipt.branch === plan.branch && receipt.step === record.id;
}
export function assertVerification(root, context = load(root), { complete = false } = {}) {
  const file = localPath(root, 'verification.json');
  if (!fs.existsSync(file)) throw Error('Run verify before committing or accepting a step.');
  const proof = readJson(file);
  if (proof.step !== context.step.id || proof.digest !== digest(root) || proof.indexDigest !== indexDigest(root)) throw Error('Verification is stale for the current files or index. Run verify again.');
  if (complete && proof.purpose !== 'completion') throw Error('Checkpoint checks cannot complete a step. Run the full verification.');
  const required = proof.purpose === 'checkpoint' && !complete ? context.step.checkpointChecks ?? context.step.checks : context.step.checks;
  if (!required?.length || required.some(id => !proof.checks?.some(check => check.id === id && check.passed))) throw Error('Required checks have not all passed.');
  return proof;
}
export function indexDigest(root) {
  const entries = git(root, ['ls-files', '--stage', '-z']).split('\0').filter(Boolean).filter(entry => !entry.endsWith('\tharness/progress.json'));
  return createHash('sha256').update(entries.join('\0')).digest('hex');
}
export function verify(root, { checkpoint = false } = {}) {
  const context = load(root);
  assertBranch(root, context.plan);
  if (context.record.status !== 'active') throw Error('Only an active step can be verified.');
  const required = checkpoint ? context.step.checkpointChecks ?? context.step.checks : context.step.checks;
  if (!required?.length) throw Error('Define meaningful required checks before verification.');
  fs.rmSync(localPath(root, 'verification.json'), { force: true });
  const sourceBefore = digest(root), indexBefore = indexDigest(root);
  const checks = [];
  for (const id of required) {
    const check = context.plan.checks[id];
    if (!check?.argv?.length || !check.argv.every(value => typeof value === 'string')) throw Error(`Required check ${id} has not been implemented.`);
    console.log(`Checking ${id}…`);
    const result = spawnSync(check.argv[0], check.argv.slice(1), { cwd: root, stdio: 'inherit', timeout: check.timeoutMs || 180_000 });
    if (result.status !== 0) {
      setMode(root, 'blocked', `Check ${id} failed.`);
      throw Error(`Check ${id} failed; this step remains open.`);
    }
    checks.push({ id, passed: true });
  }
  if (digest(root) !== sourceBefore || indexDigest(root) !== indexBefore) {
    setMode(root, 'blocked', 'A check changed source files or the staged snapshot.');
    throw Error('Checks changed the source or index; review the changes and verify again.');
  }
  const proof = { step: context.step.id, purpose: checkpoint ? 'checkpoint' : 'completion', digest: sourceBefore, indexDigest: indexBefore, checkedAt: new Date().toISOString(), checks };
  writeJson(localPath(root, 'verification.json'), proof);
  console.log(`Verified step ${proof.step} at source digest ${proof.digest.slice(0, 12)}.`);
  return proof;
}
export function begin(root, id) {
  const context = load(root);
  assertBranch(root, context.plan);
  if (id !== context.step.id + 1 || !context.plan.steps.some(step => step.id === id)) throw Error('Only the next sequential step can begin.');
  if (!published(root, context, true)) throw Error('Current step needs accepted checks, a clean tree and a confirmed remote push.');
  context.progress.activeStep = id;
  context.progress.steps[id - 1].status = 'active';
  writeJson(path.join(root, 'harness/progress.json'), context.progress);
  setMode(root, 'implementing');
}
export function hookDigest(root) {
  const hash = createHash('sha256');
  for (const file of ['.codex/hooks.json', '.cursor/hooks.json', 'tools/harness/codex-hook.mjs', 'tools/harness/workflow.mjs']) {
    const absolute = path.join(root, file);
    if (fs.existsSync(absolute)) hash.update(file + '\0').update(fs.readFileSync(absolute));
  }

  return hash.digest('hex');
}
export function runtimeEvidence(root) {
  const file = localPath(root, 'hook-receipts.jsonl');
  if (!fs.existsSync(file)) return false;
  const expected = hookDigest(root);

  return fs.readFileSync(file, 'utf8').trim().split('\n').filter(Boolean).map(line => JSON.parse(line))
    .some(item => ['PreToolUse', 'beforeShellExecution'].includes(item.event)
      && item.decision === 'probe-blocked' && item.hookDigest === expected
      && (item.session || item.event === 'beforeShellExecution'));
}
export function publish(root, message, { complete = false, note = '' } = {}) {
  const context = load(root);
  assertBranch(root, context.plan);
  if (localState(root).mode !== 'implementing') throw Error('Resume the implementation explicitly before publishing.');
  const proof = assertVerification(root, context, { complete });
  assertStaged(root);
  if (!message?.trim()) throw Error('A descriptive commit message is required.');
  if (complete) {
    if (!note.trim()) throw Error('Record concrete acceptance evidence.');
    if (!runtimeEvidence(root)) throw Error('A real trusted project hook must block the runtime probe before completion.');
    context.record.status = 'accepted';
    context.record.acceptance = { note, checkedAt: proof.checkedAt, sourceDigest: proof.digest, checks: proof.checks.map(item => item.id) };
    writeJson(path.join(root, 'harness/progress.json'), context.progress);
    git(root, ['add', '--', 'harness/progress.json']);
    // Acceptance notes are new staged content too. Scan them before publishing,
    // rather than exempting this declaration from the secret check.
    const safety = context.plan.checks['staged-safety'];
    if (safety) {
      const result = spawnSync(safety.argv[0], safety.argv.slice(1), { cwd: root, stdio: 'inherit', timeout: safety.timeoutMs || 180_000 });
      if (result.status !== 0) throw Error('Publication safety check failed; acceptance remains unpublished.');
    }
  }
  if (git(root, ['diff', '--cached', '--name-only'])) git(root, ['commit', '-m', message]);
  const head = git(root, ['rev-parse', 'HEAD']);
  try {
    git(root, ['push', '--set-upstream', context.plan.remote, `HEAD:refs/heads/${context.plan.branch}`]);
    if (remoteHead(root, context.plan) !== head) throw Error('Remote HEAD differs after push.');
    writeJson(localPath(root, 'publication.json'), { step: context.step.id, branch: context.plan.branch, commit: head, verifiedAt: new Date().toISOString() });
  } catch {
    setMode(root, 'blocked', 'Commit exists locally but remote publication is not confirmed. Retry publish after resolving the connection.');
    throw Error('Push or remote verification failed. No next step may begin.');
  }
  if (complete) setMode(root, 'discussion', `Step ${context.step.id} accepted and published. Next step has not started.`);
  console.log(`${complete ? 'Completed' : 'Checkpointed'} step ${context.step.id}; confirmed remote commit ${head}.`);
}
export function stopDecision(root, input) {
  const state = localState(root);
  if (state.mode !== 'implementing') return {};
  const context = load(root);
  if (published(root, context)) return {};
  // One repair continuation only. A second stop becomes an explicit blocker,
  // preserving the step without manufacturing completion or trapping the user.
  if (input.stop_hook_active || input.turn_id && state.stopTurn === input.turn_id) {
    setMode(root, 'blocked', 'The current step is not accepted and published. Report the remaining blocker; do not start another step.');
    return { systemMessage: 'Harness: current step remains open. Report the blocker honestly; no further automatic continuation.' };
  }
  writeJson(localPath(root, 'state.json'), { ...state, stopTurn: input.turn_id || input.session_id || 'unknown' });
  return { decision: 'block', reason: 'Harness: finish the current step with its required checks, acceptance, commit and verified push. If externally blocked, record mode blocked with its concrete reason. Do not start the next step.' };
}
