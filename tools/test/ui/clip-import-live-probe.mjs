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
const publish = process.argv.includes('--publish');
let browser, object, cleaned = false, submitted = false;
try {
  browser = await chromium.launch({ headless: true });
  const context = await browser.newContext(); // Normal HTTPS verification; no exceptions.
  const page = await context.newPage();
  page.on('response', response => {
    if (/^(?:[a-z0-9-]+\.)*odycdn\.com$/.test(new URL(response.url()).hostname)) {
      console.log(`Odysee CDN returned HTTP ${response.status()} (${response.headers()['content-type']?.startsWith('video/') ? 'video' : 'non-video'}).`);
    }
  });
  page.on('requestfailed', request => {
    if (/^(?:[a-z0-9-]+\.)*odycdn\.com$/.test(new URL(request.url()).hostname)) {
      const code = request.failure()?.errorText;
      console.log(`Odysee CDN request failed (${typeof code === 'string' && /^net::ERR_[A-Z0-9_]+$/.test(code) ? code : 'details suppressed'}).`);
    }
  });
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
    const localVideo = document.createElement('video');
    const localUrl = URL.createObjectURL(globalThis.syntheticClip);
    localVideo.muted = true; localVideo.src = localUrl;
    await localVideo.play();
    await new Promise(resolve => setTimeout(resolve, 250));
    if (!localVideo.videoWidth || localVideo.currentTime === 0) throw new Error('The synthetic recording is not playable locally.');
    localVideo.pause(); localVideo.removeAttribute('src'); localVideo.load(); URL.revokeObjectURL(localUrl);
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
  if (!publish) {
    const result = bridge('register', object);
    if (!result.registered) throw new Error(`Odysee registration failed at ${result.stage} (HTTP ${result.httpStatus ?? 'unknown'}, outcome uncertain: ${result.uncertain}).`);
    console.log('Odysee accepted the sealed Oracle URL. This does not prove publication, download completion or playback readiness.');
  } else {
    const call = operation => {
      const result = bridge(operation, object);
      if (result.failed) throw new Error(`Odysee publication failed during ${result.operation}: ${result.reason} (HTTP ${result.httpStatus ?? 'unknown'}).`);
      return result;
    };
    submitted = true; // A lost response may already have created the upstream job.
    if (!call('publish').submitted) throw new Error('Odysee publication was not confirmed.');
    console.log('Submitted one unlisted test publication; waiting for provider processing.');
    let ready = false;
    for (let attempt = 0; attempt < 30; attempt++) {
      const state = call('status').state;
      if (state === 'published') { ready = true; break; }
      if (attempt % 5 === 0) console.log(`Odysee query is ${state}; polling the existing job.`);
      await new Promise(resolve => setTimeout(resolve, 5000));
    }
    if (!ready) throw new Error('Odysee publication did not finish within the bounded test window.');
    console.log('Odysee completed publication with verified clip owner, channel and media metadata.');
    const source = call('playback').url;
    const kind = /\.m3u8(?:$|\?)/i.test(source) ? 'hls' : 'video';
    if (kind === 'hls') {
      // Same library range as the installed Vidstack HLS provider.
      await page.addScriptTag({ url: 'https://cdn.jsdelivr.net/npm/hls.js@^1.5.0/dist/hls.js' });
    }
    let playback = null;
    // A new LBRY claim can be in the wallet before CDN/indexer propagation.
    // Keep polling this single publication instead of making new uploads.
    for (let attempt = 0; attempt < 40; attempt++) {
      playback = await page.evaluate(async ({ url, kind }) => {
      const video = document.createElement('video'); video.muted = true; video.src = url; document.body.append(video);
      let hls;
      try {
        if (kind === 'hls' && !video.canPlayType('application/vnd.apple.mpegurl')) {
          video.removeAttribute('src');
          hls = new Hls({ maxBufferLength: 10, maxMaxBufferLength: 10, maxBufferSize: 1024 * 1024,
            fragLoadingMaxRetry: 0, manifestLoadingMaxRetry: 0, levelLoadingMaxRetry: 0 });
          hls.loadSource(url); hls.attachMedia(video);
        }
        await Promise.race([video.play(), new Promise((_, reject) => setTimeout(() => reject(new Error()), 10000))]);
        await new Promise(resolve => setTimeout(resolve, 1200));
        return { duration: video.duration, time: video.currentTime, width: video.videoWidth, height: video.videoHeight };
      } catch { return { error: video.error?.code ?? 0 }; }
      finally { hls?.destroy(); video.pause(); video.removeAttribute('src'); video.load(); video.remove(); }
      }, { url: source, kind });
      if (playback?.time >= 0.5) break;
      console.log(`Waiting for Odysee CDN playback (${kind}, media error ${playback?.error ?? 0}).`);
      await new Promise(resolve => setTimeout(resolve, 15000));
    }
    if (!playback || playback.time < 0.5 || playback.duration < 5 || playback.duration > 7 || !playback.width) throw new Error('Odysee publication was not playable in the browser.');
    console.log('Published clip played directly from Odysee in the browser.');
    if (!call('public').confirmed || !call('private').confirmed) throw new Error('Odysee publication visibility was not confirmed.');
    console.log('Public → unlisted visibility changes confirmed through the owner account.');
  }
} catch (error) {
  // Every emitted message is our own fixed diagnostic; do not print raw browser/provider errors.
  const message = error instanceof Error && /^(The metadata bridge|Invalid metadata bridge|The synthetic fixture|Browser direct upload|Odysee registration|Odysee publication)/.test(error.message)
    ? error.message : 'The synthetic browser import probe failed; raw diagnostics suppressed.';
  console.error(message);
  process.exitCode = 1;
} finally {
  await browser?.close();
  if (object) {
    if (submitted) {
      try {
        const result = bridge('abandon', object);
        if (result.failed || !result.abandoned) throw new Error();
        console.log('Only the synthetic test claim was abandoned; absence confirmed.');
      } catch { console.error('Test claim cleanup needs follow-up using its private probe receipt.'); process.exitCode = 1; }
    }
    try { cleaned = bridge('cleanup', object).deleted === true; } catch { /* Fixed diagnostic below. */ }
    if (cleaned) console.log('Both test staging objects deleted; absence confirmed. No account keys or signed URLs were printed.');
    else { console.error('Staging cleanup could not be confirmed; the probe requires follow-up cleanup.'); process.exitCode = 1; }
  }
}
