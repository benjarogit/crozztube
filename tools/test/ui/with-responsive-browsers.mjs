#!/usr/bin/env node
/** Keep missing host WebKit libraries out of the application machine.
 * An existing PW_WEBKIT_WS_ENDPOINT is reused; only a container started by this
 * process is stopped. No host packages or repository files are changed.
 */
import { spawn, execFileSync } from 'node:child_process';
import { createServer, connect } from 'node:net';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';
import { webkit } from '@playwright/test';

const require = createRequire(import.meta.url);
const directory = dirname(fileURLToPath(import.meta.url));
const project = resolve(directory, '../../..');
const cli = resolve(dirname(require.resolve('playwright/package.json')), 'cli.js');
let container = '', child;

function stopContainer() {
  if (!container) return;
  try { execFileSync('docker', ['stop', '--time', '2', container], { stdio: 'ignore', timeout: 10_000 }); }
  catch { console.error(`Could not stop test browser container ${container}; inspect it manually.`); }
  container = '';
}

async function freePort() {
  const server = createServer();
  await new Promise((resolve, reject) => { server.once('error', reject); server.listen(0, '127.0.0.1', resolve); });
  const port = server.address().port;
  await new Promise(resolve => server.close(resolve));
  return port;
}

function listening(port) {
  return new Promise(resolve => {
    const socket = connect(port, '127.0.0.1');
    socket.once('connect', () => { socket.destroy(); resolve(true); });
    socket.once('error', () => { socket.destroy(); resolve(false); });
  });
}

for (const signal of ['SIGINT', 'SIGTERM']) {
  process.on(signal, () => { child?.kill(signal); stopContainer(); process.exit(1); });
}

try {
  const env = { ...process.env };
  env.PW_WEBKIT_WS_ENDPOINT ||= env.PW_WEBKIT_WS;
  if (!env.PW_WEBKIT_WS_ENDPOINT) {
    let localAvailable = false;
    try {
      const browser = await webkit.launch({ headless: true });
      await browser.close();
      localAvailable = true;
    } catch { /* The bounded official browser image provides its own libraries. */ }
    if (!localAvailable) {
      const version = require('playwright/package.json').version;
      const image = env.PW_WEBKIT_IMAGE || `mcr.microsoft.com/playwright:v${version}-noble`;
      const port = await freePort();
      const name = `crozztube-test-webkit-${process.pid}`;
      console.log('WebKit host dependencies unavailable; starting an isolated browser (1 CPU, 1 GiB).');
      execFileSync('docker', [
        'run', '--rm', '-d', '--name', name, '--network', 'host', '--cpus=1', '--memory=1g', '--ipc=host',
        '--add-host', 'crozztube.local:127.0.0.1', '--mount', `type=bind,source=${project},target=/work,readonly`,
        '-w', '/work/tools/test/ui', image, 'node', 'node_modules/playwright/cli.js',
        'run-server', '--host', '127.0.0.1', '--port', String(port),
      ], { stdio: ['ignore', 'ignore', 'inherit'], timeout: 300_000 });
      container = name;
      let ready = false;
      for (let attempt = 0; attempt < 80; attempt++) {
        if (await listening(port)) { ready = true; break; }
        await new Promise(resolve => setTimeout(resolve, 250));
      }
      if (!ready) throw new Error('Isolated WebKit server did not become ready within 20 seconds.');
      env.PW_WEBKIT_WS_ENDPOINT = `ws://127.0.0.1:${port}/`;
    }
  }
  child = spawn(process.execPath, [cli, 'test', ...process.argv.slice(2)], { cwd: directory, env, stdio: 'inherit' });
  const code = await new Promise((resolve, reject) => { child.once('error', reject); child.once('exit', code => resolve(code ?? 1)); });
  process.exitCode = code;
} catch (error) {
  console.error(error.message);
  process.exitCode = 1;
} finally {
  stopContainer();
}
