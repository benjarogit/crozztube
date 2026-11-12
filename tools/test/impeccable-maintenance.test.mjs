import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { createHash } from 'node:crypto';
import { newer, latest, parseChecksums, mergeContent, installed, check, apply, prefix, blobHash, verifyAsset } from '../maintenance/impeccable.mjs';
import { maintenanceScope, verifyMaintenance, publishMaintenance } from '../harness/maintenance.mjs';
import { git, setMode, load, localState } from '../harness/workflow.mjs';

const source = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const feature = 'codex/viewer-community-features';
const maintenance = 'codex/impeccable-maintenance';
function fixture(t) {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'crozztube-maintenance-test-'));
  const root = path.join(dir, 'work'), remote = path.join(dir, 'remote.git');
  execFileSync('git', ['init', '--bare', '--quiet', remote]);
  execFileSync('git', ['init', '--quiet', '-b', feature, root]);
  for (const [key, value] of [['user.name', 'Maintenance Fixture'], ['user.email', 'maintenance@example.invalid'], ['core.hooksPath', path.join(dir, 'no-hooks')]]) git(root, ['config', key, value]);
  fs.mkdirSync(path.join(root, 'harness'));
  fs.writeFileSync(path.join(root, 'harness/plan.json'), JSON.stringify({ schemaVersion: 1, branch: feature, remote: 'origin', steps: [{ id: 1 }, { id: 2 }] }));
  fs.writeFileSync(path.join(root, 'harness/progress.json'), JSON.stringify({ schemaVersion: 1, activeStep: 2, steps: [{ id: 1, status: 'accepted' }, { id: 2, status: 'active' }] }));
  fs.writeFileSync(path.join(root, 'DESIGN.md'), 'Design baseline\n');
  fs.writeFileSync(path.join(root, 'app.txt'), 'Feature baseline\n');
  git(root, ['add', '.']); git(root, ['commit', '-qm', 'Baseline']);
  git(root, ['remote', 'add', 'origin', remote]); git(root, ['push', '-qu', 'origin', feature]);
  git(root, ['switch', '-qc', maintenance]);
  setMode(root, 'implementing');
  t.after(() => fs.rmSync(dir, { recursive: true, force: true }));
  return { root, remote };
}
const passing = [[process.execPath, '-e', 'process.exit(0)']];
const nextPatch = version => { const parts = version.split('.').map(Number); parts[2]++; return parts.join('.'); };
function skillFixture(t) {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'crozztube-lock-test-'));
  t.after(() => fs.rmSync(dir, { recursive: true, force: true }));
  execFileSync('git', ['init', '--quiet', dir]);
  fs.cpSync(path.join(source, '.agents/skills/impeccable'), path.join(dir, '.agents/skills/impeccable'), { recursive: true });
  fs.mkdirSync(path.join(dir, 'harness'));
  fs.copyFileSync(path.join(source, 'harness/impeccable.lock.json'), path.join(dir, 'harness/impeccable.lock.json'));
  fs.copyFileSync(path.join(source, 'DESIGN.md'), path.join(dir, 'DESIGN.md'));
  execFileSync('git', ['add', '.'], { cwd: dir });
  return dir;
}

test('corrupted binary bytes are rejected before any engine execution', () => {
  const bytes = Buffer.from('verified fixture');
  const checksum = createHash('sha256').update(bytes).digest('hex');
  verifyAsset(bytes, checksum);
  assert.throws(() => verifyAsset(Buffer.from('tampered'), checksum), /refusing execution/);
});

test('an engine release without a matching installer remains pending without writes', async t => {
  const root = skillFixture(t);
  const before = fs.readFileSync(path.join(root, 'harness/impeccable.lock.json'));
  const lock = installed(root), candidate = nextPatch(lock.engine.version);
  const releases = [{ tag_name: `skill-v${lock.skill.version}` }, { tag_name: `cli-v${lock.cli.referenceVersion}` },
    { tag_name: `engine-v${lock.engine.version}` }, { tag_name: `engine-v${candidate}` }];
  const report = await check(root, { releases, resolve: async () => 'a'.repeat(40), read: async (_, file) => fs.readFileSync(path.join(root, file)) });
  assert.equal(report.updates.length, 0);
  assert.equal(report.pending.length, 1);
  assert.equal(report.pending[0].version, candidate);
  assert.match(report.pending[0].reason, /still pins/);
  await apply(root, report);
  assert.deepEqual(fs.readFileSync(path.join(root, 'harness/impeccable.lock.json')), before);
});

test('a consistent engine update applies verified objects and retains local-only files', async t => {
  const root = skillFixture(t);
  const lock = installed(root), candidate = nextPatch(lock.engine.version);
  const commit = 'a'.repeat(40);
  const names = ['scripts/VERSION', 'scripts/engine-checksums.txt', 'scripts/impeccable', 'scripts/impeccable.cmd'];
  const incoming = Object.fromEntries(names.map(file => [prefix + file, fs.readFileSync(path.join(root, prefix + file))]));
  incoming[prefix + names[0]] = Buffer.from(candidate + '\n');
  incoming[prefix + names[1]] = Buffer.from(incoming[prefix + names[1]].toString().replaceAll(lock.engine.version, candidate));
  const tree = Object.entries(incoming).map(([file, bytes]) => ({ path: file, sha: file.endsWith('impeccable.cmd') ? lock.files[file].upstreamBlob : blobHash(bytes), mode: '100644', type: 'blob' }));
  const checksums = parseChecksums(incoming[prefix + names[1]].toString(), candidate);
  const original = Object.fromEntries(Object.keys(incoming).map(file => [lock.files[file].upstreamBlob, fs.readFileSync(path.join(root, file))]));
  const local = fs.readFileSync(path.join(root, prefix + 'SOURCE.md'));
  await apply(root, { updates: [{ kind: 'engine', version: candidate, engine: candidate, commit, checksums }], pending: [] }, { tree: async () => tree, read: async (_, file) => incoming[file], blob: async sha => original[sha] });
  assert.equal(installed(root).engine.version, candidate);
  assert.notEqual(installed(root).engine.version, lock.engine.version);
  assert.deepEqual(fs.readFileSync(path.join(root, prefix + 'SOURCE.md')), local);
});

test('stable component versions are independent; prereleases and drafts are excluded', () => {
  const releases = [{ tag_name: 'engine-v0.1.19' }, { tag_name: 'skill-v4.8.0' }, { tag_name: 'engine-v0.2.0', prerelease: true }, { tag_name: 'engine-v0.3.0', draft: true }];
  assert.equal(latest(releases, 'engine').tag_name, 'engine-v0.1.19');
  assert.equal(latest(releases, 'skill').tag_name, 'skill-v4.8.0');
  assert.equal(newer('0.1.19', '0.1.18'), true);
  assert.equal(newer('4.8.0', '4.8.0'), false);
  assert.throws(() => newer('4.9.0-beta', '4.8.0'), /stable/);
});

test('every supported binary needs one unambiguous pinned checksum', () => {
  const names = ['darwin-arm64', 'darwin-x64', 'linux-arm64', 'linux-x64', 'windows-x64.exe'];
  const lines = names.map(name => `0.1.19 impeccable-${name} ${'a'.repeat(64)}`).join('\n');
  assert.equal(Object.keys(parseChecksums(lines, '0.1.19')).length, 5);
  assert.throws(() => parseChecksums(lines, '0.1.18'), /Missing/);
  assert.throws(() => parseChecksums(lines + '\n' + lines.split('\n')[0], '0.1.19'), /ambiguous/);
  assert.throws(() => parseChecksums(lines.replace('a'.repeat(64), 'invalid'), '0.1.19'), /checksum/);
});

test('local customizations survive upstream changes; conflicts fail closed', () => {
  const base = Buffer.from('first\nsecond\nthird\n');
  const local = Buffer.from('local\nsecond\nthird\n');
  assert.deepEqual(mergeContent(local, base, base), local);
  assert.equal(mergeContent(local, base, Buffer.from('first\nsecond\nupstream\n')).toString(), 'local\nsecond\nupstream\n');
  assert.throws(() => mergeContent(local, base, Buffer.from('conflict\nsecond\nthird\n')), /conflicts/);
});

test('recorded vendored files are complete and an unrecorded customization is rejected', t => {
  assert.equal(installed(source).files[`${'.agents/skills/impeccable/'}SOURCE.md`].localOnly, true);
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'crozztube-lock-test-'));
  t.after(() => fs.rmSync(dir, { recursive: true, force: true }));
  execFileSync('git', ['init', '--quiet', dir]);
  fs.cpSync(path.join(source, '.agents/skills/impeccable'), path.join(dir, '.agents/skills/impeccable'), { recursive: true });
  fs.mkdirSync(path.join(dir, 'harness'));
  fs.copyFileSync(path.join(source, 'harness/impeccable.lock.json'), path.join(dir, 'harness/impeccable.lock.json'));
  execFileSync('git', ['add', '.'], { cwd: dir });
  fs.appendFileSync(path.join(dir, '.agents/skills/impeccable/SKILL.md'), 'unrecorded change\n');
  assert.throws(() => installed(dir), /Unrecorded local customization/);
});

test('feature changes, progress changes, untracked escape files and wrong branches are rejected', t => {
  const { root } = fixture(t);
  maintenanceScope(root);
  for (const file of ['app.txt', 'harness/progress.json', 'another-feature.txt', '.codex/hooks.json']) {
    const target = path.join(root, file);
    fs.mkdirSync(path.dirname(target), { recursive: true });
    const original = fs.existsSync(target) ? fs.readFileSync(target) : null;
    fs.writeFileSync(target, file.endsWith('progress.json') ? JSON.stringify(load(root).progress, null, 2) : 'unapproved change');
    assert.throws(() => maintenanceScope(root), /Outside Impeccable maintenance scope/);
    if (original) fs.writeFileSync(target, original); else fs.unlinkSync(target);
  }
  git(root, ['switch', '-q', feature]);
  assert.throws(() => maintenanceScope(root), /dedicated/);
});

test('maintenance checks bind proof to staged files, preserve the active step and verify push', t => {
  const { root } = fixture(t);
  fs.appendFileSync(path.join(root, 'DESIGN.md'), 'Tooling provenance\n');
  assert.throws(() => verifyMaintenance(root, passing), /stage/);
  git(root, ['add', 'DESIGN.md']);
  verifyMaintenance(root, passing);
  const progress = fs.readFileSync(path.join(root, 'harness/progress.json'));
  publishMaintenance(root, 'test: checked maintenance');
  assert.deepEqual(fs.readFileSync(path.join(root, 'harness/progress.json')), progress);
  assert.equal(load(root).step.id, 2);
  assert.equal(load(root).record.status, 'active');
  assert.equal(localState(root).mode, 'discussion');
  assert.equal(git(root, ['rev-parse', 'HEAD']), git(root, ['ls-remote', 'origin', `refs/heads/${maintenance}`]).split(/\s/)[0]);
});

test('failed checks and stale maintenance proof prevent publication', t => {
  const { root } = fixture(t);
  verifyMaintenance(root, passing);
  assert.throws(() => verifyMaintenance(root, [[process.execPath, '-e', 'process.exit(3)']]), /check failed/);
  assert.throws(() => publishMaintenance(root, 'not verified'), /maintenance-verify/);
  verifyMaintenance(root, passing);
  fs.appendFileSync(path.join(root, 'DESIGN.md'), 'changed after verification\n');
  git(root, ['add', 'DESIGN.md']);
  assert.throws(() => publishMaintenance(root, 'stale'), /stale/);
});

test('failed remote publication is reported as blocked without feature advancement', t => {
  const { root } = fixture(t);
  verifyMaintenance(root, passing);
  git(root, ['remote', 'set-url', 'origin', path.join(root, 'missing.git')]);
  assert.throws(() => publishMaintenance(root, 'test: unavailable push'), /confirmation failed/);
  assert.equal(localState(root).mode, 'blocked');
  assert.equal(load(root).step.id, 2);
});

test('a pending merge of the published base can be checked before committing without treating incoming features as tooling edits', t => {
  const { root } = fixture(t);
  git(root, ['switch', '-q', feature]);
  fs.writeFileSync(path.join(root, 'app.txt'), 'Published feature update\n');
  git(root, ['add', 'app.txt']); git(root, ['commit', '-qm', 'Published feature']);
  git(root, ['push', '-q', 'origin', feature]);
  const incoming = git(root, ['rev-parse', 'HEAD']);
  git(root, ['switch', '-q', maintenance]);
  fs.appendFileSync(path.join(root, 'DESIGN.md'), 'Existing maintenance change\n');
  git(root, ['add', 'DESIGN.md']); git(root, ['commit', '-qm', 'Existing tooling']);
  git(root, ['merge', '--no-commit', '--no-ff', `origin/${feature}`]);
  assert.equal(maintenanceScope(root).base, incoming);
  fs.writeFileSync(path.join(root, 'app.txt'), 'Unapproved feature edit during merge\n');
  assert.throws(() => maintenanceScope(root), /Outside Impeccable maintenance scope/);
  fs.writeFileSync(path.join(root, 'app.txt'), 'Published feature update\n');
  git(root, ['add', 'app.txt']);
  verifyMaintenance(root, passing);
  publishMaintenance(root, 'test: verified base integration');
  assert.equal(maintenanceScope(root).base, incoming);
  assert.equal(git(root, ['show', 'HEAD:app.txt']), 'Published feature update');
  assert.equal(load(root).record.status, 'active');
});

test('a pending merge from an unapproved source cannot use the maintenance publication gate', t => {
  const { root } = fixture(t);
  git(root, ['switch', '-qc', 'unapproved']);
  fs.writeFileSync(path.join(root, 'app.txt'), 'Other branch\n');
  git(root, ['add', 'app.txt']); git(root, ['commit', '-qm', 'Unapproved source']);
  git(root, ['switch', '-q', maintenance]);
  fs.appendFileSync(path.join(root, 'DESIGN.md'), 'Existing maintenance change\n');
  git(root, ['add', 'DESIGN.md']); git(root, ['commit', '-qm', 'Existing tooling']);
  git(root, ['merge', '--no-commit', '--no-ff', 'unapproved']);
  assert.throws(() => maintenanceScope(root), /only integrate the published/);
});
