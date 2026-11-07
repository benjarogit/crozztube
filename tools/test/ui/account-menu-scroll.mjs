import assert from 'node:assert/strict';
import { chromium, expect } from '@playwright/test';
import { layoutSession } from './fixtures/readonly-layout.ts';

// One browser, native wheel/touch input and isolated API GETs; no account changes.
const browser = await chromium.launch({ headless: true });
let checks = 0;
const check = (condition, message) => { assert(condition, message); checks++; };
try {
  for (const width of [924, 963, 1279]) {
    const session = await layoutSession(browser, { width, height: 902, strictHttps: true, touch: width === 963, dark: width === 963 });
    try {
      const { page, context } = session;
      await session.goto('/communities');
      const nav = page.locator('#website-sidebar');
      const trigger = page.locator('.topbar .menu-button');
      const menu = page.locator('.topbar-account-menu.rich');
      await page.evaluate(() => window.scrollTo(0, 140));
      const beforePage = await page.evaluate(() => scrollY);
      await page.locator('.topbar .avatar-button').click();
      await expect(menu).toBeVisible();
      // Keyboard activation doesn't emit the outside pointerdown which usually
      // closes a header popup. It reproduces the real overlapping-menu state.
      await trigger.focus();
      await page.keyboard.press('Enter');
      await expect(nav).toHaveAttribute('aria-modal', 'true');
      await expect(menu).toHaveAttribute('aria-modal', 'true'); checks++;
      await expect.poll(() => menu.evaluate(e => e.contains(document.activeElement) && !e.closest('[inert]'))).toBe(true); checks++;
      const box = await menu.boundingBox();
      check(box.x >= 0 && box.x + box.width <= width + 1 && box.y >= 0 && box.y + box.height <= 903, 'The upper account dialog must stay inside the viewport.');
      await menu.hover();
      await page.mouse.wheel(0, 450);
      await expect.poll(() => menu.evaluate(e => e.scrollTop)).toBeGreaterThan(20); checks++;
      check(await page.evaluate(() => scrollY) === beforePage, 'Wheel input must scroll the menu, never the underlying page.');
      await menu.locator('button').last().focus();
      await page.keyboard.press('Tab');
      await expect(menu.getByRole('button', { name: 'Close account menu', exact: true })).toBeFocused(); checks++;
      await page.keyboard.press('Shift+Tab');
      await expect.poll(() => menu.evaluate(e => e.contains(document.activeElement))).toBe(true); checks++;
      if (width === 963) {
        await page.setViewportSize({ width: 390, height: 700 });
        await expect(menu).toBeVisible();
        await expect.poll(() => menu.evaluate(e => { const r=e.getBoundingClientRect(); return r.top >= 0 && r.bottom <= innerHeight + 1; })).toBe(true);
        await menu.evaluate(e => { e.scrollTop = 0; });
        const bounds = await menu.boundingBox();
        const x = bounds.x + bounds.width / 2;
        const start = Math.min(600, bounds.y + bounds.height - 50);
        const end = Math.max(bounds.y + 80, start - 260);
        const beforeTouch = await page.evaluate(() => scrollY);
        const touch = await context.newCDPSession(page);
        await touch.send('Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: [{ x, y: start }] });
        for (let part = 1; part <= 8; part++) await touch.send('Input.dispatchTouchEvent', { type: 'touchMove', touchPoints: [{ x, y: start + (end - start) * part / 8 }] });
        await touch.send('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] });
        await touch.detach();
        await expect.poll(() => menu.evaluate(e => e.scrollTop)).toBeGreaterThan(20); checks++;
        check(await page.evaluate(() => scrollY) === beforeTouch, 'Native touch scrolling must leave the background locked.');
        await page.setViewportSize({ width: 963, height: 902 });
        await expect(menu).toHaveClass(/is-navigation-overlay/);
        await expect.poll(() => menu.evaluate(e => { const r=e.getBoundingClientRect(); return !e.closest('[inert]') && e.contains(document.elementFromPoint(r.x+r.width/2,r.y+r.height/2)); })).toBe(true); checks++;
        await page.screenshot({ path: '/tmp/crozztube-account-scroll-fixed.png' });
      }
      await page.keyboard.press('Escape');
      await expect(menu).toHaveCount(0);
      await expect(nav).toHaveAttribute('aria-modal', 'true');
      await expect.poll(() => nav.evaluate(e => e.contains(document.activeElement) && !e.closest('[inert]'))).toBe(true); checks++;
      const links = nav.locator('.side-scroll');
      await links.hover();
      const beforeNav = await links.evaluate(e => e.scrollTop);
      await page.mouse.wheel(0, 400);
      await expect.poll(() => links.evaluate(e => e.scrollTop)).toBeGreaterThan(beforeNav + 20); checks++;
      await page.keyboard.press('Escape');
      await expect(nav).not.toHaveAttribute('aria-modal', 'true');
      await expect(trigger).toBeFocused(); checks++;
      await expect.poll(() => page.evaluate(() => !document.body.classList.contains('responsive-overlay-active') && !document.documentElement.style.overflow && !document.body.style.overflow)).toBe(true); checks++;
      check(await page.evaluate(() => scrollY) === beforePage, 'Closing both overlays must restore the original document position.');
      check(session.errors.length === 0 && session.writes.length === 0 && session.failedGets.length === 0, 'Scroll checks must not produce application errors, real writes or failed API GETs.');
      console.log(`${width}px: overlapping menus, wheel, focus, dismissal and scroll restoration passed.`);
    } finally { await session.context.close(); }
  }
} finally { await browser.close(); }
console.log(`${checks} account-menu scroll checks passed with regular HTTPS verification and one browser worker.`);
