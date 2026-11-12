#!/usr/bin/env node
import fs from 'node:fs';
import path from 'node:path';
import os from 'node:os';
import { createHash } from 'node:crypto';
import { execFileSync, spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

export const repository = 'pbakaus/impeccable';
export const prefix = '.agents/skills/impeccable/';
const lockPath = 'harness/impeccable.lock.json';
const sha256 = bytes => createHash('sha256').update(bytes).digest('hex');
export function verifyAsset(bytes, checksum) {
  if (!/^[a-f0-9]{64}$/.test(checksum) || sha256(bytes) !== checksum) throw Error('Downloaded engine checksum mismatch; refusing execution.');
}
export const blobHash = bytes => createHash('sha1').update(`blob ${bytes.length}\0`).update(bytes).digest('hex');
export function newer(a, b) {
  if (![a, b].every(v => /^\d+\.\d+\.\d+$/.test(v))) throw Error('Only stable numeric release versions are supported.');
  const x = a.split('.').map(Number), y = b.split('.').map(Number);
  for (let i = 0; i < 3; i++) if (x[i] !== y[i]) return x[i] > y[i];
  return false;
}
export function latest(releases, kind) {
  return releases.filter(r => !r.draft && !r.prerelease && new RegExp(`^${kind}-v\\d+\\.\\d+\\.\\d+$`).test(r.tag_name))
    .sort((a, b) => newer(a.tag_name.split('-v')[1], b.tag_name.split('-v')[1]) ? -1 : 1)[0];
}
export function parseChecksums(text, version) {
  const rows = text.split(/\r?\n/).filter(line => line.trim() && !line.startsWith('#')).map(line => line.trim().split(/\s+/));
  const expected = ['impeccable-darwin-arm64', 'impeccable-darwin-x64', 'impeccable-linux-arm64', 'impeccable-linux-x64', 'impeccable-windows-x64.exe'];
  const result = {};
  for (const name of expected) {
    const matches = rows.filter(row => row[0] === version && row[1] === name);
    if (matches.length !== 1 || !/^[a-f0-9]{64}$/.test(matches[0][2])) throw Error(`Missing or ambiguous upstream checksum for ${version}/${name}.`);
    result[name] = matches[0][2];
  }
  return result;
}
export function mergeContent(local, base, upstream) {
  if (local.equals(base)) return upstream;
  if (upstream.equals(base) || local.equals(upstream)) return local;
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'crozztube-skill-merge-'));
  try {
    for (const [name, bytes] of Object.entries({ local, base, upstream })) fs.writeFileSync(path.join(dir, name), bytes);
    const result = spawnSync('git', ['merge-file', '-p', path.join(dir, 'local'), path.join(dir, 'base'), path.join(dir, 'upstream')], { maxBuffer: 4_000_000 });
    if (result.status !== 0) throw Error('Local Impeccable customization conflicts with upstream; nothing was applied.');
    return result.stdout;
  } finally { fs.rmSync(dir, { recursive: true, force: true }); }
}
export function installed(root) {
  const lock = JSON.parse(fs.readFileSync(path.join(root, lockPath), 'utf8'));
  if (lock.schemaVersion !== 1 || lock.repository !== repository) throw Error('Unknown Impeccable source lock.');
  const tracked = execFileSync('git', ['ls-files', prefix], { cwd: root, encoding: 'utf8' }).trim().split('\n').filter(Boolean);
  if (tracked.some(file => !lock.files[file])) throw Error('Unrecorded local skill files; review before updating.');
  for (const [file, record] of Object.entries(lock.files)) {
    if (!file.startsWith(prefix) || file.includes('..') || !fs.existsSync(path.join(root, file)) || fs.lstatSync(path.join(root, file)).isSymbolicLink()) throw Error('Invalid locked skill path.');
    if (sha256(fs.readFileSync(path.join(root, file))) !== record.sha256) throw Error(`Unrecorded local customization: ${file}. Preserve and review it first.`);
  }
  const engine = fs.readFileSync(path.join(root, prefix, 'scripts/VERSION'), 'utf8').trim();
  const skill = fs.readFileSync(path.join(root, prefix, 'SKILL.md'), 'utf8').match(/version:\s*(\d+\.\d+\.\d+)/)?.[1];
  if (engine !== lock.engine.version || skill !== lock.skill.version) throw Error('Installed versions differ from their source lock.');
  const sums = parseChecksums(fs.readFileSync(path.join(root, prefix, 'scripts/engine-checksums.txt'), 'utf8'), engine);
  if (JSON.stringify(sums) !== JSON.stringify(lock.engine.checksums)) throw Error('Engine checksums differ from the recorded upstream set.');
  return lock;
}
async function get(url, json = false, max = 12_000_000) {
  const response = await fetch(url, { headers: { Accept: json ? 'application/vnd.github+json' : 'application/octet-stream', 'User-Agent': 'CrozzTube-Impeccable-maintenance' }, signal: AbortSignal.timeout(45_000) });
  if (!response.ok) throw Error(`Official upstream request failed (${response.status}): ${new URL(url).pathname}`);
  let size = 0; const parts = [];
  for await (const part of response.body) { size += part.length; if (size > max) throw Error('Upstream response exceeds maintenance limit.'); parts.push(part); }
  const buffer = Buffer.concat(parts);
  return json ? JSON.parse(buffer.toString()) : buffer;
}
const api = route => get(`https://api.github.com/repos/${repository}/${route}`, true);
const raw = (commit, file) => get(`https://raw.githubusercontent.com/${repository}/${commit}/${file}`);
async function commitFor(ref) {
  const result = await api(`commits/${encodeURIComponent(ref)}`);
  if (!/^[a-f0-9]{40}$/.test(result.sha)) throw Error('Upstream commit could not be resolved.');
  return result.sha;
}
async function snapshot(commit) {
  const result = await api(`git/trees/${commit}?recursive=1`);
  if (result.truncated) throw Error('Incomplete upstream tree.');
  return result.tree.filter(entry => entry.type === 'blob' && entry.path.startsWith(prefix));
}
export async function check(root, upstream = {}) {
  const resolve = upstream.resolve || commitFor;
  const read = upstream.read || raw;
  const lock = installed(root);
  const releases = upstream.releases || await api('releases?per_page=100');
  const result = { installed: { skill: lock.skill.version, engine: lock.engine.version, cliReference: lock.cli.referenceVersion }, updates: [], pending: [] };
  for (const kind of ['skill', 'cli', 'engine']) {
    const release = latest(releases, kind);
    if (!release) throw Error(`No stable ${kind} release in the bounded upstream release inventory.`);
    const version = release.tag_name.split('-v')[1];
    const current = kind === 'cli' ? lock.cli.referenceVersion : lock[kind].version;
    if (!newer(version, current)) continue;
    if (kind === 'cli') {
      result.updates.push({ kind, version, tag: release.tag_name, url: release.html_url, note: 'CLI reference only; the vendored launcher does not install the npm CLI.' });
      continue;
    }
    let commit = await resolve(release.tag_name);
    let engine = (await read(commit, prefix + 'scripts/VERSION')).toString().trim();
    let manifest = await read(commit, prefix + 'scripts/engine-checksums.txt');
    // Engine binaries may be released before their installer manifest is recorded.
    // A later exact upstream commit may provide that manifest without importing
    // unreleased skill prose. Only these engine-support files are then considered.
    if (kind === 'engine' && engine !== version) {
      commit = await resolve('main');
      engine = (await read(commit, prefix + 'scripts/VERSION')).toString().trim();
      manifest = await read(commit, prefix + 'scripts/engine-checksums.txt');
    }
    try {
      if (kind === 'engine' && engine !== version) throw Error(`Upstream installer still pins engine ${engine}.`);
      if (newer(lock.engine.version, engine)) throw Error('Skill release would downgrade the already pinned engine.');
      if (!releases.some(r => r.tag_name === `engine-v${engine}` && !r.draft && !r.prerelease)) throw Error('No matching stable engine release.');
      const checksums = parseChecksums(manifest.toString(), engine);
      if (kind === 'skill') {
        const prose = (await read(commit, prefix + 'SKILL.md')).toString();
        if (prose.match(/version:\s*(\d+\.\d+\.\d+)/)?.[1] !== version) throw Error('Release skill metadata is inconsistent.');
      }
      result.updates.push({ kind, version, tag: release.tag_name, url: release.html_url, commit, engine, checksums });
    } catch (error) { result.pending.push({ kind, version, url: release.html_url, reason: error.message }); }
  }
  return result;
}
export async function apply(root, report = null, upstream = {}) {
  const lock = installed(root);
  const result = report || await check(root);
  const writes = new Map();
  for (const update of result.updates) {
    if (update.kind === 'cli') { lock.cli = { referenceVersion: update.version, releaseTag: update.tag }; continue; }
    let tree = await (upstream.tree || snapshot)(update.commit);
    if (update.kind === 'engine') tree = tree.filter(entry => /^scripts\/(?:VERSION|engine-checksums\.txt|impeccable|impeccable\.cmd)$/.test(entry.path.slice(prefix.length)));
    const required = ['scripts/VERSION', 'scripts/engine-checksums.txt', 'scripts/impeccable', 'scripts/impeccable.cmd'];
    if (required.some(file => !tree.some(entry => entry.path === prefix + file))) throw Error('Incomplete upstream launcher tree; nothing was applied.');
    for (const entry of tree) {
      if (entry.path.includes('..') || !['100644', '100755'].includes(entry.mode)) throw Error('Unsafe upstream skill tree entry.');
      const previous = lock.files[entry.path];
      if (previous?.upstreamBlob === entry.sha) continue;
      const incoming = await (upstream.read || raw)(update.commit, entry.path);
      if (blobHash(incoming) !== entry.sha) throw Error('Upstream file does not match its pinned Git object.');
      let merged = incoming;
      if (previous) {
        const original = upstream.blob ? await upstream.blob(previous.upstreamBlob) : await raw(previous.sourceCommit, entry.path);
        if (blobHash(original) !== previous.upstreamBlob) throw Error('Upstream merge base failed verification.');
        merged = mergeContent(fs.readFileSync(path.join(root, entry.path)), original, incoming);
      } else if (fs.existsSync(path.join(root, entry.path))) throw Error('New upstream file collides with local-only provenance.');
      writes.set(entry.path, { bytes: merged, mode: parseInt(entry.mode, 8) & 0o777 });
      lock.files[entry.path] = { sha256: sha256(merged), upstreamBlob: entry.sha, sourceCommit: update.commit };
    }
    if (update.kind === 'skill') lock.skill = { version: update.version, sourceCommit: update.commit, releaseTag: update.tag };
    lock.engine = { version: update.engine, sourceCommit: update.commit, checksums: update.checksums };
  }
  // Missing upstream files are retained, never silently deleted. Local licenses,
  // notices and provenance stay local-only; a reviewer sees every proposed delta.
  if (result.updates.length) {
    for (const [file, value] of writes) { fs.mkdirSync(path.dirname(path.join(root, file)), { recursive: true }); fs.writeFileSync(path.join(root, file), value.bytes, { mode: value.mode }); }
    fs.writeFileSync(path.join(root, lockPath), JSON.stringify(lock, null, 2) + '\n');
    const design = path.join(root, 'DESIGN.md');
    const prose = fs.readFileSync(design, 'utf8').replace(/Impeccable [^\n]+is vendored[^\n]+/, `Impeccable skill ${lock.skill.version}, engine ${lock.engine.version} is vendored under \`.agents/skills/impeccable\`. Pinned sources, local customizations and engine checksums are recorded in \`harness/impeccable.lock.json\`. Use audit/adapt/harden/polish playbooks against rendered evidence. Local engine caches and screenshot artifacts are not project source.`);
    fs.writeFileSync(design, prose);
    installed(root);
  }
  return result;
}
export function verifyEngine(root) {
  const lock = installed(root);
  const launcher = path.join(root, prefix, 'scripts/impeccable');
  execFileSync('sh', ['-n', launcher]);
  const env = { ...process.env };
  delete env.IMPECCABLE_BIN;
  // Ignore unverified developer overrides, sibling binaries and PATH. Never
  // execute downloaded skill hooks; smoke() supplies verified bytes explicitly.
  const engineName = `impeccable-${process.platform === 'win32' ? 'windows' : process.platform}-${process.arch === 'x64' ? 'x64' : process.arch}${process.platform === 'win32' ? '.exe' : ''}`;
  const checksum = lock.engine.checksums[engineName];
  if (!checksum) throw Error('No pinned engine checksum for this platform.');
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'crozztube-engine-check-'));
  return { lock, dir, engineName, checksum, env };
}
export async function smoke(root) {
  const { lock, dir, engineName, checksum, env } = verifyEngine(root);
  try {
    const binary = await get(`https://github.com/${repository}/releases/download/engine-v${lock.engine.version}/${engineName}`, false, 100_000_000);
    verifyAsset(binary, checksum);
    const executable = path.join(dir, engineName);
    fs.writeFileSync(executable, binary, { mode: 0o700 });
    // This override is used only after independently verifying the pinned bytes.
    env.IMPECCABLE_BIN = executable;
    const probe = execFileSync('sh', [path.join(root, prefix, 'scripts/impeccable'), 'engine-probe'], { env, encoding: 'utf8', timeout: 15_000 }).trim();
    if (probe !== `impeccable-engine ${lock.engine.version}`) throw Error('Engine reports a different version.');
    const fixture = path.join(dir, 'fixture.css');
    fs.writeFileSync(fixture, '.sample { background: linear-gradient(red, blue); background-clip: text; -webkit-text-fill-color: transparent; }\n');
    const scan = spawnSync(executable, ['detect', '--no-config', '--json', fixture], { cwd: dir, env, encoding: 'utf8', timeout: 30_000 });
    if (scan.status !== 2) throw Error('Engine detector failed to report the fixture violation.');
    const parsed = JSON.parse(scan.stdout);
    if (!Array.isArray(parsed) || !parsed.some(finding => finding.antipattern === 'gradient-text')) throw Error('Detector did not find the known fixture violation.');
    console.log(`PASS: checksum-verified engine ${lock.engine.version}, launcher handshake and detector fixture.`);
  } finally { fs.rmSync(dir, { recursive: true, force: true }); }
}
if (process.argv[1] && path.resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  const root = execFileSync('git', ['rev-parse', '--show-toplevel'], { encoding: 'utf8' }).trim();
  try {
    const command = process.argv[2] || 'check';
    if (command === 'check') console.log(JSON.stringify(await check(root), null, 2));
    else if (command === 'apply') console.log(JSON.stringify(await apply(root), null, 2));
    else if (command === 'verify') await smoke(root);
    else throw Error('Use check, apply or verify.');
  } catch (error) { console.error(`Impeccable maintenance: ${error.message}`); process.exitCode = 1; }
}
