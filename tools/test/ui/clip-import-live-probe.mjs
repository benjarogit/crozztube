// Bounded registration-only protocol test. Publication uses the persistent workflow.
import { spawnSync } from 'node:child_process';
import { chromium } from '@playwright/test';
import { createClipFixture, uploadClipFixture } from './clip-browser-fixture.mjs';
if (process.argv.includes('--publish')) {
  console.error('Disposable publication is retired. Use clip-workflow-live-probe.mjs --run; pending Odysee entries must remain for confirmation.');
  process.exit(1);
}
if (!process.argv.includes('--run')) {
  console.log('No requests made. Use --run for browser → Oracle → Odysee URL registration only.'); process.exit(0);
}
const bridge = (operation, object) => {
  const result = spawnSync('docker', ['compose', '-f', 'docker/compose.yml', 'exec', '-T', '-u', 'www-data', 'web',
    'php', 'tools/test/php/clip-import-live-bridge.php', '--run'], {
    input: JSON.stringify({ operation, object }), encoding: 'utf8', timeout: 120000, maxBuffer: 16384,
  });
  if (result.status !== 0 || result.error) throw new Error('Metadata operation failed.');
  try { const value = JSON.parse(result.stdout); if (value.failed) throw new Error(); return value; }
  catch { throw new Error('Invalid metadata response.'); }
};
let browser, object;
try {
  browser = await chromium.launch({ headless: true });
  const context = await browser.newContext();
  const page = await context.newPage();
  const base = process.env.CROZZTUBE_TEST_URL || 'https://crozztube.local:8443';
  await context.route(`${base}/__clip_import_probe__`, route => route.fulfill({ contentType: 'text/html', body: '<canvas width="320" height="180"></canvas>' }));
  await page.goto(`${base}/__clip_import_probe__`);
  const metadata = await createClipFixture(page);
  if (metadata.bytes > 1048576) throw new Error('Fixture exceeds its traffic limit.');
  const admission = bridge('grant', metadata); object = admission.object;
  if (await uploadClipFixture(page, admission.grant) !== 204) throw new Error('Browser upload failed.');
  if (!bridge('register', object).registered) throw new Error('URL registration failed.');
  console.log('Odysee accepted URL registration only; publication and playback are not claimed.');
} catch {
  console.error('Registration probe failed; sensitive diagnostics suppressed.'); process.exitCode = 1;
} finally {
  await browser?.close();
  if (object) {
    try { if (!bridge('cleanup', object).deleted) throw new Error(); console.log('Registration test staging copies removed. No claim was created.'); }
    catch { console.error('Registration test staging cleanup needs follow-up.'); process.exitCode = 1; }
  }
}
