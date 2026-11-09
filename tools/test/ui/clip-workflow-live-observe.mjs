// Read-only follow-up of one retained synthetic test clip. No new publication.
import { spawnSync } from 'node:child_process';
import { chromium } from '@playwright/test';
if (!process.argv.includes('--run')) {
  console.log('No requests made. Use --run --id=UUID to verify one retained synthetic clip in Odysee’s actual player.'); process.exit(0);
}
const id = process.argv.find(arg => arg.startsWith('--id='))?.slice(5);
if (!/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/.test(id || '')) {
  console.error('A valid existing synthetic transfer ID is required.'); process.exit(1);
}
let browser;
try {
  const result = spawnSync('docker', ['compose', '-f', 'docker/compose.yml', 'exec', '-T', '-u', 'www-data', 'web',
    'php', 'tools/test/php/clip-workflow-live-bridge.php', '--run'], {
    input: JSON.stringify({ operation: 'observe', owner: process.env.CROZZTUBE_TEST_USER || 'benny', id }),
    encoding: 'utf8', timeout: 30000, maxBuffer: 16384,
  });
  if (result.status !== 0) throw new Error('The retained clip is not ready for this bounded observation.');
  const data = JSON.parse(result.stdout);
  if (data.state !== 'ready' || !data.hash || !data.claim || data.bytes > 1048576 || data.confirmations < 1
    || data.notifications !== 1 || !data.cleanup_checked) throw new Error('Availability, notification or cleanup evidence is incomplete.');
  const embed = new URL(data.embed);
  if (embed.protocol !== 'https:' || embed.hostname !== 'odysee.com' || !embed.pathname.startsWith('/$/embed/')) throw new Error();
  browser = await chromium.launch({ headless: true });
  const context = await browser.newContext(); // Normal TLS; no account credentials in this browser.
  const page = await context.newPage(); let requests = 0;
  await context.route('**/*', async route => {
    const request = route.request(), url = new URL(request.url());
    if (request.resourceType() === 'media' || /\.(mp4|m3u8|ts|m4s)(?:$|\?)/i.test(url.pathname)) {
      if (!url.hostname.endsWith('.odycdn.com') || (!url.href.includes(data.hash) && !url.href.includes(data.claim)) || ++requests > 8) {
        await route.abort(); return;
      }
    }
    await route.continue();
  });
  await page.goto(embed.toString(), { waitUntil: 'domcontentloaded', timeout: 30000 });
  const play = page.getByRole('button', { name: 'Play', exact: true }).first();
  await play.waitFor({ state: 'visible', timeout: 20000 });
  // Consent belongs only to this disposable test context; reject optional tracking.
  const reject = page.getByRole('button', { name: 'Reject All', exact: true });
  if (await reject.isVisible()) await reject.click();
  await play.click();
  const video = page.locator('video').first();
  await video.waitFor({ state: 'attached', timeout: 15000 });
  const playback = await video.evaluate(async node => {
    node.muted = true;
    try {
      await Promise.race([node.play(), new Promise((_, reject) => setTimeout(() => reject(new Error()), 10000))]);
      await new Promise(resolve => setTimeout(resolve, 1200));
      return { time: node.currentTime, duration: node.duration, width: node.videoWidth, height: node.videoHeight };
    } catch { return { failed: true }; }
    finally { node.pause(); }
  });
  if (playback.failed || playback.time < 0.5 || playback.duration < 5 || playback.duration > 7 || playback.width !== 320 || playback.height !== 180) {
    throw new Error('Actual Odysee playback was not confirmed; the provider may still be throttling requests.');
  }
  console.log(`Retained clip ${id}: Odysee confirmation, owner-authorized playback, one notification and staging cleanup confirmed.`);
  console.log(`Actual Odysee player: ${playback.width}×${playback.height}, ${playback.duration.toFixed(2)} seconds, playback advanced ${playback.time.toFixed(2)} seconds. No video passed through PHP.`);
} catch (error) {
  console.error(/^(The retained clip|Availability,|Actual Odysee)/.test(error?.message || '') ? error.message : 'Live observation failed; sensitive diagnostics suppressed.');
  process.exitCode = 1;
} finally { await browser?.close(); }
