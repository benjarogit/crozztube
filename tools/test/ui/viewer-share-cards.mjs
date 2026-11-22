import { readFile } from 'node:fs/promises';
import path from 'node:path';
import { chromium, expect } from '@playwright/test';
import { applicationJson, layoutSession, expectContained } from './fixtures/readonly-layout.ts';

// Use an isolated candidate build over the existing read-only Laravel fixture.
const build = process.env.VIEWER_TEST_BUILD;
if (!build) throw new Error('Set VIEWER_TEST_BUILD to the isolated Vite output directory.');
const manifest = JSON.parse(await readFile(path.join(build, 'manifest.json'), 'utf8'));
const entry = manifest['resources/js/main.jsx'];
const video = applicationJson('/api/videos?type=video&per_page=1', false).data[0];
const browser = await chromium.launch({ headless: true });

try {
  const catalogFixture = Array.from({ length: 3 }, (_, index) => ({
    ...video, id: 990001 + index, slug: 'layout-example-' + index,
    title: 'A longer video title for checking card spacing and wrapping ' + (index + 1),
  }));
  for (const width of [390, 1440]) for (const dark of [false, true]) {
    const session = await layoutSession(browser, {
      authenticated: true, width, dark, touch: width === 390, language: 'en', strictHttps: true,
      respond: url => {
        if (url.startsWith('/api/live-streams?')) {
          const streams = catalogFixture.map((item, index) => ({
            ...item, status: index === 0 ? 'live' : 'replay', creator: item.channel,
            ended_at: '2026-11-01T12:00:00Z', replay: { duration_seconds: 540 },
          }));
          return { status: 200, body: JSON.stringify({ streams }) };
        }
        if (url.startsWith('/api/videos?type=video&sort=recommended&per_page=24') || url.startsWith('/api/watch-later?')) {
          return { status: 200, body: JSON.stringify({ data: catalogFixture }) };
        }
      },
      transform: (url, data) => url.startsWith('/api/continue-watching')
        ? { ...data, data: [{ ...video, progress: 40, duration_seconds: 600, progress_seconds: 240 }] }
        : url === '/api/integrations/public'
        ? { ...data, features: { ...data.features, live_streaming: { enabled: true } } }
        : data.video ? { ...data, video: { ...data.video, source_type: 'upload',
        import_provider: null, video_url: '/fixture/menu.mp4', video_sources: [{ src: '/fixture/menu.mp4', type: 'video/mp4' }],
        embed_url: null, playback_pending: false } } : data,
    });
    const { context, page } = session;
    page.setDefaultTimeout(10000);
    const goto = route => page.goto(route, { waitUntil: 'domcontentloaded' });
    try {
      await context.route('**/build/assets/**', async route => {
        const name = path.basename(new URL(route.request().url()).pathname);
        try {
          await route.fulfill({ path: path.join(build, 'assets', name) });
        } catch (error) {
          if (error.code !== 'ENOENT' && !String(error).includes('ENOENT')) throw error;
          await route.fallback();
        }
      });
      await context.route(url => ['/', '/watch-later', '/live'].includes(url.pathname) || url.pathname.startsWith('/watch/'), async route => {
        if (!route.request().isNavigationRequest()) return route.fallback();
        const response = await route.fetch({ maxRedirects: 0 });
        const source = await response.text();
        const body = source.replace(/\/build\/assets\/main-[\w-]+\.js/g, '/build/' + entry.file)
          .replace(/\/build\/assets\/main-[\w-]+\.css/g, '/build/' + entry.css[0]);
        if (!body.includes('/build/' + entry.file)) throw new Error('Candidate frontend entry missing from page.');
        await route.fulfill({ response, body });
      });
      await context.addInitScript(() => {
        globalThis.shareFixture = { copies: [], shares: [], mode: 'success', clipboardFails: false };
        Object.defineProperty(navigator, 'share', { configurable: true, value: globalThis.fixtureNativeShare });
        Object.defineProperty(navigator, 'clipboard', { configurable: true, value: { writeText: async url => {
          if (shareFixture.clipboardFails) throw new Error('Clipboard denied');
          shareFixture.copies.push(url);
        } } });
      });
      const watch = '/watch/' + video.slug;
      await goto(watch);
      const toolbar = page.locator('.watch-toolbar');
      const toast = page.locator('.watch-player-toast');
      const copy = toolbar.getByRole('button', { name: 'Copy Link', exact: true });
      const menu = page.locator('.watch-detail-menu');
      await expect(page.locator('html')).toHaveAttribute('data-theme', dark ? 'dark' : 'light');
      await expect(copy).toBeVisible();
      await expect(toolbar.getByRole('button', { name: /^Share video$/i })).toHaveCount(0);
      await copy.click();
      await expect(menu).toHaveCount(0);
      await expect(toast).toContainText('Video link copied.');
      expect(await page.evaluate(() => shareFixture.copies)).toEqual([new URL(watch, page.url()).href]);
      await page.evaluate(() => { shareFixture.clipboardFails = true; });
      await copy.click();
      await expect(toast).toContainText('Unable to copy video link.');

      await context.addInitScript(() => {
        const nativeShare = async data => {
          shareFixture.shares.push(data);
          if (shareFixture.mode === 'abort') throw new DOMException('Cancelled', 'AbortError');
          if (shareFixture.mode === 'failure') throw new DOMException('Denied', 'NotAllowedError');
        };
        globalThis.fixtureNativeShare = nativeShare;
        Object.defineProperty(navigator, 'share', { configurable: true, value: nativeShare });
      });
      await goto(watch);
      const share = toolbar.getByRole('button', { name: /^Share video$/i });
      const openNative = async () => {
        await share.click();
        await expect(menu.getByRole('menuitem')).toHaveCount(2);
        await menu.getByRole('menuitem', { name: /^Share video$/i }).click();
        await expect(share).toBeFocused();
      };
      await page.evaluate(() => { shareFixture.mode = 'abort'; });
      await openNative();
      await expect(toast).toHaveCount(0);
      expect(await page.evaluate(() => shareFixture.copies)).toEqual([]);
      await page.evaluate(() => { shareFixture.mode = 'success'; });
      await openNative();
      await expect(toast).toContainText('Video shared.');
      expect(await page.evaluate(() => shareFixture.shares.length)).toBe(2);
      await page.evaluate(() => { shareFixture.mode = 'failure'; });
      await openNative();
      await expect(toast).toContainText('Sharing is not available, link copied instead.');
      await page.evaluate(() => { shareFixture.clipboardFails = true; });
      await openNative();
      await expect(toast).toContainText('Unable to copy video link.');
      await page.evaluate(() => {
        Object.defineProperty(navigator, 'clipboard', { configurable: true, value: undefined });
        document.execCommand = () => false;
      });
      await openNative();
      await expect(toast).toContainText('Unable to copy video link.');
      await expectContained(page, 'watch toolbar');
      await goto('/');
      const grid = page.locator('.home-page .video-grid').filter({ has: page.locator('.video-card') }).first();
      const continuing = page.locator('.home-page .continue-grid');
      await expect(grid).toBeVisible();
      await expect(continuing).toBeVisible();
      const metrics = node => {
        const style = getComputedStyle(node);
        return { row: style.rowGap, column: style.columnGap, tracks: style.gridTemplateColumns };
      };
      expect(await continuing.evaluate(metrics)).toEqual(await grid.evaluate(metrics));
      expect(await grid.evaluate(metrics)).toMatchObject({ row: '24px', column: '16px' });
      const titleStyle = node => {
        const style = getComputedStyle(node);
        return [style.fontSize, style.fontWeight, style.letterSpacing, style.lineHeight];
      };
      const continuingTitle = await continuing.locator('.continue-video-link strong').first().evaluate(titleStyle);
      expect(continuingTitle).toEqual(await grid.locator('.video-card-link h3').first().evaluate(titleStyle));
      await expectContained(page, 'home card grids');
      const gridStyle = await grid.evaluate(metrics);
      if (process.env.VIEWER_TEST_SHOTS) {
        await grid.scrollIntoViewIfNeeded();
        await page.screenshot({ path: path.join(process.env.VIEWER_TEST_SHOTS, `cards-${width}-${dark ? 'dark' : 'light'}.png`) });
      }
      await goto('/watch-later');
      const saved = page.locator('.watch-later-continue-row');
      await expect(saved.locator('.continue-wide-card')).toHaveCount(catalogFixture.length);
      expect(await saved.evaluate(metrics)).toMatchObject({ row: gridStyle.row, column: gridStyle.column });
      expect(await saved.locator('.continue-wide-card > strong').first().evaluate(titleStyle))
        .toEqual(continuingTitle);
      await expectContained(page, 'watch later card grid');
      await goto('/live');
      for (const selector of ['.live-now-grid', '.live-replay-grid']) {
        const liveGrid = page.locator(selector);
        await expect(liveGrid.locator('article').first()).toBeVisible();
        expect(await liveGrid.evaluate(metrics)).toMatchObject({ row: gridStyle.row, column: gridStyle.column });
        expect(await liveGrid.locator('h3').first().evaluate(titleStyle)).toEqual(continuingTitle);
        await expectContained(page, selector + ' video grid');
      }
      expect(session.writes).toEqual([]);
      expect(session.errors).toEqual([]);
      console.log(`PASS share and card grids ${width}px ${dark ? 'dark' : 'light'}`);
    } finally { await context.close(); }
  }
} finally { await browser.close(); }
