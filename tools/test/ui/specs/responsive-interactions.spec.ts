import { test, expect, type Locator, type Page } from '@playwright/test';
import { applicationJson, layoutSession, expectContained } from '../fixtures/readonly-layout';
import { catalogue } from '../fixtures/catalogue';
import { retainScreenshot } from '../fixtures/screenshots';

test.use({ trace: 'off', video: 'off' });

async function expectFocusedWithin(dialog: Locator) {
  await expect.poll(() => dialog.evaluate(element => element.contains(document.activeElement))).toBe(true);
}

async function expectScrollLocked(page: Page, locked: boolean) {
  await expect.poll(() => page.evaluate(() => {
    const body = getComputedStyle(document.body), root = getComputedStyle(document.documentElement);
    return body.overflowY === 'hidden' || root.overflowY === 'hidden' || body.position === 'fixed';
  })).toBe(locked);
}

for (const admin of [false, true]) {
  test(`${admin ? 'admin' : 'website'} navigation has one consistent breakpoint and preserves focus`, async ({ browser }) => {
    const session = await layoutSession(browser);
    const { page } = session;
    try {
      await session.goto(admin ? '/admin/users' : '/');
      const shell = page.locator(admin ? '.admin-dashboard-shell[data-navigation-mode]' : '.app-shell');
      const sidebar = page.locator(admin ? '.admin-sidebar' : '#website-sidebar');
      const trigger = page.locator(admin ? '.admin-mobile-menu-button' : '.topbar .menu-button');
      for (const width of [859, 860, 861, 1279, 1280, 1281]) {
        await page.setViewportSize({ width, height: 900 });
        await expect(shell).toHaveAttribute('data-navigation-mode', width < 1280 ? 'overlay' : 'docked');
        await expectContained(page, `closed ${width}px`);
        if (width < 1280) {
          await expect.poll(() => sidebar.evaluate(element => element.hasAttribute('inert') || element.getAttribute('aria-hidden') === 'true' || getComputedStyle(element).visibility === 'hidden')).toBe(true);
          await trigger.click();
          await expect(sidebar).toHaveAttribute('aria-modal', 'true');
          await expectFocusedWithin(sidebar);
          await expectScrollLocked(page, true);
          await page.keyboard.press('Shift+Tab');
          await expectFocusedWithin(sidebar);
          await page.keyboard.press('Tab');
          await expectFocusedWithin(sidebar);
          await page.keyboard.press('Escape');
          await expect(sidebar).not.toHaveAttribute('aria-modal', 'true');
          await expectScrollLocked(page, false);
          await expect(trigger).toBeFocused();
        } else {
          const bounds = await sidebar.boundingBox();
          expect(bounds?.x).toBeGreaterThanOrEqual(0);
          expect(bounds?.width).toBeGreaterThan(200);
        }
      }

      // Cross both legacy breakpoints while the drawer is open, then dock it.
      await page.setViewportSize({ width: 859, height: 900 });
      await trigger.click();
      for (const width of [860, 861, 1279]) {
        await page.setViewportSize({ width, height: 900 });
        await expect(sidebar).toHaveAttribute('aria-modal', 'true');
        await expectScrollLocked(page, true);
      }
      await page.setViewportSize({ width: 1280, height: 900 });
      await expect(shell).toHaveAttribute('data-navigation-mode', 'docked');
      await expect(sidebar).not.toHaveAttribute('aria-modal', 'true');
      await expectScrollLocked(page, false);
      await expect(page.locator(admin ? '.admin-mobile-sidebar-backdrop' : '.sidebar-backdrop')).toHaveCount(0);
      await page.setViewportSize({ width: 1279, height: 900 });
      await expect.poll(() => sidebar.evaluate(element => element.hasAttribute('inert') || element.getAttribute('aria-hidden') === 'true' || getComputedStyle(element).visibility === 'hidden')).toBe(true);
    } finally { await session.context.close(); }
  });
}

test('feed cards keep a readable width and do not jump at 1280/1281', async ({ browser }) => {
  const session = await layoutSession(browser);
  try {
    await session.goto('/');
    const { page } = session;
    const widths: number[] = [];
    for (const width of [320, 360, 768, 859, 860, 861, 1024, 1279, 1280, 1281, 1440, 1920, 2560]) {
      await page.setViewportSize({ width, height: 900 });
      const cards = page.locator('.video-grid > .video-card');
      await expect(cards.first()).toBeVisible();
      for (const card of await cards.all()) {
        expect((await card.boundingBox())!.width, `card width at ${width}px`).toBeGreaterThanOrEqual(239);
      }
      if (width === 1280 || width === 1281) widths.push((await cards.first().boundingBox())!.width);
      await expectContained(page, `feed ${width}px`);
    }
    expect(Math.abs(widths[1] - widths[0])).toBeLessThan(3);
  } finally { await session.context.close(); }
});

test('short-height admin form scrolls its own content and traps focus', async ({ browser }, testInfo) => {
  const session = await layoutSession(browser, { width: 360, height: 400 });
  const { page } = session;
  try {
    await session.goto('/admin/users');
    const trigger = page.getByRole('button', { name: 'Invite User', exact: true });
    await trigger.click();
    const dialog = page.getByRole('dialog');
    await expect(dialog).toBeVisible();
    await expectFocusedWithin(dialog);
    await expectScrollLocked(page, true);
    const before = await page.evaluate(() => scrollY);
    for (const viewport of [{ width: 360, height: 400 }, { width: 800, height: 360 }, { width: 390, height: 844 }]) {
      await page.setViewportSize(viewport);
      const submit = dialog.getByRole('button', { name: 'Invite user', exact: true });
      await submit.scrollIntoViewIfNeeded();
      const box = (await submit.boundingBox())!;
      expect(box.y).toBeGreaterThanOrEqual(0);
      expect(box.y + box.height).toBeLessThanOrEqual(viewport.height + 1);
      expect(await page.evaluate(() => scrollY)).toBe(before);
      await expectContained(page);
      if (viewport.width === 360) await retainScreenshot(page, testInfo, 'admin-invite-360x400');
    }
    await page.keyboard.press('Shift+Tab');
    await expectFocusedWithin(dialog);
    await page.keyboard.press('Escape');
    await expect(dialog).toHaveCount(0);
    await expectScrollLocked(page, false);
    await expect(trigger).toBeFocused();
    // Language opens from a menu item that is unmounted during the handoff.
    // Closing must restore the surviving account trigger, never the body.
    const account = page.locator('.admin-avatar-button');
    await account.click();
    await page.getByRole('menuitem', { name: 'Language', exact: true }).click();
    const language = page.locator('.language-dialog');
    await expect(language).toBeVisible();
    await expectFocusedWithin(language);
    await page.keyboard.press('Escape');
    await expect(language).toHaveCount(0);
    await expect(account).toBeFocused();
    await expectScrollLocked(page, false);
    expect(session.writes, 'opening/closing an invite must not send it').toEqual([]);
  } finally { await session.context.close(); }
});

test('settings switch between compact and desktop navigation without losing a draft', async ({ browser }) => {
  const session = await layoutSession(browser, { width: 360, height: 800 });
  const { page } = session;
  try {
    await session.goto('/settings');
    const selector = page.getByRole('combobox', { name: 'Settings section', exact: true });
    await expect(selector).toBeVisible();
    const name = page.getByPlaceholder('Your display name', { exact: true });
    const draft = 'Außergewöhnlich langer Anzeigename zur Größenprüfung';
    await name.fill(draft);
    await selector.selectOption('channel');
    await expect(page.locator('#settings-channel')).toBeVisible();
    await selector.selectOption('profile');
    await expect(name).toHaveValue(draft);
    for (const width of [768, 1024, 1440, 360]) {
      await page.setViewportSize({ width, height: 800 });
      await expect(name).toHaveValue(draft);
      await expectContained(page);
      if (width === 1440) {
        await expect(selector).toBeHidden();
        await expect(page.getByRole('complementary', { name: 'Settings sections' })).toBeVisible();
      }
    }
    expect(session.writes, 'drafts must stay local until saved').toEqual([]);
  } finally { await session.context.close(); }
});

test('German long content, dark appearance and 200% equivalent reflow stay usable', async ({ browser }) => {
  const longTitle = 'Außergewöhnlich ausführliche Videobeschreibung mit Bildschirmgrößenänderung und wiederhergestellter Wiedergabeposition '.repeat(3);
  const session = await layoutSession(browser, {
    width: 640, height: 450, dark: true, language: 'de',
    transform: (path, payload) => {
      if (path.startsWith('/api/videos')) {
        for (const video of payload.data || []) video.title = longTitle;
        if (payload.video) payload.video.title = longTitle;
      }
      return payload;
    },
  });
  try {
    await session.goto('/');
    await expect(session.page.locator('html')).toHaveAttribute('data-theme', 'dark');
    await expect(session.page.locator('.video-grid h3').first()).toContainText('Außergewöhnlich');
    await expectContained(session.page);
    // 640 CSS px is the reflow viewport of a 1280px desktop at 200% zoom.
    // This checks layout reflow; manual OS/browser zoom remains a separate pass.
    await session.page.setViewportSize({ width: 320, height: 640 });
    await expectContained(session.page);
    expect(session.errors).toEqual([]);
  } finally { await session.context.close(); }
});

test('empty results and recoverable GET errors preserve the shell', async ({ browser }) => {
  for (const state of ['empty', 'error']) {
    const session = await layoutSession(browser, { width: 360, height: 800 });
    try {
      await session.page.route('**/api/videos?**', route => route.fulfill({
        status: state === 'error' ? 503 : 200,
        contentType: 'application/json',
        body: state === 'error' ? '{"message":"Temporarily unavailable"}' : '{"data":[],"meta":{"total":0}}',
      }));
      await session.goto('/search?q=missing');
      await expectContained(session.page, state);
      await expect(session.page.locator('.app-shell')).toHaveAttribute('data-navigation-mode', 'overlay');
      expect(session.errors).toEqual([]);
    } finally { await session.context.close(); }
  }
});

test('an existing minimized player keeps its media element when resized to mobile and back', async ({ browser }, testInfo) => {
  const session = await layoutSession(browser);
  try {
    await session.goto('/explore');
    const video = applicationJson(`/api/videos/${catalogue().video}/playback-session`).video;
    const user = applicationJson('/api/auth/me').user;
    await session.page.evaluate(({ video, accountId }) => {
      // The normal cross-route handoff event, carrying real playback metadata.
      // Bytes stay blocked by the layout fixture; real progress has separate tests.
      window.dispatchEvent(new CustomEvent('playhub:persistent-playback', { detail: {
        session: { version: 1, video, account_id: accountId, current_time: 30, duration: video.duration_seconds,
          queue: [], history: [], playing: false, muted: true, volume: 0, updated_at: Date.now() },
        video,
      } }));
    }, { video, accountId: user.id });
    const minimized = session.page.getByRole('complementary', { name: 'Minimized video player' });
    const media = minimized.locator('.persistent-vidstack-player');
    await expect(minimized).toBeVisible();
    await expect(media).toBeAttached();
    await media.evaluate(element => { element.setAttribute('data-resize-identity', 'preserved'); });
    for (const width of [1024, 861, 860, 390, 320, 1280, 1440]) {
      await session.page.setViewportSize({ width, height: 900 });
      await expect(minimized).toBeVisible();
      await expect(media).toHaveAttribute('data-resize-identity', 'preserved');
      await expect.poll(() => minimized.evaluate(element => {
        const rect = element.getBoundingClientRect();
        const nav = document.querySelector('.mobile-nav');
        const navRect = nav && getComputedStyle(nav).display !== 'none' ? nav.getBoundingClientRect() : null;
        const bottom = navRect && navRect.height > 0 ? Math.min(innerHeight, navRect.y) : innerHeight;
        return rect.x >= -1 && rect.y >= -1 && rect.right <= innerWidth + 1 && rect.bottom <= bottom + 1;
      }), `minimized player bounds at ${width}px`).toBe(true);
      if (width === 390 || width === 1440) await retainScreenshot(session.page, testInfo, `minimized-player-${width}`);
    }
    expect(session.errors).toEqual([]);
  } finally { await session.context.close(); }
});

test('touch navigation and a cancelled edge drag leave the next tap and filter scroll usable', async ({ browser }) => {
  const session = await layoutSession(browser, { width: 390, height: 844, touch: true });
  const { page } = session;
  try {
    await session.goto('/');
    const trigger = page.locator('.topbar .menu-button');
    const sidebar = page.locator('#website-sidebar');
    await trigger.tap();
    await expect(sidebar).toHaveAttribute('aria-modal', 'true');
    await sidebar.getByRole('button', { name: 'Close navigation', exact: true }).tap();
    await expectScrollLocked(page, false);

    // Chromium's real touch pipeline produces pointer capture/cancel, rather
    // than dispatching synthetic JS pointer events which bypass browser gestures.
    const cdp = await session.context.newCDPSession(page);
    await cdp.send('Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: [{ x: 3, y: 250, id: 1 }] });
    await expect(page.locator('.app-shell')).toHaveClass(/sidebar-dragging/);
    await cdp.send('Input.dispatchTouchEvent', { type: 'touchMove', touchPoints: [{ x: 145, y: 250, id: 1 }] });
    await expect.poll(() => page.locator('.app-shell').evaluate(element => parseFloat((element as HTMLElement).style.getPropertyValue('--sidebar-drag-width')))).toBeGreaterThan(100);
    await cdp.send('Input.dispatchTouchEvent', { type: 'touchCancel', touchPoints: [] });
    await expect(page.locator('.app-shell')).not.toHaveClass(/sidebar-dragging/);
    await expectScrollLocked(page, false);
    await trigger.tap();
    await sidebar.getByRole('button', { name: 'Explore', exact: true }).tap();
    await expect(page).toHaveURL(/\/explore$/);
    await expectScrollLocked(page, false);

    await session.goto('/watch-later');
    const filters = page.locator('.filter-bar > .filter-chips').first();
    await filters.scrollIntoViewIfNeeded();
    const activeBefore = await filters.locator('.chip.active').innerText();
    const bounds = (await filters.boundingBox())!;
    const y = Math.round(bounds.y + bounds.height / 2);
    const right = Math.min(350, Math.round(bounds.x + bounds.width - 14));
    await cdp.send('Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: [{ x: right, y, id: 2 }] });
    for (const x of [right - 35, right - 90, right - 150]) {
      await cdp.send('Input.dispatchTouchEvent', { type: 'touchMove', touchPoints: [{ x, y, id: 2 }] });
    }
    await cdp.send('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] });
    await expect.poll(() => filters.evaluate(element => element.scrollLeft)).toBeGreaterThan(20);
    await expect(filters.locator('.chip.active')).toHaveText(activeBefore);
    await expectScrollLocked(page, false);
    await expectContained(page);
    await cdp.detach();
    expect(session.writes).toEqual([]);
  } finally { await session.context.close(); }
});

test('opening scrolled navigation keeps the sticky header and drawer aligned', async ({ browser }) => {
  const session = await layoutSession(browser, { authenticated: false, width: 924, height: 902 });
  try {
    const { page } = session;
    await session.goto('/');
    for (const width of [390, 860, 861, 924, 1279]) {
      await page.setViewportSize({ width, height: 902 });
      await page.evaluate(() => window.scrollTo(0, 54));
      const trigger = page.locator('.topbar .menu-button');
      const header = page.locator('.topbar');
      const before = await page.evaluate(() => scrollY);
      await trigger.click();
      const drawer = page.locator('#website-sidebar');
      await expect(drawer).toHaveAttribute('aria-modal', 'true');
      await expectFocusedWithin(drawer);
      await expectScrollLocked(page, true);
      await expect.poll(() => header.evaluate(e => Math.abs(e.getBoundingClientRect().top))).toBeLessThan(1);
      const bounds = await drawer.boundingBox();
      expect(bounds!.y).toBeCloseTo((await header.boundingBox())!.height, 0);
      expect(bounds!.y + bounds!.height).toBeLessThanOrEqual(903);
      const backdrop = await page.locator('.sidebar-backdrop').boundingBox();
      expect(backdrop!.y).toBeCloseTo(bounds!.y, 0);
      const headerText = await page.locator('.sidebar-drawer-head').boundingBox();
      expect(headerText!.y).toBeCloseTo(bounds!.y, 0);
      await page.mouse.move(width - 30, 300);
      await page.mouse.wheel(0, 300);
      expect(await page.evaluate(() => scrollY)).toBe(before);
      // The drawer can still scroll its own links and legal footer.
      await drawer.locator('.side-scroll').evaluate(e => { e.scrollTop = e.scrollHeight; });
      expect(await drawer.locator('.side-scroll').evaluate(e => e.scrollTop)).toBeGreaterThan(0);
      expect((await page.locator('.sidebar-drawer-head').boundingBox())!.y).toBeCloseTo(bounds!.y, 0);
      if (width === 924) await page.screenshot({ path: '/tmp/crozztube-navigation-924-fixed.png' });
      await page.keyboard.press('Escape');
      await expect(trigger).toBeFocused();
      await expectScrollLocked(page, false);
      expect(await page.evaluate(() => scrollY)).toBe(before);
      await expectContained(page, `navigation after closing at ${width}`);
    }
    expect(session.errors).toEqual([]);
    expect(session.writes).toEqual([]);
  } finally { await session.context.close(); }
});

test('navigation stays aligned after profile tip/back and video comment jumps', async ({ browser }) => {
  const video = applicationJson('/api/videos?type=video&per_page=1', false).data[0];
  const session = await layoutSession(browser, { authenticated: false, width: 924, height: 902, transform: (_path, payload) => payload.video ? { ...payload, video: { ...payload.video, source_type: 'upload', import_provider: null, video_url: null, video_sources: [], embed_url: null, playback_pending: false } } : payload });
  try {
    const { page } = session;
    const openAndCheck = async (name: string) => {
      await page.locator('.topbar .menu-button').click();
      const drawer = page.locator('#website-sidebar');
      await expect(drawer).toHaveAttribute('aria-modal', 'true');
      await expect.poll(() => page.locator('.topbar').evaluate(e => Math.abs(e.getBoundingClientRect().top))).toBeLessThan(1);
      expect((await drawer.boundingBox())!.y).toBeCloseTo((await page.locator('.topbar').boundingBox())!.height, 0);
      await expectFocusedWithin(drawer);
      await page.screenshot({ path: `/tmp/crozztube-navigation-after-${name}.png` });
      await page.keyboard.press('Escape');
    };
    await session.goto(`/channel/${video.channel.slug}`);
    await page.locator('.profile-tip-button').click();
    await expect(page).toHaveURL(/\/login$/);
    await page.goBack();
    await expect(page).toHaveURL(new RegExp(`/channel/${video.channel.slug}$`));
    await expect(page.locator('.profile-tip-button')).toBeVisible();
    await openAndCheck('tip-back');

    await session.goto(`/watch/${video.slug}`);
    await page.locator('.watch-info').getByRole('button', { name: 'Comments', exact: true }).click();
    await expect.poll(() => page.evaluate(() => scrollY)).toBeGreaterThan(100);
    await openAndCheck('comments');
    // Reopening once more must not accumulate a second offset or lock.
    await openAndCheck('comments-reopened');
    expect(session.errors).toEqual([]);
    expect(session.writes).toEqual([]);
  } finally { await session.context.close(); }
});
