import fs from 'node:fs';
import { chromium, expect } from '@playwright/test';
import { applicationJson, layoutSession, expectContained } from './fixtures/readonly-layout.ts';
const video = applicationJson('/api/videos?type=video&per_page=1', false).data[0];
const artifacts = 'tools/test/.artifacts/action-consistency'; fs.mkdirSync(artifacts, { recursive: true });
const browser = await chromium.launch({ headless: true }); let checks = 0;
const contrast = style => {
  const luminance = color => {
    const channels = color.match(/[\d.]+/g).slice(0, 3).map(Number).map(v => color.startsWith('color(srgb') ? v : v / 255);
    const linear = channels.map(v => v <= .04045 ? v / 12.92 : ((v + .055) / 1.055) ** 2.4);
    return linear[0] * .2126 + linear[1] * .7152 + linear[2] * .0722;
  };
  const [a,b] = [luminance(style.color),luminance(style.bg)].sort((a,b)=>a-b);
  return (b+.05)/(a+.05);
};
try {
  for (const [language, dark, width] of [['en', false, 1915], ['de_de', true, 924], ['de_de', false, 390]]) {
    const session = await layoutSession(browser, { language, dark, width, touch: width < 600, strictHttps: true,
      transform: (_path, payload) => payload.video ? { ...payload, video: { ...payload.video, source_type: 'upload', import_provider: null, video_url: '/fixture/menu.mp4', video_sources: [{ src: '/fixture/menu.mp4', type: 'video/mp4' }], embed_url: null, playback_pending: false } } : payload });
    try {
      const { page, context } = session; page.setDefaultTimeout(12000);
      await context.route('**/api/source-resolver/status/**', route => route.fulfill({ json: { source: { state: 'unavailable', has_direct: false, refreshing: false, fresh: false, auto_refresh_allowed: false, mirror: null } } }));
      await context.addInitScript(() => {
        globalThis.fixtureCopies = []; globalThis.fixtureShares = [];
        Object.defineProperty(navigator, 'clipboard', { configurable: true, value: { writeText: async text => { globalThis.fixtureCopies.push(text); } } });
        Object.defineProperty(navigator, 'share', { configurable: true, value: async data => { globalThis.fixtureShares.push(data); } });
      });
      await session.goto('/studio');
      const link = page.locator('.recent-comments-card .studio-card-head button');
      await expect(link).toBeVisible();
      expect(await link.locator('span').count()).toBe(0);
      expect(await link.evaluate(n => getComputedStyle(n, '::after').content)).toBe('"→"'); checks += 3;
      await session.goto('/admin/roles-permissions');
      const pagination = page.locator('.admin-pagination').first();
      const selected = pagination.locator('button.active'); await expect(selected).toBeVisible();
      await expect(selected).toHaveAttribute('aria-current','page');
      await expect(pagination.locator('button').first().locator('svg path')).toHaveAttribute('d','m15 6-6 6 6 6'); checks += 2;
      const style = await selected.evaluate(n => {const s=getComputedStyle(n),shell=n.closest('.admin-dashboard-shell');return {image:s.backgroundImage,color:s.color,ink:getComputedStyle(shell).getPropertyValue('--ad-primary-ink').trim(),bg:s.backgroundColor};});
      expect(style.image).toBe('none'); expect(contrast(style)).toBeGreaterThanOrEqual(4.5); checks++;
      const initial = await selected.evaluate(n => getComputedStyle(n).backgroundColor);
      expect((await selected.boundingBox()).height).toBeGreaterThanOrEqual(width < 600 ? 44 : 36); checks++;
      await selected.hover(); expect(await selected.evaluate(n => getComputedStyle(n).backgroundColor)).toBe(initial);
      await selected.focus(); expect(await selected.evaluate(n => getComputedStyle(n).outlineStyle)).not.toBe('none'); checks += 4;
      for (const button of await page.locator('.admin-action-button.primary').all()) {
        const appearance = await button.evaluate(n=>{const s=getComputedStyle(n);return {image:s.backgroundImage,color:s.color,bg:s.backgroundColor};});
        expect(appearance.image).toBe('none'); expect(appearance.bg).toBe(initial); expect(contrast(appearance)).toBeGreaterThanOrEqual(4.5); checks += 3;
      }
      await expectContained(page, `admin pagination ${width} ${language}`);
      await page.screenshot({ path: `${artifacts}/pagination-${width}-${dark ? 'dark' : 'light'}.png`, fullPage: true }); checks++;
      await session.goto(`/watch/${video.slug}`);
      await page.locator('.watch-vidstack-player').evaluate(n => { n.dataset.menuFixture = 'preserved'; });
      const draft = page.locator('.watch-comments-card .comment-composer-field > input').first();
      await draft.fill('Mein unveröffentlichter Kommentar');
      const more = page.getByRole('button', { name: language === 'en' ? 'Video actions' : 'Videoaktionen', exact: true });
      await more.scrollIntoViewIfNeeded(); await more.click();
      const menu = page.locator('body > .watch-detail-menu'); await expect(menu).toBeVisible();
      const manage = menu.getByRole('menuitem', { name: language === 'en' ? 'Manage video' : 'Video verwalten', exact: true });
      await expect(manage).toBeVisible();
      await expect(menu.getByRole('menuitem', { name: /^(Share video|Video teilen|Copy Link|Link kopieren|.*Watch later.*|.*Später ansehen.*)$/i })).toHaveCount(0); checks += 3;
      await manage.focus(); await page.keyboard.press('ArrowRight');
      await expect(menu).toHaveAttribute('aria-label', language === 'en' ? 'Manage video' : 'Video verwalten');
      const back = menu.getByRole('menuitem', { name: language === 'en' ? 'Back' : 'Zurück', exact: true }); await expect(back).toBeFocused();
      await expect(menu.getByRole('menuitem', { name: language === 'en' ? 'Edit video' : 'Video bearbeiten', exact: true })).toBeVisible(); checks += 3;
      await page.keyboard.press('Escape'); await expect(manage).toBeFocused();
      await page.keyboard.press('Escape'); await expect(menu).toHaveCount(0); await expect(more).toBeFocused(); checks += 3;
      const share = page.locator('.watch-toolbar').getByRole('button', { name: language === 'en' ? 'Share video' : 'Video teilen', exact: true });
      await expect(page.locator('.watch-toolbar .video-detail-menu-shell')).toHaveCount(1); checks++;
      await share.click(); await expect(menu.getByRole('menuitem')).toHaveCount(2);
      await menu.getByRole('menuitem', { name: language === 'en' ? 'Copy Link' : 'Link kopieren', exact: true }).click();
      expect(await page.evaluate(() => globalThis.fixtureCopies.length)).toBe(1); await expect(share).toBeFocused(); checks += 3;
      await share.click(); await menu.getByRole('menuitem', { name: language === 'en' ? 'Share video' : 'Video teilen', exact: true }).click();
      expect(await page.evaluate(() => globalThis.fixtureShares.length)).toBe(1); await expect(share).toBeFocused(); checks += 2;
      await more.click(); await manage.click();
      for (const w of [width, 390, 924]) {
        await page.setViewportSize({ width: w, height: 902 });
        await expectContained(page, `watch menu ${w} ${language}`);
        await expect(page.locator('.watch-vidstack-player')).toHaveAttribute('data-menu-fixture', 'preserved');
        await expect(draft).toHaveValue('Mein unveröffentlichter Kommentar'); checks += 2;
        expect(await menu.evaluate(n=>n.getBoundingClientRect().top >= (document.querySelector('.topbar')?.getBoundingClientRect().bottom||0))).toBe(true); checks += 2;
      }
      await page.setViewportSize({ width, height: 902 });
      await expectContained(page, 'management capture');
      await page.screenshot({ path: `${artifacts}/manage-${width}-${dark ? 'dark' : 'light'}.png`, fullPage: true });
      await page.keyboard.press('ArrowLeft'); await expect(manage).toBeFocused(); checks++;
      expect(session.writes).toEqual([]); expect(session.errors).toEqual([]); expect(session.failedGets).toEqual([]); checks += 3;
    } finally { await session.context.close(); }
  }
  for (const role of ['guest', 'viewer', 'admin']) {
    const session = await layoutSession(browser, { authenticated: role !== 'guest', width: 924, strictHttps: true,
      transform: (_path, payload) => _path.endsWith('/viewer-state') ? { ...payload, can_report: role === 'viewer', reported: false } : payload.video ? { ...payload, video: { ...payload.video,
        channel: { ...payload.video.channel, user_id: 999999 }, can_report: role === 'viewer',
      } } : role === 'viewer' && _path.startsWith('/api/auth/me') ? { ...payload, user: { ...payload.user,
        settings: { ...payload.user.settings, role: 'user', account_role: 'user', admin: false, is_admin: false },
      } } : payload });
    try {
      const { page, context } = session;
      await context.route('**/api/source-resolver/status/**', route => route.fulfill({ json: { source: { state: 'unavailable', has_direct: false, refreshing: false, fresh: false, auto_refresh_allowed: false, mirror: null } } }));
      await session.goto(`/watch/${video.slug}`);
      await page.getByRole('button', { name: 'Video actions', exact: true }).click();
      const menu = page.locator('body > .watch-detail-menu');
      await expect(menu.getByRole('menuitem', { name: 'Report video', exact: true })).toHaveCount(role === 'viewer' ? 1 : 0); checks++;
      if (role === 'admin') {
        await menu.getByRole('menuitem', { name: 'Manage video', exact: true }).click();
        await expect(menu.getByRole('menuitem', { name: 'Delete video', exact: true })).toBeVisible();
        await expect(menu.getByRole('menuitem', { name: /^(Edit video|Boost video|Pin to profile)$/ })).toHaveCount(0); checks += 2;
      } else {
        await expect(menu.getByRole('menuitem', { name: 'Manage video', exact: true })).toHaveCount(0);
        await expect(menu.getByRole('menuitem', { name: 'Delete video', exact: true })).toHaveCount(0); checks += 2;
        if (role === 'guest') {
          await menu.getByRole('menuitem', { name: 'Add to Playlist', exact: true }).click();
          await expect(page).toHaveURL(/\/login$/); checks++;
        }
      }
      expect(session.writes).toEqual([]); expect(session.errors).toEqual([]); checks += 2;
    } finally { await session.context.close(); }
  }
  console.log(`${checks} action consistency browser checks passed. Studio arrow, pagination states, owner menu, sharing, keyboard and resizing; no real writes or media.`);
} finally { await browser.close(); }
