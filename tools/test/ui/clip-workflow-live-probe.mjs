// Creates one persistent owner test job. The scheduler owns subsequent polling;
// no finally block deletes an Odysee entry that is still awaiting confirmation.
import { spawnSync } from 'node:child_process';
import { chromium } from '@playwright/test';
import { createClipFixture, uploadClipFixture } from './clip-browser-fixture.mjs';

if (!process.argv.includes('--run')) {
  console.log('No requests made. Use --run to create one retained owner test clip via the persistent workflow.');
  process.exit(0);
}
const owner = process.env.CROZZTUBE_TEST_USER || 'benny';
let id, browser;
const bridge = (operation, object) => {
  const result = spawnSync('docker', ['compose', '-f', 'docker/compose.yml', 'exec', '-T', '-u', 'www-data',
    'web', 'php', 'tools/test/php/clip-workflow-live-bridge.php', '--run'], {
    input: JSON.stringify({ owner, operation, object, id }), encoding: 'utf8', timeout: 180000, maxBuffer: 16384,
  });
  if (result.status !== 0 || result.error) throw new Error('Workflow metadata operation failed; sensitive details suppressed.');
  try { const value = JSON.parse(result.stdout); if (value.failed) throw new Error(); return value; }
  catch { throw new Error('Invalid workflow metadata response; sensitive details suppressed.'); }
};
try {
  browser = await chromium.launch({ headless: true });
  const context = await browser.newContext(); // Regular TLS verification.
  const page = await context.newPage();
  const base = process.env.CROZZTUBE_TEST_URL || 'https://crozztube.local:8443';
  await page.goto(base, { waitUntil: 'domcontentloaded' });
  await context.route(`${base}/__clip_workflow_probe__`, route => route.fulfill({ contentType: 'text/html',
    body: '<!doctype html><title>CrozzTube confirmation test</title><canvas width="320" height="180"></canvas>' }));
  await page.goto(`${base}/__clip_workflow_probe__`);
  const metadata = await createClipFixture(page);
  if (metadata.bytes > 1048576) throw new Error('Synthetic fixture exceeds the one-MiB limit.');
  let admission = bridge('reserve', metadata); id = admission.id;
  console.log(`Persistent test transfer: ${id}. Browser fixture: ${metadata.bytes} bytes.`);
  if (!admission.grant) {
    console.log('The transfer is queued. No publication or successful upload is claimed.');
    process.exitCode = 1;
  } else {
    const status = await uploadClipFixture(page, admission.grant);
    if (status !== 204) throw new Error('Browser upload was not confirmed. The workflow retains responsibility for cleanup.');
    bridge('uploaded');
    console.log('Browser uploaded directly to Oracle; the durable workflow will upload to Odysee and wait.');
    let result;
    for (let attempt = 0; attempt < 12; attempt++) {
      result = bridge('advance');
      console.log(`Transfer state: ${result.state}; provider accepted: ${result.provider_accepted}; ready notification: ${result.notification_recorded}.`);
      if (['ready', 'failed', 'cancelled', 'awaiting_confirmation', 'releasing'].includes(result.state)) break;
      await new Promise(resolve => setTimeout(resolve, 5000));
    }
    if (result.state !== 'ready') console.log('Not yet available. The scheduler keeps waiting; no Odysee test entry or staging copy was deleted by this probe.');
  }
} catch (error) {
  console.error(/^(Workflow metadata|Invalid workflow|Synthetic fixture|Browser upload)/.test(error?.message || '') ? error.message : 'Browser workflow test failed; raw diagnostics suppressed.');
  process.exitCode = 1;
} finally {
  await browser?.close();
  if (id) console.log(`Owner status: /clip-uploads?clip=${id}. No video bytes passed through PHP.`);
}
