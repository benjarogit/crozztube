import fs from 'node:fs';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
import { git, load, digest, indexDigest, assertStaged, localPath, localState, writeJson, setMode } from './workflow.mjs';

export function maintenanceScope(root) {
  const { plan } = load(root);
  const branch = git(root, ['branch', '--show-current']);
  if (branch !== 'codex/impeccable-maintenance') throw Error('Use the dedicated codex/impeccable-maintenance branch.');
  const remoteBase = git(root, ['rev-parse', `${plan.remote}/${plan.branch}`]);
  const mergeFile = path.resolve(root, git(root, ['rev-parse', '--git-path', 'MERGE_HEAD']));
  let base;
  if (fs.existsSync(mergeFile)) {
    const incoming = fs.readFileSync(mergeFile, 'utf8').trim();
    if (incoming !== remoteBase) throw Error('Maintenance may only integrate the published plan branch.');
    // Compare the pending merge with its approved incoming feature snapshot.
    // Otherwise unchanged incoming feature files would look like new tooling
    // edits. The proof retains this same base after the merge is committed.
    base = incoming;
  } else {
    base = git(root, ['merge-base', 'HEAD', remoteBase]);
  }
  const allowed = file => /^(?:\.agents\/skills\/impeccable\/|tools\/maintenance\/|tools\/test\/impeccable-maintenance\.test\.mjs$)/.test(file)
    || ['AGENTS.md', 'DESIGN.md', 'harness/README.md', 'harness/impeccable.lock.json', 'tools/harness/maintenance.mjs', 'tools/harness/cli.mjs', 'tools/harness/workflow.mjs', 'tools/test/harness.test.mjs'].includes(file);
  const changes = git(root, ['diff', '--name-only', base, '--']).split('\n').filter(Boolean);
  const untracked = git(root, ['ls-files', '--others', '--exclude-standard']).split('\n').filter(Boolean);
  const forbidden = [...changes, ...untracked].filter(file => !allowed(file));
  if (forbidden.length) throw Error(`Outside Impeccable maintenance scope: ${forbidden.join(', ')}`);
  return { branch, base, plan };
}

export function verifyMaintenance(root, checks = [
  ['node', '--test', 'tools/test/harness.test.mjs', 'tools/test/impeccable-maintenance.test.mjs'],
  ['node', 'tools/maintenance/impeccable.mjs', 'verify'],
  ['node', 'tools/harness/static-check.mjs'],
]) {
  const { branch, base } = maintenanceScope(root);
  assertStaged(root);
  fs.rmSync(localPath(root, 'maintenance-verification.json'), { force: true });
  const before = digest(root), index = indexDigest(root);
  for (const argv of checks) {
    const result = spawnSync(argv[0], argv.slice(1), { cwd: root, stdio: 'inherit', timeout: 180_000 });
    if (result.status !== 0) throw Error('Maintenance check failed; do not publish as tested.');
  }
  maintenanceScope(root);
  if (before !== digest(root) || index !== indexDigest(root)) throw Error('Maintenance verification changed the checked snapshot.');
  writeJson(localPath(root, 'maintenance-verification.json'), { branch, base, digest: before, indexDigest: index, checkedAt: new Date().toISOString() });
  console.log('PASS: isolated maintenance scope and checked snapshot. Feature progress is unchanged.');
}

export function publishMaintenance(root, message) {
  const { branch, base, plan } = maintenanceScope(root);
  if (localState(root).mode !== 'implementing') throw Error('Resume implementation before publishing maintenance.');
  assertStaged(root);
  const file = localPath(root, 'maintenance-verification.json');
  if (!fs.existsSync(file)) throw Error('Run maintenance-verify first.');
  const proof = JSON.parse(fs.readFileSync(file, 'utf8'));
  if (proof.branch !== branch || proof.base !== base || proof.digest !== digest(root) || proof.indexDigest !== indexDigest(root)) throw Error('Maintenance proof is stale.');
  if (!message?.trim()) throw Error('A maintenance commit message is required.');
  if (git(root, ['diff', '--cached', '--name-only'])) git(root, ['commit', '-m', message]);
  try {
    git(root, ['push', '--set-upstream', plan.remote, `HEAD:refs/heads/${branch}`]);
    const head = git(root, ['rev-parse', 'HEAD']);
    const remote = git(root, ['ls-remote', '--exit-code', '--heads', plan.remote, `refs/heads/${branch}`]).split(/\s+/)[0];
    if (head !== remote) throw Error('Remote publication differs.');
    writeJson(localPath(root, 'maintenance-publication.json'), { branch, commit: head, checkedAt: proof.checkedAt });
    setMode(root, 'discussion', 'Impeccable maintenance committed and confirmed pushed; no feature step completed.');
    console.log(`Confirmed maintenance push: ${head}`);
  } catch {
    setMode(root, 'blocked', 'Maintenance commit exists, but its remote publication could not be confirmed.');
    throw Error('Maintenance push or confirmation failed.');
  }
}
