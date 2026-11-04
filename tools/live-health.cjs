#!/usr/bin/env node
'use strict';

// Read-only deployment diagnostics. Never issue RTC grants, start services, consume
// jobs, print settings bodies, or turn a listener probe into a media acceptance claim.
const fs = require('node:fs');
const path = require('node:path');
const net = require('node:net');
const dgram = require('node:dgram');
const http = require('node:http');
const https = require('node:https');
const tls = require('node:tls');
const crypto = require('node:crypto');
const { spawnSync } = require('node:child_process');

const DEFAULT_ROOT = path.resolve(__dirname, '..');
const CONTAINERS = { core: 'playhub-livekit', ingress: 'playhub-livekit-ingress', egress: 'playhub-livekit-egress' };
const ERROR_CODES = new Set(['ECONNREFUSED', 'ETIMEDOUT', 'ENOTFOUND', 'EHOSTUNREACH', 'ECONNRESET', 'CERT_HAS_EXPIRED', 'UNABLE_TO_VERIFY_LEAF_SIGNATURE', 'DEPTH_ZERO_SELF_SIGNED_CERT', 'SELF_SIGNED_CERT_IN_CHAIN', 'ERR_TLS_CERT_ALTNAME_INVALID']);
const result = (name, status, evidence) => ({ name, status, evidence });
const safeError = error => ERROR_CODES.has(error?.code) ? error.code : 'Probe failed';

function cleanUrl(value, schemes = ['wss:']) {
  const url = new URL(value);
  if (!schemes.includes(url.protocol) || url.username || url.password || url.search || url.hash) throw new Error('Unsupported endpoint');
  return url;
}

function port(value) {
  const number = Number(value);
  if (!Number.isInteger(number) || number < 1 || number > 65535) throw new Error('Invalid configured port');
  return number;
}

function effectivePortConfiguration(source, advanced) {
  // Match LiveKitSettings::generatedLiveKitConfigValues/ generatedDockerCompose:
  // basic fields overwrite advanced fields; UDP mux and additional listeners
  // survive, and the generated core explicitly enables TURN.
  const optionalPort = value => Number(value || 0) > 0 ? port(value) : null;
  const turnUdpPort = source.turn_tls_enabled ? optionalPort(advanced.turn?.udp_port) : port(source.turn_udp_port);
  const turnTlsPort = source.turn_tls_enabled ? port(source.turn_tls_port) : optionalPort(advanced.turn?.tls_port);
  let turnRelayStart = null;
  let turnRelayEnd = null;
  if (turnUdpPort || turnTlsPort) {
    const start = Number(advanced.turn?.relay_range_start || 0);
    const end = Number(advanced.turn?.relay_range_end || 0);
    // These are pinned LiveKit defaults when either range bound is unset/zero,
    // also used by the server's generated bridge Compose configuration.
    turnRelayStart = start === 0 || end === 0 ? 30000 : port(start);
    turnRelayEnd = start === 0 || end === 0 ? (advanced.development ? 30002 : 40000) : port(end);
    if (turnRelayStart > turnRelayEnd) throw new Error('Invalid configured relay range');
  }
  return {
    signalPort: port(source.signal_port),
    rtcTcpPort: port(source.rtc_tcp_port),
    rtcUdpStart: port(source.rtc_udp_port_start),
    rtcUdpEnd: port(source.rtc_udp_port_end),
    rtcUdpMuxPort: optionalPort(advanced.rtc?.udp_port),
    turnUdpPort,
    turnTlsPort,
    turnTlsDomain: source.turn_tls_enabled ? (source.turn_tls_domain || source.ssl_domain || '') : (advanced.turn?.domain || ''),
    turnEnabled: true,
    turnRelayStart,
    turnRelayEnd,
  };
}

function readConfiguration(root) {
  const source = JSON.parse(fs.readFileSync(path.join(root, 'livekit/runtime/settings.json'), 'utf8'));
  // Missing/blank advanced settings normalize to the repository defaults. An
  // explicit empty object instead requests the pinned server's unset defaults.
  const advanced = source.advanced_config_json?.trim() ? JSON.parse(source.advanced_config_json) : { turn: { relay_range_start: 1024, relay_range_end: 30000 } };
  const containerName = (value, fallback) => {
    const name = String(value || fallback);
    if (!/^[A-Za-z0-9][A-Za-z0-9_.-]*$/.test(name)) throw new Error('Invalid container name');
    return name;
  };
  // Whitelist only operational fields. The input file also contains secrets.
  return {
    enabled: source.enabled === true,
    publicUrl: cleanUrl(source.url).href,
    ...effectivePortConfiguration(source, advanced),
    redisPort: port(source.redis_port),
    redisHost: String(source.redis_host || '127.0.0.1'),
    rtmpPort: source.rtmp_url ? port(source.rtmp_port) : null,
    nodeIpConfigured: Boolean(source.node_ip),
    useExternalIp: source.use_external_ip === true,
    credentialsConfigured: Boolean(source.api_key && source.api_secret),
    containers: {
      core: containerName(source.container_name, CONTAINERS.core),
      ingress: containerName(source.ingress_container_name, CONTAINERS.ingress),
      egress: containerName(source.egress_container_name, CONTAINERS.egress),
    },
  };
}

function tcpProbe(host, targetPort, timeout) {
  return new Promise(resolve => {
    const socket = net.createConnection({ host, port: targetPort });
    let complete = false;
    const finish = value => { if (!complete) { complete = true; socket.destroy(); resolve(value); } };
    socket.setTimeout(timeout, () => finish({ ok: false, error: 'ETIMEDOUT' }));
    socket.once('connect', () => finish({ ok: true }));
    socket.once('error', error => finish({ ok: false, error: safeError(error) }));
  });
}

function tlsProbe(host, targetPort, timeout, ca) {
  return new Promise(resolve => {
    const socket = tls.connect({ host, port: targetPort, servername: host, ca });
    let complete = false;
    const finish = value => { if (!complete) { complete = true; socket.destroy(); resolve(value); } };
    socket.setTimeout(timeout, () => finish({ ok: false, error: 'ETIMEDOUT' }));
    socket.once('secureConnect', () => finish({ ok: socket.authorized }));
    socket.once('error', error => finish({ ok: false, error: safeError(error) }));
  });
}

function httpProbe(url, options = {}) {
  return new Promise(resolve => {
    const client = url.protocol === 'https:' ? https : http;
    let complete = false;
    const finish = value => { if (!complete) { complete = true; resolve(value); } };
    const request = client.request(url, {
      method: options.method || 'GET',
      headers: options.headers,
      ca: options.ca,
      // Keep certificate and hostname verification enabled, including local HTTPS.
      timeout: options.timeout || 3000,
    }, response => {
      const chunks = [];
      let bytes = 0;
      response.on('data', chunk => {
        bytes += chunk.length;
        if (bytes <= 65536) chunks.push(chunk);
        else request.destroy();
      });
      response.on('end', () => finish({ ok: true, status: response.statusCode, body: Buffer.concat(chunks).toString('utf8') }));
      response.on('error', error => finish({ ok: false, error: safeError(error) }));
    });
    request.on('upgrade', (response, socket) => { socket.destroy(); finish({ ok: true, status: response.statusCode }); });
    request.on('timeout', () => { request.destroy(); finish({ ok: false, error: 'ETIMEDOUT' }); });
    request.on('error', error => finish({ ok: false, error: safeError(error) }));
    request.end();
  });
}

function websocketRouteUrl(publicUrl) {
  const url = cleanUrl(publicUrl);
  url.protocol = 'https:';
  url.pathname = `${url.pathname.replace(/\/$/, '')}/rtc`;
  return url;
}

function classifyWebsocketRoute(probe) {
  if (!probe.ok) return result('nginx WSS route', 'FAIL', probe.error);
  if (probe.status === 101) return result('nginx WSS route', 'WARN', 'Unauthenticated upgrade accepted; audit authentication. No media was tested.');
  if ([400, 401, 403].includes(probe.status)) return result('nginx WSS route', 'PASS', `Upgrade request rejected with HTTP ${probe.status}; compare the core response below. Real PlayHub-issued grant still requires acceptance.`);
  return result('nginx WSS route', 'FAIL', `HTTP ${probe.status}; expected LiveKit authentication rejection, not a gateway error, redirect, or application page.`);
}

function validateStunResponse(message, transaction) {
  return message.length >= 20 && [0x0101, 0x0111].includes(message.readUInt16BE(0))
    && message.readUInt32BE(4) === 0x2112a442 && message.subarray(8, 20).equals(transaction)
    && message.readUInt16BE(2) + 20 <= message.length;
}

function stunProbe(host, targetPort, timeout) {
  return new Promise(resolve => {
    const socket = dgram.createSocket('udp4');
    const transaction = crypto.randomBytes(12);
    const request = Buffer.alloc(20);
    request.writeUInt16BE(0x0001, 0);
    request.writeUInt32BE(0x2112a442, 4);
    transaction.copy(request, 8);
    let complete = false;
    const timer = setTimeout(() => finish({ ok: false, error: 'ETIMEDOUT' }), timeout);
    function finish(value) {
      if (!complete) { complete = true; clearTimeout(timer); socket.close(); resolve(value); }
    }
    socket.on('error', error => finish({ ok: false, error: safeError(error) }));
    socket.on('message', message => { if (validateStunResponse(message, transaction)) finish({ ok: true }); });
    socket.connect(targetPort, host, () => socket.send(request, error => { if (error) finish({ ok: false, error: safeError(error) }); }));
  });
}

function dockerSnapshot(containers, dockerBinary = 'docker') {
  // Do not output docker inspect wholesale: Env, command arguments and mounts can contain secrets.
  const template = '{"name":{{json .Name}},"running":{{.State.Running}},"status":{{json .State.Status}},"restart_count":{{.RestartCount}},"network_mode":{{json .HostConfig.NetworkMode}},"health":{{if .State.Health}}{{json .State.Health.Status}}{{else}}null{{end}},"ports":{{json .NetworkSettings.Ports}}}';
  const execution = spawnSync(dockerBinary, ['inspect', '--format', template, ...Object.values(containers)], { encoding: 'utf8', timeout: 15000, windowsHide: true });
  const snapshot = Object.fromEntries(Object.keys(containers).map(key => [key, null]));
  if (execution.error) return snapshot;
  // Docker can return nonzero for an absent optional container while still
  // returning valid core metadata. Never print the raw stderr or inspect body.
  for (const line of (execution.stdout || '').split(/\r?\n/).filter(Boolean)) {
    try {
      const state = JSON.parse(line);
      const key = Object.keys(containers).find(value => `/${containers[value]}` === state.name);
      if (key) snapshot[key] = state;
    } catch { /* Incomplete metadata remains unverified. */ }
  }
  return snapshot;
}

function published(state, targetPort, protocol, loopbackOnly = false) {
  return Boolean(state?.ports?.[`${targetPort}/${protocol}`]?.some(binding => binding.HostPort === String(targetPort)
    && (!loopbackOnly || ['127.0.0.1', '::1'].includes(binding.HostIp))));
}

function requiredPortBindings(config) {
  if (config.rtcUdpStart > config.rtcUdpEnd) throw new Error('Invalid configured RTC range');
  const bindings = new Map();
  const add = (number, protocol, loopback = false) => {
    const key = `${number}/${protocol}`;
    const previous = bindings.get(key);
    bindings.set(key, [number, protocol, loopback || previous?.[2] || false]);
  };
  const udpRange = (start, end) => { for (let number = start; number <= end; number++) add(number, 'udp'); };
  add(config.signalPort, 'tcp', true);
  add(config.rtcTcpPort, 'tcp');
  if (config.rtcUdpMuxPort) add(config.rtcUdpMuxPort, 'udp');
  else udpRange(config.rtcUdpStart, config.rtcUdpEnd);
  if (config.turnEnabled) {
    if (config.turnUdpPort) add(config.turnUdpPort, 'udp');
    if (config.turnTlsPort) add(config.turnTlsPort, 'tcp');
    if (config.turnRelayStart && config.turnRelayEnd) udpRange(config.turnRelayStart, config.turnRelayEnd);
  }
  // A listener or relay range may overlap RTC; each socket is required once.
  return [...bindings.values()];
}

function classifyPortPublishing(config, state) {
  const missing = requiredPortBindings(config).filter(([number, protocol, loopback]) => !published(state, number, protocol, loopback));
  const rtc = config.rtcUdpMuxPort ? `UDP mux ${config.rtcUdpMuxPort}` : `UDP ${config.rtcUdpStart}-${config.rtcUdpEnd}`;
  const checks = [result('core port publishing', missing.length ? 'FAIL' : 'PASS', `Configured signal TCP, RTC TCP/${rtc}, TURN listeners and relay sockets checked; ${missing.length} required mappings missing. Signal must bind host loopback. No media tested.`)];
  if (config.turnEnabled && config.turnRelayStart && config.turnRelayEnd) {
    let missingRelay = 0;
    for (let number = config.turnRelayStart; number <= config.turnRelayEnd; number++) if (!published(state, number, 'udp')) missingRelay++;
    checks.push(result('TURN relay publishing', missingRelay ? 'FAIL' : 'PASS', `UDP ${config.turnRelayStart}-${config.turnRelayEnd}: ${missingRelay} missing mappings. Authenticated TURN allocation and actual selected relay media remain unverified.`));
  } else checks.push(result('TURN relay publishing', 'NOT_CONFIGURED', 'No enabled TURN listener/relay range configured.'));
  return checks;
}

function classifyContainer(name, first, second, required) {
  if (!first || !second) return result(name, required ? 'FAIL' : 'NOT_CONFIGURED', 'Container absent or Docker unavailable.');
  if (second.network_mode === 'host') return result(name, 'FAIL', 'Host networking is forbidden for this supported deployment; use the generated bridge configuration.');
  if (!first.running || !second.running || second.health === 'unhealthy' || second.restart_count !== first.restart_count) return result(name, 'FAIL', 'Not running stably during the observation window.');
  return result(name, second.health === 'starting' ? 'WARN' : 'PASS', `Running; restarts ${second.restart_count}, health ${second.health || 'not declared'}. This short observation does not prove long-term stability.`);
}

function windowsProcessInventoryScript(root, workerPid = 0, schedulerPid = 0) {
  const escapedRoot = root.replace(/'/g, "''");
  // PowerShell regex escapes must survive JavaScript string parsing. In particular,
  // an ordinary template literal turns \s into s and misses queues followed by flags.
  return String.raw`$project='${escapedRoot}'; $workerPid=${workerPid}; $schedulerPid=${schedulerPid};
$identityPattern='(?:^|\s|")'+[regex]::Escape((Join-Path $project 'artisan'))+'(?:\s|$|")';
$rows=@(Get-CimInstance Win32_Process -Filter "Name='php.exe'" -ErrorAction Stop | ForEach-Object {
  $line=$_.CommandLine;
  $storedKind=if ($_.ProcessId -eq $workerPid) {'worker'} elseif ($_.ProcessId -eq $schedulerPid) {'scheduler'} else {'unknown'};
  if ([string]::IsNullOrWhiteSpace($line)) {
    [pscustomobject]@{kind=$storedKind; project=$false; legacy=($storedKind -ne 'unknown'); queues=$false; unreadable=$true; pid=[int]$_.ProcessId}
  } elseif ($line -match '(?:^|\s|")(queue:work|schedule:work)(?:\s|$|")') {
    [pscustomobject]@{kind=$(if ($line -match '(?:^|\s|")queue:work(?:\s|$|")') {'worker'} else {'scheduler'}); project=($line -match $identityPattern); legacy=($storedKind -ne 'unknown'); queues=($line -match '(?:^|\s|")--queue=videos,default(?:\s|$|")'); unreadable=$false; pid=[int]$_.ProcessId}
  }
}); ConvertTo-Json -InputObject $rows -Compress`;
}

function processSnapshot(root) {
  const workerPid = (() => { try { const value = fs.readFileSync(path.join(root, 'storage/app/queue-worker.pid'), 'utf8').trim(); return /^\d+$/.test(value) ? Number(value) : null; } catch { return null; } })();
  const schedulerPid = (() => { try { const value = fs.readFileSync(path.join(root, 'storage/app/scheduler.pid'), 'utf8').trim(); return /^\d+$/.test(value) ? Number(value) : null; } catch { return null; } })();
  if (process.platform === 'win32') {
    const script = windowsProcessInventoryScript(root, workerPid || 0, schedulerPid || 0);
    const execution = spawnSync('powershell.exe', ['-NoProfile', '-NonInteractive', '-Command', script], { encoding: 'utf8', timeout: 15000, windowsHide: true });
    if (execution.error || execution.status !== 0) return null;
    try { const value = JSON.parse(execution.stdout || '[]'); return Array.isArray(value) ? value : [value]; } catch { return null; }
  }
  if (process.platform === 'linux') {
    const rows = [];
    for (const pid of fs.readdirSync('/proc').filter(value => /^\d+$/.test(value))) {
      try {
        const args = fs.readFileSync(`/proc/${pid}/cmdline`, 'utf8').split('\0');
        if (!args.some(value => /(?:^|\/)artisan$/.test(value))) continue;
        const kind = args.includes('queue:work') ? 'worker' : args.includes('schedule:work') ? 'scheduler' : null;
        if (kind) rows.push({ kind, project: fs.realpathSync(`/proc/${pid}/cwd`) === fs.realpathSync(root) || args.includes(path.join(root, 'artisan')), queues: args.includes('--queue=videos,default'), pid: Number(pid) });
      } catch { /* Inaccessible processes remain unverified. */ }
    }
    return rows;
  }
  return null;
}

function classifyProcesses(rows) {
  if (rows === null) return [result('queue worker', 'WARN', 'Process inventory unavailable; inspect the service manager without exposing command lines.'), result('scheduler', 'WARN', 'Process inventory unavailable; cron/Task Scheduler execution must be verified separately.')];
  const owned = rows.filter(row => row.project);
  const worker = owned.some(row => row.kind === 'worker' && row.queues);
  const scheduler = owned.some(row => row.kind === 'scheduler');
  const limited = kind => rows.some(row => row.unreadable && (row.kind === kind || row.kind === 'unknown'));
  const legacyWorker = rows.some(row => row.kind === 'worker' && row.legacy && !row.project);
  return [
    result('queue worker', worker ? 'PASS' : 'WARN', worker ? 'Observed absolute project artisan queue:work on videos,default; process presence is not proof that a fixture job completed.' : limited('worker') ? 'PHP command-line visibility is restricted; a project queue worker may be running. Verify its identity through the permitted service manager; no absent-worker conclusion or duplicate start.' : legacyWorker ? 'Stored legacy worker PID observed without absolute project identity; verify provenance and wait for its current job before a controlled restart. No duplicate worker was started.' : 'No matching absolute project worker on videos,default observed. Check the supervised service; this observation does not prove a worker is absent.'),
    result('scheduler', scheduler ? 'PASS' : 'WARN', scheduler ? 'Observed absolute project artisan schedule:work. Verify actual scheduled execution and a fixture effect separately.' : limited('scheduler') ? 'PHP command-line visibility is restricted; scheduler process identity is unverified. Cron or Task Scheduler execution must be verified separately.' : 'No project schedule:work observed. Cron or Task Scheduler may be used instead; verify their execution history and a fixture effect.'),
  ];
}

async function run(options = {}) {
  const root = options.root || DEFAULT_ROOT;
  const timeout = options.timeout || 3000;
  const config = readConfiguration(root);
  const caBytes = options.caFile ? fs.readFileSync(options.caFile) : undefined;
  const ca = caBytes && !caBytes.toString('utf8').includes('-----BEGIN CERTIFICATE-----') ? new crypto.X509Certificate(caBytes).toString() : caBytes;
  const checks = [result('configuration', config.enabled && config.credentialsConfigured ? 'PASS' : 'FAIL', 'Generated runtime settings loaded; credential values are never emitted.')];
  const first = dockerSnapshot(config.containers, options.dockerBinary);
  const coreHeaders = { Connection: 'Upgrade', Upgrade: 'websocket', 'Sec-WebSocket-Version': '13', 'Sec-WebSocket-Key': crypto.randomBytes(16).toString('base64') };
  const [coreHttp, directUpgrade, proxyUpgrade, rtcTcp, redis, turn, egress, ingress] = await Promise.all([
    httpProbe(new URL(`http://127.0.0.1:${config.signalPort}/`), { timeout }),
    httpProbe(new URL(`http://127.0.0.1:${config.signalPort}/rtc`), { timeout, headers: coreHeaders }),
    httpProbe(websocketRouteUrl(config.publicUrl), { timeout, ca, headers: coreHeaders }),
    tcpProbe('127.0.0.1', config.rtcTcpPort, timeout),
    tcpProbe(config.redisHost, config.redisPort, timeout),
    config.turnEnabled && config.turnUdpPort ? stunProbe('127.0.0.1', config.turnUdpPort, timeout) : Promise.resolve(null),
    first.egress ? tcpProbe('127.0.0.1', 7981, timeout) : Promise.resolve(null),
    first.ingress && config.rtmpPort ? tcpProbe('127.0.0.1', config.rtmpPort, timeout) : Promise.resolve(null),
  ]);
  const second = dockerSnapshot(config.containers, options.dockerBinary);
  checks.push(...Object.entries(config.containers).map(([key, name]) => classifyContainer(name, first[key], second[key], key === 'core')));
  checks.push(result('core HTTP', coreHttp.ok && coreHttp.status === 200 && coreHttp.body.trim() === 'OK' ? 'PASS' : 'FAIL', coreHttp.ok ? `HTTP ${coreHttp.status}; expected LiveKit OK response.` : coreHttp.error));
  const route = classifyWebsocketRoute(proxyUpgrade);
  if (route.status === 'PASS' && (!directUpgrade.ok || directUpgrade.status !== proxyUpgrade.status || directUpgrade.body !== proxyUpgrade.body)) {
    route.status = 'WARN'; route.evidence = proxyUpgrade.status === 403
      ? 'nginx rejected the unauthenticated upgrade; the Laravel admission guard may be active. An authenticated PlayHub-issued grant is still needed to prove this protected route reaches LiveKit.'
      : 'Proxy rejection does not match the direct core response; upstream routing needs inspection.';
  }
  checks.push(route);
  checks.push(result('RTC TCP listener', rtcTcp.ok ? 'PASS' : 'FAIL', rtcTcp.ok ? `TCP ${config.rtcTcpPort} accepts connections; no RTC media tested.` : rtcTcp.error));
  checks.push(result('Redis listener', redis.ok ? 'PASS' : 'FAIL', redis.ok ? `TCP ${config.redisPort} accepts connections; credentials/database/LiveKit Redis access not tested.` : redis.error));
  checks.push(...classifyPortPublishing(config, second.core));
  checks.push(result('advertised RTC address', config.nodeIpConfigured || config.useExternalIp ? 'WARN' : 'FAIL', 'Configured advertisement must be reachable by every participant. Actual ICE selected candidate is not tested here.'));
  checks.push(result('TURN UDP listener', turn ? (turn.ok ? 'PASS' : 'FAIL') : 'NOT_CONFIGURED', turn?.ok ? 'Matching STUN response received; allocation, TURN authentication and relay media remain unverified.' : turn?.error || 'No generated UDP TURN listener configured.'));
  checks.push(result('TURN TCP', 'NOT_CONFIGURED', 'This generator configures UDP TURN or TLS TURN; plain TURN TCP is not configured.'));
  if (config.turnEnabled && config.turnTlsPort) {
    const turnTls = config.turnTlsDomain ? await tlsProbe(config.turnTlsDomain, config.turnTlsPort, timeout, ca) : { ok: false, error: 'Missing TLS domain' };
    checks.push(result('TURN TLS listener', turnTls.ok ? 'PASS' : 'FAIL', turnTls.ok ? 'Trusted TLS handshake completed; authenticated TURN allocation and selected relay media remain unverified.' : turnTls.error));
  } else checks.push(result('TURN TLS', 'NOT_CONFIGURED', 'Disabled in generated settings.'));
  checks.push(result('egress health listener', egress ? (egress.ok && published(second.egress, 7981, 'tcp', true) ? 'PASS' : 'FAIL') : 'NOT_CONFIGURED', egress?.ok ? 'Host-loopback health port 7981 reachable; actual recording/output/replay is unverified.' : egress?.error || 'Optional container absent; recording is unavailable.'));
  checks.push(result('RTMP ingress listener', ingress ? (ingress.ok && published(second.ingress, config.rtmpPort, 'tcp') ? 'PASS' : 'FAIL') : 'NOT_CONFIGURED', ingress?.ok ? `RTMP TCP ${config.rtmpPort} reachable; actual encoder publishing is unverified.` : ingress?.error || 'Optional container absent; native camera hosting does not require ingress.'));
  checks.push(...classifyProcesses(processSnapshot(root)));
  const adminToken = process.env.PLAYHUB_LIVE_HEALTH_ADMIN_TOKEN;
  if (adminToken) {
    const adminBase = cleanUrl(options.adminBase || `${new URL(config.publicUrl).origin}/api`, ['https:']);
    const headers = { Authorization: `Bearer ${adminToken}`, Accept: 'application/json' };
    const status = await httpProbe(new URL(`${adminBase.href.replace(/\/$/, '')}/admin/livekit-status`), { timeout, ca, headers });
    let ready = false;
    try { ready = JSON.parse(status.body).ready === true; } catch { /* Do not expose bodies. */ }
    checks.push(result('PlayHub admin diagnostics', status.ok && status.status === 200 && ready ? 'PASS' : 'FAIL', 'Admin-only GET status checked; response bodies and authorization token are not emitted.'));
    const connection = await httpProbe(new URL(`${adminBase.href.replace(/\/$/, '')}/admin/livekit-test`), { timeout, ca, headers, method: 'POST' });
    let authenticated = false;
    try { authenticated = JSON.parse(connection.body).connection.success === true; } catch { /* Do not expose bodies. */ }
    checks.push(result('PlayHub server API authentication', connection.ok && connection.status === 200 && authenticated ? 'PASS' : 'FAIL', 'Existing admin test performs authenticated ListRooms on the server. Host/viewer RTC grants and media remain unverified.'));
  } else checks.push(result('PlayHub admin diagnostics', 'UNVERIFIED', 'Optional PLAYHUB_LIVE_HEALTH_ADMIN_TOKEN is unset; no admin requests made.'));
  checks.push(result('connected acceptance', 'UNVERIFIED', 'Run independent PlayHub host/viewer media, selected TURN relay, moderation/revocation, worker fixture execution and recording artifact/replay checks. Listener success cannot prove these.'));
  return { checked_at: new Date().toISOString(), endpoint: config.publicUrl, operational_status: checks.some(check => check.status === 'FAIL') ? 'FAIL' : checks.some(check => check.status === 'WARN') ? 'WARN' : 'PASS', checks };
}

function parseArguments(args) {
  const options = {};
  for (let i = 0; i < args.length; i++) {
    const argument = args[i];
    if (argument === '--json') options.json = true;
    else if (argument === '--help') options.help = true;
    else if (['--root', '--ca-file', '--admin-base', '--docker-binary', '--timeout'].includes(argument)) {
      const value = args[++i];
      if (!value || value.startsWith('--')) throw new Error('Missing option value');
      options[{ '--root': 'root', '--ca-file': 'caFile', '--admin-base': 'adminBase', '--docker-binary': 'dockerBinary', '--timeout': 'timeout' }[argument]] = value;
    } else throw new Error('Unknown option');
  }
  if (options.timeout !== undefined) {
    options.timeout = Number(options.timeout);
    if (!Number.isInteger(options.timeout) || options.timeout < 100 || options.timeout > 10000) throw new Error('Invalid timeout');
  }
  if (options.root) options.root = path.resolve(options.root);
  return options;
}

if (require.main === module) {
  (async () => {
    const options = parseArguments(process.argv.slice(2));
    if (options.help) {
      console.log('Usage: node tools/live-health.cjs [--json] [--root PATH] [--ca-file TRUSTED_CA_PEM] [--admin-base HTTPS_API_ORIGIN] [--docker-binary PATH] [--timeout 3000]');
      return;
    }
    const report = await run(options);
    if (options.json) console.log(JSON.stringify(report, null, 2));
    else { console.log(`PlayHub Live operations: ${report.operational_status}`); for (const check of report.checks) console.log(`${check.status.padEnd(14)} ${check.name}: ${check.evidence}`); }
    process.exitCode = report.operational_status === 'FAIL' ? 1 : 0;
  })().catch(() => { console.error('Live health verification failed. Check generated runtime settings, permitted paths and trusted CA configuration. No settings or error bodies are emitted.'); process.exitCode = 1; });
}

module.exports = { cleanUrl, readConfiguration, effectivePortConfiguration, requiredPortBindings, classifyPortPublishing, websocketRouteUrl, classifyWebsocketRoute, validateStunResponse, published, classifyContainer, classifyProcesses, windowsProcessInventoryScript, processSnapshot, parseArguments, run, httpProbe, stunProbe };
