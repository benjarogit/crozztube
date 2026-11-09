import { chromium, expect } from '@playwright/test';
import { applicationJson, layoutSession } from './fixtures/readonly-layout.ts';

const video = applicationJson('/api/videos?type=video&per_page=1', false).data[0];
if (!video?.id || !video?.slug) throw new Error('A local video fixture is required.');
const browser = await chromium.launch({ headless: true });
try {
  const session = await layoutSession(browser, { authenticated: false, width: 924, strictHttps: true,
    transform: (_path, payload) => payload.video ? { ...payload, video: { ...payload.video,
      source_type: 'upload', import_provider: null, video_url: null, video_sources: [], embed_url: null, playback_pending: false,
    } } : payload });
  try {
    const { page, context } = session;
    const source = { video_id: video.id, state: 'stale', kind: 'hls', has_direct: true, fresh: false,
      refreshing: false, auto_refresh_allowed: true, expires_in: 0, retry_after: 3, mirror: null };
    let requests = 0, release;
    await context.route('**/api/source-resolver/status/**', route => route.fulfill({ status: 200,
      contentType: 'application/json', body: JSON.stringify({ source }) }));
    await context.route('**/api/source-resolver/refresh/**', async route => {
      requests++;
      await new Promise(resolve => { release = resolve; });
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ source: { ...source, state: 'refreshing', refreshing: true } }) });
    });
    await page.goto(`/watch/${video.slug}`, { waitUntil: 'domcontentloaded' });
    const banner = page.locator('.crz-source-banner');
    await expect(banner).toBeVisible();
    await expect(banner).toContainText('Renewing the direct source…');
    await expect(banner).not.toContainText('expired');
    await expect.poll(() => typeof release).toBe('function');
    release();
    await expect.poll(() => requests).toBe(1);
    await expect(banner).toContainText('Renewing the direct source…');
    if (session.writes.length || session.errors.length || session.failedGets.length) throw new Error('The banner fixture must not issue real writes or fail application requests.');
    console.log('6 source-refresh banner checks passed. Internal recheck and running refresh use neutral copy; no real jobs or media requests.');
  } finally { await session.context.close(); }
} finally { await browser.close(); }
