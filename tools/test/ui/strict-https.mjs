import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { execFileSync } from 'node:child_process';
import assert from 'node:assert/strict';
import { chromium, firefox } from 'playwright';

const origin = 'https://crozztube.local:8443';
const caRoot = execFileSync('mkcert', ['-CAROOT'], { encoding: 'utf8' }).trim();
const profile = fs.mkdtempSync(path.join(os.tmpdir(), 'crozztube-https-'));
// Firefox gets the same public trust anchor as the real browser profiles.
// No private key, certificate override, or ignoreHTTPSErrors exception is used.
execFileSync('certutil', ['-N', '--empty-password', '-d', `sql:${profile}`]);
execFileSync('certutil', ['-A', '-d', `sql:${profile}`, '-n', 'CrozzTube local development CA', '-t', 'C,,', '-i', path.join(caRoot, 'rootCA.pem')]);

async function check(context, engine) {
  const writes = [];
  await context.route('**/*', async route => {
    const request = route.request(), url = new URL(request.url());
    if (!['GET', 'HEAD'].includes(request.method())) { writes.push(request.method()); await route.abort(); }
    else if (url.origin !== origin) await route.abort();
    else await route.continue();
  });
  const page = context.pages()[0] || await context.newPage();
  const response = await page.goto(`${origin}/login`, { waitUntil: 'networkidle' });
  assert.equal(response?.status(), 200);
  assert.equal(await page.evaluate(() => window.isSecureContext), true);
  assert.ok(await page.locator('.auth-card').isVisible());
  assert.deepEqual(writes, []);
  console.log(`${engine}: HTTPS and application render passed with certificate verification enabled.`);
}

try {
  const browser = await chromium.launch({ headless: true });
  try {
    const context = await browser.newContext({ ignoreHTTPSErrors: false });
    try { await check(context, 'Chromium'); } finally { await context.close(); }
  } finally { await browser.close(); }
  const context = await firefox.launchPersistentContext(profile, { headless: true, ignoreHTTPSErrors: false });
  try { await check(context, 'Firefox'); } finally { await context.close(); }
} finally { fs.rmSync(profile, { recursive: true, force: true }); }
