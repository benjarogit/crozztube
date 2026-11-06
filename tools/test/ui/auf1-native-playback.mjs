/** Opt-in real stream check: existing public import only, API writes blocked. */
import { chromium, expect } from '@playwright/test';
import { layoutSession } from './fixtures/readonly-layout.ts';
const slug = process.argv[2];
if (!slug || !/^[a-z0-9-]+$/.test(slug)) throw new Error('Pass an existing public AUF1 video slug.');
const browser = await chromium.launch();
const session = await layoutSession(browser, { authenticated: false, width: 1280, height: 900 });
let requests = 0, bytes = 0;
await session.context.route('https://cdn.jsdelivr.net/**', async route => {
  if (route.request().resourceType() === 'script' && /\/hls(?:\.min)?\.js$/.test(new URL(route.request().url()).pathname)) return route.continue();
  return route.abort();
});
const failures = [];
await session.context.route('https://gegenstimme.tv/**', async route => {
  if (!['GET', 'HEAD'].includes(route.request().method()) || ++requests > 30 || bytes > 12 * 1024 * 1024) return route.abort();
  await route.continue();
});
session.page.on('response', response => {
  if (new URL(response.url()).hostname !== 'gegenstimme.tv') return;
  if (response.status() >= 400) failures.push(response.status());
  bytes += Number(response.headers()['content-length'] || 0);
});
try {
  await session.goto(`/watch/${slug}`);
  const player = session.page.locator('.watch-vidstack-player');
  await expect(player).toBeVisible();
  await session.page.waitForFunction(() => document.querySelector('.watch-poster-overlay, .watch-vidstack-player .vds-play-button'), null, { timeout: 20000 });
  const overlay = session.page.locator('.watch-poster-overlay');
  if (await overlay.count()) await overlay.click();
  else await player.locator('.vds-play-button').click();
  await expect(session.page.locator('.watch-embed-player,.crz-mirror-layer')).toHaveCount(0);
  const media = player.locator('video');
  await expect.poll(() => media.evaluate(v => v.currentTime), { timeout: 30_000 }).toBeGreaterThan(2);
  const evidence = await media.evaluate(v => ({ time: v.currentTime, width: v.videoWidth, height: v.videoHeight, paused: v.paused, error: v.error?.code || null, frames: v.getVideoPlaybackQuality?.().totalVideoFrames || 0, audioBytes: v.webkitAudioDecodedByteCount || 0 }));
  expect(evidence.width).toBeGreaterThan(0);
  expect(evidence.frames).toBeGreaterThan(0);
  expect(evidence.error).toBe(null);
  expect(evidence.paused).toBe(false);
  expect(evidence.audioBytes).toBeGreaterThan(0);
  await media.evaluate(v => v.pause());
  await session.page.screenshot({ path: '/tmp/crozztube-auf1-native-playing.png' });
  expect(failures).toEqual([]);
  expect(session.errors).toEqual([]);
  expect(session.writes).toEqual([]);
  console.log(JSON.stringify({ result: 'Native AUF1 video and audio decoded without Mirror', ...evidence, requests, bytes }));
} catch (error) {
  await session.page.screenshot({ path: '/tmp/crozztube-auf1-native-error.png' });
  console.log('Player diagnostics', await session.page.locator('.watch-vidstack-player').evaluate(e => ({ text: e.textContent?.slice(0,300), provider: e.getAttribute('data-media-type'), error: e.querySelector('video')?.error?.code, state: e.querySelector('video')?.readyState, sources: [...e.querySelectorAll('source')].map(s => new URL(s.src).hostname) })));
  console.log('Page errors', session.errors);
  throw error;
} finally { await session.context.close(); await browser.close(); }
