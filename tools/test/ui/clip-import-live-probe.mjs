// Deliberate live test. Creates only a synthetic five-second canvas recording.
// Video stays in the browser; PHP receives size/hash/job metadata only.
import { spawnSync } from 'node:child_process';
import { chromium } from '@playwright/test';

if (!process.argv.includes('--run')) {
  console.log('No requests made. Use --run for a disposable browser → Oracle → Odysee URL-registration test.');
  process.exit(0);
}
const bridge = (operation, object) => {
  const result = spawnSync('docker', ['compose', '-f', 'docker/compose.yml', 'exec', '-T', '-u', 'www-data', 'web',
    'php', 'tools/test/php/clip-import-live-bridge.php', '--run'], {
    input: JSON.stringify({ operation, object }), encoding: 'utf8', timeout: 120000, maxBuffer: 16384,
  });
  // Never echo stderr/stdout: they may contain capabilities after a bootstrap error.
  if (result.status !== 0 || result.error) throw new Error(`The metadata bridge failed during ${operation}; details suppressed.`);
  try { return JSON.parse(result.stdout); } catch { throw new Error(`Invalid metadata bridge result during ${operation}; details suppressed.`); }
};
let browser, object, cleaned = false;
try {
  browser = await chromium.launch({ headless: true });
  const context = await browser.newContext(); // Normal HTTPS verification; no exceptions.
  const page = await context.newPage();
  const baseURL = process.env.CROZZTUBE_TEST_URL || 'https://crozztube.local:8443';
  await page.goto(baseURL, { waitUntil: 'domcontentloaded' });
  // Isolate the probe from application effects and drafts in the user's browser.
  await context.route(`${baseURL}/__clip_import_probe__`, route => route.fulfill({
    contentType: 'text/html', body: '<!doctype html><title>CrozzTube synthetic clip protocol test</title><canvas width="320" height="180"></canvas>',
  }));
  await page.goto(`${baseURL}/__clip_import_probe__`);
  const metadata = await page.evaluate(async () => {
    if (!MediaRecorder.isTypeSupported('video/mp4;codecs=avc1')) throw new Error('This browser cannot record the H.264 protocol fixture.');
    const canvas = document.querySelector('canvas'), ctx = canvas.getContext('2d');
    const stream = canvas.captureStream(10), chunks = [];
    const recorder = new MediaRecorder(stream, { mimeType: 'video/mp4;codecs=avc1', videoBitsPerSecond: 120000 });
    recorder.ondataavailable = event => { if (event.data.size) chunks.push(event.data); };
    const stopped = new Promise((resolve, reject) => { recorder.onstop = resolve; recorder.onerror = () => reject(new Error('Synthetic recording failed.')); });
    let frame = 0;
    const paint = () => {
      ctx.fillStyle = '#172131'; ctx.fillRect(0, 0, 320, 180);
      ctx.fillStyle = '#ffffff'; ctx.font = '18px sans-serif';
      ctx.fillText('CrozzTube import test', 24, 76);
      ctx.fillText(`${(++frame / 10).toFixed(1)} / 5 seconds`, 24, 108);
    };
    paint(); recorder.start();
    const ticker = setInterval(paint, 100);
    await new Promise(resolve => setTimeout(resolve, 5200));
    recorder.stop(); clearInterval(ticker); await stopped;
    stream.getTracks().forEach(track => track.stop());
    globalThis.syntheticClip = new Blob(chunks, { type: 'video/mp4' });
    const digest = await crypto.subtle.digest('SHA-256', await globalThis.syntheticClip.arrayBuffer());
    return { bytes: globalThis.syntheticClip.size, sha256: Array.from(new Uint8Array(digest), byte => byte.toString(16).padStart(2, '0')).join('') };
  });
  if (metadata.bytes > 1024 * 1024) throw new Error('The synthetic fixture exceeded its one-MiB traffic limit.');
  console.log(`Created synthetic H.264 fixture in browser (${metadata.bytes} bytes); no video bytes sent to PHP.`);
  const reservation = bridge('grant', metadata);
  object = reservation.object;
  const status = await page.evaluate(async grant => {
    const form = new FormData();
    for (const [key, value] of Object.entries(grant.fields)) form.append(key, value);
    form.append('file', globalThis.syntheticClip, 'crozztube-synthetic-test.mp4');
    try {
      const response = await fetch(grant.url, { method: 'POST', body: form, credentials: 'omit', redirect: 'error' });
      return response.status;
    } catch { return 0; }
  }, reservation.grant);
  if (status !== 204) throw new Error(`Browser direct upload was not confirmed (HTTP ${status || 'unreadable/CORS/transport'}).`);
  console.log('Browser POST confirmed with normal CORS and TLS verification.');
  const result = bridge('register', object);
  if (!result.registered) throw new Error(`Odysee registration failed at ${result.stage} (HTTP ${result.httpStatus ?? 'unknown'}, outcome uncertain: ${result.uncertain}).`);
  console.log('Odysee accepted the sealed Oracle URL. This does not prove publication, download completion or playback readiness.');
} catch (error) {
  // Every emitted message is our own fixed diagnostic; do not print raw browser/provider errors.
  const message = error instanceof Error && /^(The metadata bridge|Invalid metadata bridge|The synthetic fixture|Browser direct upload|Odysee registration)/.test(error.message)
    ? error.message : 'The synthetic browser import probe failed; raw diagnostics suppressed.';
  console.error(message);
  process.exitCode = 1;
} finally {
  await browser?.close();
  if (object) {
    try { cleaned = bridge('cleanup', object).deleted === true; } catch { /* Fixed diagnostic below. */ }
    if (cleaned) console.log('Both test staging objects deleted; absence confirmed. No account keys or signed URLs were printed.');
    else { console.error('Staging cleanup could not be confirmed; the probe requires follow-up cleanup.'); process.exitCode = 1; }
  }
}
