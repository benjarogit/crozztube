import { test, expect, firefox, webkit } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { layoutSession, expectContained, applicationJson } from '../fixtures/readonly-layout';

test.use({ trace: 'off', video: 'off' });
const original = () => applicationJson('/api/videos?type=video&per_page=1').data[0];
const title = 'Gemeinsam neue Geschichten entdecken: Ein sehr langer deutscher Videotitel mit verständlichen Kapiteln und Untertiteln';
function fixtureVideo() {
  return { ...original(), source_type: 'upload', import_provider: null, source_url: null, title,
    duration_seconds: 30, duration: '0:30', video_width: 640, video_height: 360,
    video_url: '/fixtures/viewer.mp4', video_sources: [{ src: '/fixtures/viewer.mp4', type: 'video/mp4' }],
    embed_url: null, playback_pending: false, thumbnail_url: null, thumbnail_candidates: [],
    chapters_enabled: true, chapters: [{ start_time: 0, title: 'Home' }, { start_time: 5, title: 'Settings' }],
    caption_tracks: [{ url: '/fixtures/viewer.vtt', format: 'vtt', label: 'Saved', language: 'en', default: true }],
  };
}
const transform = (path: string, payload: any) => {
  if (path.startsWith('/api/videos/') && payload.video) return { ...payload, player_ads: null, video: fixtureVideo() };
  if (path.startsWith('/api/continue-watching')) return { ...payload, data: [{ ...fixtureVideo(), progress: 13, current_time: 4 }] };
  if (path.startsWith('/api/videos?') && /type=short/.test(path)) return { ...payload, data: [{ ...fixtureVideo(), id: 999991, slug: 'viewer-short', is_short: true, video_width: 360, video_height: 640 }] };
  return payload;
};

for (const width of [320, 390, 768, 1024, 1440]) {
  test(`viewer layout, discovery order and deliberate playback at ${width}px`, async ({ browser }) => {
    const session = await layoutSession(browser, { language: 'de_de', width, dark: [768, 1024].includes(width), transform });
    try {
      const { page } = session;
      const streams: string[] = [];
      page.on('request', r => { if (r.resourceType() === 'media') streams.push(r.url()); });
      await session.goto('/');
      const headings = await page.locator('.home-page > .content-section > .section-title h2').allTextContents();
      const contrast = await page.locator('.hero-buttons .btn.primary').evaluate(e => {
        const style = getComputedStyle(e);
        const luminance = (color: string) => {
          const channels = color.match(/[\d.]+/g)!.slice(0, 3).map(Number).map(n => { const v = n / 255; return v <= .04045 ? v / 12.92 : ((v + .055) / 1.055) ** 2.4; });
          return channels[0] * .2126 + channels[1] * .7152 + channels[2] * .0722;
        };
        const a = luminance(style.color), b = luminance(style.backgroundColor);
        return (Math.max(a, b) + .05) / (Math.min(a, b) + .05);
      });
      expect(contrast).toBeGreaterThanOrEqual(4.5);
      expect(headings).toEqual(['Weiterschauen', 'Videos entdecken', 'Shorts', 'Creator entdecken']);
      await expect(page.locator('.continue-content')).toContainText('Noch 0:26');
      await expect(page.locator('.continue-card [role="progressbar"]')).toHaveAttribute('aria-valuenow', '13');
      await expect(page.locator('.home-page .video-grid .rank-badge')).toHaveCount(0);
      const link = page.locator('.video-card-link').first();
      await link.focus();
      await expect(link).toBeFocused();
      await page.emulateMedia({ reducedMotion: 'no-preference' });
      await page.locator('.shorts-card-link').first().hover();
      // A real hover must stay static even when motion is allowed.
      await page.waitForTimeout(150);
      await page.emulateMedia({ reducedMotion: 'reduce' });
      await expect(page.locator('.home-page video')).toHaveCount(0);
      expect(streams).toEqual([]);
      await expectContained(page, `home ${width}`);
      await page.evaluate(() => window.scrollTo(0, 0));
      await page.screenshot({ path: `/tmp/crozztube-home-${width}.png`, fullPage: true });
      expect(session.writes).toEqual([]);
      await session.goto(`/watch/${original().slug}`);
      await expect(page.locator('.watch-page h1')).toHaveText(title);
      expect(await page.locator('.watch-toolbar').evaluate(e => getComputedStyle(e).flexDirection)).toBe('row');
      expect((await page.locator('.watch-toolbar .channel-identity').boundingBox())?.height).toBeLessThan(100);
      await expect(page.locator('.watch-info .action-bar > button[aria-label]')).toHaveCount(3);
      await expect(page.locator('.watch-chapters')).toBeVisible();
      await page.locator('.watch-chapters summary').click();
      await expect(page.locator('.watch-chapters li')).toHaveCount(2);
      await expect(page.locator('.watch-chapters li').first()).toContainText('Home');
      await expect(page.locator('.watch-chapters li').nth(1)).toContainText('Settings');
      const order = await page.locator('.watch-page').evaluate(e => [...e.children].filter(c => /watch-(primary|sidebar|comments-section)/.test(c.className)).map(c => c.className));
      expect(order).toEqual(['watch-primary', 'watch-sidebar', 'watch-comments-section']);
      await expectContained(page, `watch ${width}`);
      await page.evaluate(() => window.scrollTo(0, 0));
      await page.screenshot({ path: `/tmp/crozztube-watch-${width}.png`, fullPage: true });
      expect(session.errors).toEqual([]);
      expect(session.writes.filter(w => !w.includes('/progress'))).toEqual([]);
    } finally { await session.context.close(); }
  });
}

test('native playback, chapters, captions and a comment draft survive resizing', async ({ browser }) => {
  const file = `/tmp/crozztube-viewer-${process.pid}.mp4`;
  let media: Buffer;
  try {
    execFileSync('docker', ['exec', 'crozztube-web', 'ffmpeg', '-hide_banner', '-loglevel', 'error', '-f', 'lavfi', '-i', 'color=c=black:s=320x180:r=10', '-t', '30', '-c:v', 'libx264', '-pix_fmt', 'yuv420p', '-an', '-movflags', '+faststart', '-y', file]);
    media = execFileSync('docker', ['exec', 'crozztube-web', 'cat', file]);
  } finally { execFileSync('docker', ['exec', 'crozztube-web', 'rm', '-f', file]); }
  const session = await layoutSession(browser, { language: 'de_de', width: 1440, transform });
  await session.context.route('**/fixtures/viewer.mp4', route => {
    const range = route.request().headers()['range']?.match(/bytes=(\d+)-(\d*)/);
    const start = range ? Number(range[1]) : 0, end = range?.[2] ? Math.min(Number(range[2]), media.length - 1) : media.length - 1;
    return route.fulfill({ status: range ? 206 : 200, contentType: 'video/mp4', headers: { 'Accept-Ranges': 'bytes', ...(range ? { 'Content-Range': `bytes ${start}-${end}/${media.length}` } : {}) }, body: media.subarray(start, end + 1) });
  });
  await session.context.route('**/fixtures/viewer.vtt', route => route.fulfill({ contentType: 'text/vtt', body: 'WEBVTT\n\n00:00.000 --> 00:30.000\nHome — Saved\n' }));
  await session.context.route('**/api/videos/*/progress', route => route.fulfill({ json: { saved: true } }));
  try {
    const { page } = session;
    await session.goto(`/watch/${original().slug}`);
    const mediaElement = page.locator('.watch-vidstack-player video');
    await expect(mediaElement).toBeVisible();
    await expect.poll(() => mediaElement.evaluate((v: HTMLVideoElement) => v.readyState)).toBeGreaterThan(1);
    // A deliberate chapter click seeks using the existing real player API.
    await page.locator('.watch-chapters summary').click();
    await page.locator('.watch-chapters li').nth(1).getByRole('button').click();
    await expect.poll(() => mediaElement.evaluate((v: HTMLVideoElement) => v.currentTime)).toBeGreaterThanOrEqual(5);
    await mediaElement.evaluate((v: HTMLVideoElement) => { v.pause(); v.dataset.resizeIdentity = 'retained'; });
    const composer = page.locator('.watch-comments-card').getByRole('textbox').first();
    await composer.fill('Mein offener Kommentar bleibt hier.');
    await expect(page.locator('.vds-captions')).toContainText('Home — Saved');
    for (const width of [390, 1024, 1440]) {
      await page.setViewportSize({ width, height: 900 });
      await expect(mediaElement).toHaveAttribute('data-resize-identity', 'retained');
      await expect(composer).toHaveValue('Mein offener Kommentar bleibt hier.');
      expect(await mediaElement.evaluate((v: HTMLVideoElement) => v.currentTime)).toBeGreaterThanOrEqual(5);
      await expectContained(page, `native player resized ${width}`);
    }
    expect(session.errors).toEqual([]);
    expect(session.writes).toEqual([]);
  } finally { await session.context.close(); }
});

test('queue, empty sections and card menus have clear keyboard paths', async ({ browser }) => {
  const items = applicationJson('/api/videos?type=video&per_page=10').data;
  const queued = { ...items[1], title: 'Home', duration: '1:00' };
  const session = await layoutSession(browser, { language: 'de_de', width: 390, transform });
  await session.context.addInitScript(item => localStorage.setItem('playhub_playback_queue_v1', JSON.stringify([item])), queued);
  try {
    const { page } = session;
    await session.goto('/');
    const card = page.locator('.video-card').first();
    const before = await card.boundingBox();
    await card.locator('.video-card-link').focus();
    await card.getByRole('button', { name: 'Videoaktionen', exact: true }).click();
    await page.keyboard.press('ArrowDown');
    await expect(card.getByRole('menuitem').first()).toBeFocused();
    await page.keyboard.press('Escape');
    await expect(card.getByRole('button', { name: 'Videoaktionen', exact: true })).toBeFocused();
    expect((await card.boundingBox())?.height).toBe(before?.height);
    const href = await card.locator('.video-card-link').getAttribute('href');
    await card.locator('.video-card-link').focus();
    await page.keyboard.press('Enter');
    await expect(page).toHaveURL(new RegExp(`${href}$`));
    await expect(page.locator('.watch-sidebar')).toContainText('Warteschlange');
    await expect(page.locator('.watch-sidebar .small-video-row').first().locator('strong')).toHaveText('Home');
    await expect(page.locator('.watch-sidebar .watch-up-next-label').last()).toHaveText('Videos entdecken');
    await expectContained(page, 'queued watch at 390');
    expect(session.errors).toEqual([]);
  } finally { await session.context.close(); }
  const empty = await layoutSession(browser, { language: 'de_de', width: 320, transform: (path, payload) => /\/api\/(videos\?|continue-watching|home\/categories)/.test(path) ? { ...payload, data: [] } : payload });
  try {
    await empty.goto('/');
    await expect(empty.page.locator('.home-empty-hero')).toBeVisible();
    await expect(empty.page.locator('.home-page > .content-section')).toHaveCount(0);
    await expectContained(empty.page, 'empty home');
    expect(empty.errors).toEqual([]);
  } finally { await empty.context.close(); }
});

for (const [browserName, engine] of Object.entries({ firefox, webkit })) {
  test(`${browserName}: responsive links, chapters and light/dark surfaces`, async () => {
    const endpoint = process.env.PW_WEBKIT_WS_ENDPOINT || process.env.PW_WEBKIT_WS;
    const browser = browserName === 'webkit' && endpoint ? await engine.connect(endpoint) : await engine.launch();
    try {
      for (const width of [390, 1440]) {
        const session = await layoutSession(browser, { language: 'de_de', width, dark: width === 1440, transform });
        try {
          await session.goto('/');
          await expect(session.page.locator('.continue-content')).toContainText('Noch 0:26');
          await expectContained(session.page, `${browserName} home ${width}`);
          await session.goto(`/watch/${original().slug}`);
          await expect(session.page.locator('.watch-chapters')).toBeVisible();
          await expectContained(session.page, `${browserName} watch ${width}`);
          expect(session.errors).toEqual([]);
        } finally { await session.context.close(); }
      }
    } finally { await browser.close(); }
  });
}

test('touch cards expose reachable actions and start playback only after a tap', async ({ browser }) => {
  const session = await layoutSession(browser, { language: 'de_de', width: 390, dark: true, touch: true, userAgent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148 Safari/604.1', transform });
  try {
    const { page } = session;
    await session.goto('/');
    const card = page.locator('.video-card').first(), more = card.getByRole('button', { name: 'Videoaktionen', exact: true });
    await expect(more).toBeVisible();
    expect((await more.boundingBox())?.width).toBeGreaterThanOrEqual(44);
    expect((await more.boundingBox())?.height).toBeGreaterThanOrEqual(44);
    await expect(page.locator('.home-page video')).toHaveCount(0);
    await expectContained(page, 'touch dark home');
    await card.locator('.video-card-link').tap();
    await expect(page.locator('.watch-chapters')).toBeVisible();
    await expectContained(page, 'touch dark watch');
    expect(session.errors).toEqual([]);
  } finally { await session.context.close(); }
});

test('hero, spotlight and discovery rows give feedback without layout shifts or previews', async ({ browser }) => {
  const session = await layoutSession(browser, { authenticated: false, language: 'de_de', width: 1915, height: 902 });
  try {
    const { page } = session;
    await session.goto('/');
    const primary = page.locator('.hero-buttons .btn.primary');
    const idle = await primary.evaluate(e => getComputedStyle(e).backgroundColor);
    const before = await primary.boundingBox();
    await primary.hover();
    await expect.poll(() => primary.evaluate(e => getComputedStyle(e).backgroundColor)).not.toBe(idle);
    expect(await primary.boundingBox()).toEqual(before);
    const spotlight = page.locator('.spotlight-card').first();
    const action = spotlight.locator('.spotlight-watch-action');
    await expect(action).toBeVisible();
    const base = await action.evaluate(e => getComputedStyle(e).backgroundColor);
    const cardBounds = await spotlight.boundingBox();
    await spotlight.hover();
    await expect.poll(() => action.evaluate(e => getComputedStyle(e).backgroundColor)).not.toBe(base);
    await spotlight.focus();
    await page.mouse.move(0, 0);
    await expect.poll(() => action.evaluate(e => getComputedStyle(e).backgroundColor)).not.toBe(base);
    expect(await spotlight.boundingBox()).toEqual(cardBounds);
    const thumb = page.locator('.video-card-link .thumb').first();
    const duration = thumb.locator('.duration');
    expect((await duration.boundingBox())!.x + (await duration.boundingBox())!.width).toBeLessThan((await thumb.boundingBox())!.x + (await thumb.boundingBox())!.width - 5);
    await expect(page.locator('.home-page video')).toHaveCount(0);
    await session.goto('/explore');
    const row = page.locator('.explore-feature-grid .small-video-row').first();
    const background = await row.evaluate(e => getComputedStyle(e).backgroundColor);
    await row.hover();
    await expect.poll(() => row.evaluate(e => getComputedStyle(e).backgroundColor)).not.toBe(background);
    await row.locator('.small-video-link').focus();
    await page.mouse.move(0, 0);
    await expect.poll(() => row.evaluate(e => getComputedStyle(e).backgroundColor)).not.toBe(background);
    expect(session.errors).toEqual([]);
    expect(session.writes).toEqual([]);
  } finally { await session.context.close(); }
});
