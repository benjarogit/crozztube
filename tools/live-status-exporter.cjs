#!/usr/bin/env node
'use strict';

// Windows-compatible operational exporter only. It never consumes Docker apply
// or command triggers, starts services, invokes shell text, or inspects Env.
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const { spawnSync } = require('node:child_process');

const DEFAULT_ROOT = path.resolve(__dirname, '..');
const SCRIPT_PATH = fs.realpathSync(__filename);
const CONTAINER_FORMAT = '{"id":{{json .Id}},"image":{{json .Config.Image}},"image_id":{{json .Image}},"status":{{json .State.Status}},"running":{{.State.Running}},"restarting":{{.State.Restarting}},"restart_count":{{.RestartCount}},"exit_code":{{.State.ExitCode}},"health":{{with index .State "Health"}}{{json (index . "Status")}}{{else}}"none"{{end}},"started_at":{{json .State.StartedAt}},"finished_at":{{json .State.FinishedAt}},"has_error":{{if .State.Error}}true{{else}}false{{end}}}';
const IMAGE_FORMAT = '{"id":{{json .Id}},"tags":{{json .RepoTags}},"created":{{json .Created}},"size":{{.Size}},"architecture":{{json .Architecture}},"os":{{json .Os}}}';
const STATUSES = new Set(['created', 'running', 'restarting', 'paused', 'exited', 'dead', 'removing', 'missing', 'healthy', 'unhealthy', 'starting', 'none', 'unknown']);

function text(value, length) { return typeof value === 'string' ? value.trim().slice(0, length) : ''; }
function integer(value) { if (!Number.isSafeInteger(value)) throw new Error('Invalid inspection'); return value; }
function boolean(value) { if (typeof value !== 'boolean') throw new Error('Invalid inspection'); return value; }
function status(value) { if (!STATUSES.has(value)) throw new Error('Invalid inspection'); return value; }

function runtimePath(root, relative) {
  const normalized = String(relative).replaceAll('\\', '/');
  if (!/^livekit\/runtime\/[A-Za-z0-9_./-]+\.json$/.test(normalized)
      || normalized.split('/').some(segment => segment === '..' || segment === '.' || segment === '')) throw new Error('Invalid runtime path');
  const resolved = path.resolve(root, normalized);
  const runtimeRoot = path.resolve(root, 'livekit/runtime');
  if (!resolved.startsWith(`${runtimeRoot}${path.sep}`)) throw new Error('Invalid runtime path');
  // Reject symlinks/junctions along the path instead of following an operator-
  // controlled setting outside the workspace during an atomic write.
  let current = root;
  for (const segment of path.relative(root, resolved).split(path.sep)) {
    current = path.join(current, segment);
    try {
      if (fs.lstatSync(current).isSymbolicLink()) throw new Error('Invalid runtime path');
    } catch (error) { if (error.code !== 'ENOENT') throw error; }
  }
  return resolved;
}

function readConfiguration(root) {
  root = fs.realpathSync(root);
  const settingsFile = runtimePath(root, 'livekit/runtime/settings.json');
  if (fs.statSync(settingsFile).size > 1048576) throw new Error('Invalid settings');
  const source = JSON.parse(fs.readFileSync(settingsFile, 'utf8'));
  const names = [source.container_name || 'playhub-livekit', source.ingress_container_name || 'playhub-livekit-ingress', source.egress_container_name || 'playhub-livekit-egress'];
  if (names.some(name => typeof name !== 'string' || !/^[A-Za-z0-9][A-Za-z0-9_.-]{0,127}$/.test(name))) throw new Error('Invalid container names');
  if (new Set(names).size !== names.length) throw new Error('Duplicate container names');
  const relativeStatusPath = source.status_path || 'livekit/runtime/status/docker-status.json';
  const statusFile = runtimePath(root, relativeStatusPath);
  if (statusFile === settingsFile) throw new Error('Status cannot replace settings');
  // No keys, passwords, arbitrary settings, or trigger paths survive this read.
  return { root, statusFile, names };
}

function inspect(binary, args, run) {
  const result = run(binary || 'docker', args, { encoding: 'utf8', timeout: 5000, maxBuffer: 131072, windowsHide: true, shell: false });
  if (result.error || result.signal || result.status !== 0) {
    const target = args.at(-1);
    const missing = new RegExp(`^(?:Error(?: response from daemon)?:\\s*)?No such (?:object|container|image):\\s*${target.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}\\s*$`, 'i').test(String(result.stderr || '').trim());
    if (!result.error && !result.signal && missing) return null;
    // Keep the previous snapshot stale when Docker is unavailable; do not
    // replace it with fabricated "missing" statuses or print stderr bodies.
    throw new Error('Docker inspection unavailable');
  }
  const decoded = JSON.parse(result.stdout);
  if (!decoded || typeof decoded !== 'object' || Array.isArray(decoded)) throw new Error('Invalid inspection');
  return decoded;
}

function emptyImage() { return { id: '', tags: [], created: '', size: 0, architecture: '', os: '' }; }
function missingContainer(name) {
  return { exists: false, name, id: '', image: '', image_id: '', status: 'missing', running: false, restarting: false, restart_count: 0, exit_code: 0, health: 'none', started_at: '', finished_at: '', error: '' };
}

function inspectContainer(name, binary, run = spawnSync) {
  const observed = inspect(binary, ['inspect', '--type', 'container', '--format', CONTAINER_FORMAT, name], run);
  if (!observed) return { container: missingContainer(name), image: emptyImage() };
  if (!/^[a-f0-9]{12,64}$/i.test(observed.id) || !/^sha256:[a-f0-9]{64}$/i.test(observed.image_id)) throw new Error('Invalid inspection');
  const container = {
    exists: true, name, id: observed.id, image: text(observed.image, 255), image_id: observed.image_id,
    status: status(observed.status), running: boolean(observed.running), restarting: boolean(observed.restarting),
    restart_count: Math.max(0, integer(observed.restart_count)), exit_code: integer(observed.exit_code),
    health: status(observed.health), started_at: text(observed.started_at, 80), finished_at: text(observed.finished_at, 80),
    error: boolean(observed.has_error) ? 'Docker reports a container state error; inspect it locally.' : '',
  };
  const imageObserved = inspect(binary, ['image', 'inspect', '--format', IMAGE_FORMAT, container.image_id], run);
  if (!imageObserved) return { container, image: emptyImage() };
  if (imageObserved.id !== container.image_id) throw new Error('Invalid image identity');
  const image = { id: imageObserved.id, tags: Array.isArray(imageObserved.tags) ? imageObserved.tags.filter(tag => typeof tag === 'string').slice(0, 20).map(tag => text(tag, 255)) : [], created: text(imageObserved.created, 80), size: Math.max(0, integer(imageObserved.size)), architecture: text(imageObserved.architecture, 40), os: text(imageObserved.os, 40) };
  return { container, image };
}

function snapshot(configuration, binary, run = spawnSync) {
  const [core, ingress, egress] = configuration.names.map(name => inspectContainer(name, binary, run));
  return { updated_at: new Date().toISOString(), container: core.container, image: core.image, ingress_container: ingress.container, ingress_image: ingress.image, egress_container: egress.container, egress_image: egress.image };
}

function atomicWrite(configuration, observed, io = fs) {
  const statusFile = runtimePath(configuration.root, path.relative(configuration.root, configuration.statusFile));
  const temporaryFile = `${statusFile}.${crypto.randomBytes(12).toString('hex')}.tmp`;
  io.mkdirSync(path.dirname(statusFile), { recursive: true });
  try {
    io.writeFileSync(temporaryFile, `${JSON.stringify(observed)}\n`, { encoding: 'utf8', flag: 'wx', mode: 0o600 });
    io.renameSync(temporaryFile, statusFile);
  } finally {
    if (io.existsSync(temporaryFile)) io.unlinkSync(temporaryFile);
  }
}

function acquireLease(configuration) {
  const leaseFile = `${configuration.statusFile}.exporter.pid.json`;
  // Use the same guarded runtime parent and refuse every pre-existing lease.
  // This includes crashed owners and reused PIDs: the operator must verify the
  // recorded PID/start/script/root before clearing it; we never kill a process.
  runtimePath(configuration.root, path.relative(configuration.root, leaseFile));
  fs.mkdirSync(path.dirname(leaseFile), { recursive: true });
  const ownership = { pid: process.pid, process_started_at: new Date(Date.now() - process.uptime() * 1000).toISOString(), script: SCRIPT_PATH, root: configuration.root, status_file: configuration.statusFile, nonce: crypto.randomBytes(16).toString('hex') };
  const descriptor = fs.openSync(leaseFile, 'wx', 0o600);
  try { fs.writeFileSync(descriptor, JSON.stringify(ownership), 'utf8'); } finally { fs.closeSync(descriptor); }
  return () => {
    try {
      const current = JSON.parse(fs.readFileSync(leaseFile, 'utf8'));
      if (current.pid === ownership.pid && current.nonce === ownership.nonce && current.script === SCRIPT_PATH && current.root === configuration.root) fs.unlinkSync(leaseFile);
    } catch { /* Never delete a replacement or an unverified lease. */ }
  };
}

function parseArguments(args) {
  const options = { root: DEFAULT_ROOT, watch: false, interval: 5000 };
  let mode;
  for (let index = 0; index < args.length; index++) {
    const argument = args[index];
    if (argument === '--once' || argument === '--watch') { if (mode) throw new Error('Duplicate mode'); mode = argument; options.watch = argument === '--watch'; }
    else if (argument === '--help') options.help = true;
    else if (['--root', '--docker-binary', '--interval'].includes(argument)) {
      const value = args[++index];
      if (!value || value.startsWith('--')) throw new Error('Missing option value');
      options[{ '--root': 'root', '--docker-binary': 'dockerBinary', '--interval': 'interval' }[argument]] = value;
    } else throw new Error('Unknown option');
  }
  options.interval = Number(options.interval);
  if (!Number.isInteger(options.interval) || options.interval < 1000 || options.interval > 10000) throw new Error('Invalid interval');
  options.root = path.resolve(options.root);
  if (options.dockerBinary && !fs.statSync(options.dockerBinary).isFile()) throw new Error('Invalid Docker binary');
  return options;
}

function runOnce(options, run = spawnSync) {
  const configuration = readConfiguration(options.root);
  const release = acquireLease(configuration);
  try { atomicWrite(configuration, snapshot(configuration, options.dockerBinary, run)); } finally { release(); }
  return configuration;
}

async function watch(options) {
  const configuration = readConfiguration(options.root);
  const release = acquireLease(configuration);
  let stopping = false;
  let wake;
  const stop = () => { stopping = true; if (wake) wake(); };
  process.once('SIGINT', stop);
  process.once('SIGTERM', stop);
  process.once('exit', release);
  try {
    while (!stopping) {
      try {
        const current = readConfiguration(options.root);
        if (current.statusFile !== configuration.statusFile) throw new Error('Status path changed; restart exporter');
        atomicWrite(current, snapshot(current, options.dockerBinary));
      } catch { console.error('Live Docker snapshot was not updated. Check Docker access/runtime settings; no settings or error bodies are emitted.'); }
      if (!stopping) await new Promise(resolve => { const timer = setTimeout(resolve, options.interval); wake = () => { clearTimeout(timer); resolve(); }; });
    }
  } finally { process.removeListener('SIGINT', stop); process.removeListener('SIGTERM', stop); process.removeListener('exit', release); release(); }
}

if (require.main === module) {
  (async () => {
    const options = parseArguments(process.argv.slice(2));
    if (options.help) { console.log('Usage: node tools/live-status-exporter.cjs [--once | --watch] [--root PATH] [--docker-binary PATH] [--interval 5000]. Read-only Docker metadata only. An existing exporter PID lease requires operator provenance verification; no automatic startup task is installed.'); return; }
    if (options.watch) await watch(options);
    else { runOnce(options); console.log('PlayHub Live Docker status snapshot updated from actual read-only inspections.'); }
  })().catch(() => { console.error('Live Docker status export failed. Check Docker access, runtime paths and any existing exporter PID lease. No settings or error bodies are emitted.'); process.exitCode = 1; });
}

module.exports = { CONTAINER_FORMAT, IMAGE_FORMAT, readConfiguration, runtimePath, inspectContainer, snapshot, atomicWrite, acquireLease, parseArguments, runOnce };
