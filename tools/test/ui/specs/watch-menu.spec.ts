import { test, expect } from '@playwright/test';
import { applicationJson, layoutSession } from '../fixtures/readonly-layout';
test.use({ trace: 'off', video: 'off' });
for (const [width, height, touch] of [[924, 902, false], [924, 420, false], [390, 844, true]] as const) {
  test(`watch actions stay reachable below navigation at ${width}x${height}`, async ({ browser }) => {
    const slug = applicationJson('/api/videos?type=video&per_page=1', false).data[0].slug;
    const session = await layoutSession(browser, { authenticated: false, width, height, touch, transform: (_path, payload) => payload.video ? { ...payload, video: { ...payload.video, source_type: 'upload', import_provider: null, video_url: '/fixture/menu.mp4', video_sources: [{ src: '/fixture/menu.mp4', type: 'video/mp4' }], embed_url: null, playback_pending: false } } : payload });
    try {
      const { page, context } = session;
      await context.route('**/api/source-resolver/status/**', route => route.fulfill({ json: { source: { state: 'unavailable', has_direct: false, refreshing: false, fresh: false, auto_refresh_allowed: false, mirror: null } } }));
      await session.goto(`/watch/${slug}`);
      const trigger = page.getByRole('button', { name: 'Video actions', exact: true });
      await trigger.scrollIntoViewIfNeeded();
      if (width === 924 && height === 902) await trigger.evaluate(e => window.scrollBy(0, e.getBoundingClientRect().top - 450));
      await trigger.click();
      const menu = page.locator('body > .watch-detail-menu');
      await expect(menu).toBeVisible();
      await expect.poll(() => menu.evaluate(e => e.getBoundingClientRect().top >= (document.querySelector('.topbar')?.getBoundingClientRect().bottom || 0) + 7)).toBe(true);
      await expect.poll(() => menu.evaluate(e => e.getBoundingClientRect().bottom <= window.innerHeight + 1)).toBe(true);
      const bounds = await menu.boundingBox();
      expect(bounds!.x).toBeGreaterThanOrEqual(0);
      expect(bounds!.x + bounds!.width).toBeLessThanOrEqual(width);
      expect(bounds!.y + bounds!.height).toBeLessThanOrEqual(height + 1);
      await expect(menu.getByRole('menuitem').first()).toBeFocused();
      await page.keyboard.press('End');
      const last = menu.getByRole('menuitem').last();
      await expect(last).toBeFocused();
      expect(await last.evaluate(e => { const r = e.getBoundingClientRect(); return document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2) === e || e.contains(document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2)); })).toBe(true);
      await page.keyboard.press('Home');
      const first = menu.getByRole('menuitem').first();
      await expect(first).toBeFocused();
      expect(await first.evaluate(e => { const r = e.getBoundingClientRect(); return e.contains(document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2)); })).toBe(true);
      await page.screenshot({ path: `/tmp/crozztube-watch-menu-${width}x${height}.png` });
      await page.keyboard.press('Escape');
      await expect(menu).toHaveCount(0);
      await expect(trigger).toBeFocused();
      // A click within the portal must reach its handler, rather than outside-dismiss first.
      await trigger.click();
      await menu.getByRole('menuitem', { name: 'Add to Playlist', exact: true }).click();
      await expect(page).toHaveURL(/\/login$/);
      expect(session.errors).toEqual([]);
      expect(session.writes).toEqual([]);
    } finally { await session.context.close(); }
  });
}
