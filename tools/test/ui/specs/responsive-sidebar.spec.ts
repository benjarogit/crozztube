import { test, expect, type Page } from '@playwright/test';
import { layoutSession } from '../fixtures/readonly-layout';
import { retainScreenshot } from '../fixtures/screenshots';

test.use({ trace: 'off', video: 'off' });

async function expectFooterContained(page: Page) {
  const sidebar = page.locator('#website-sidebar');
  await expect(sidebar).toBeVisible();
  const footer = sidebar.locator('.site-footer');
  await expect(footer.locator('.sidebar-footer-brand .logo-wrap')).toBeVisible();
  await expect(footer.locator('.sidebar-footer-brand > p')).not.toHaveText('');
  await expect(footer.locator('.sidebar-footer-built')).not.toHaveText('');
  await footer.scrollIntoViewIfNeeded();
  const problems = await footer.evaluate(element => {
    const sidebar = element.closest('.sidebar')!.getBoundingClientRect();
    return [element, ...element.querySelectorAll<HTMLElement>('*')].flatMap(item => {
      const rect = item.getBoundingClientRect();
      if (!item.checkVisibility({ checkVisibilityCSS: true }) || rect.width < 4 || rect.height < 4) return [];
      if (rect.left < sidebar.left - 1 || rect.right > sidebar.right + 1) {
        return [`${item.tagName}.${item.className}: ${Math.round(rect.left)}..${Math.round(rect.right)}`];
      }
      if (item instanceof HTMLElement && item.scrollWidth > item.clientWidth + 2
        && ['.site-footer-inner', '.site-footer-brand', '.site-footer-language', '.site-footer-bottom', '.sidebar-footer-brand', '.sidebar-footer-language', '.sidebar-footer-copyright'].some(selector => item.matches(selector))) {
        return [`${item.className} clips ${item.scrollWidth - item.clientWidth}px`];
      }
      return [];
    });
  });
  expect(problems).toEqual([]);
  await expect(footer.getByText(/All rights reserved|Alle Rechte|Rights reserved/i)).toBeVisible();
  await footer.locator('.sidebar-footer-built').scrollIntoViewIfNeeded();
  await expect(footer.locator('.sidebar-footer-built')).toBeInViewport();
}

for (const language of ['en', 'de_de']) {
  for (const width of [320, 390, 1024, 1280, 1440, 1920]) {
    test(`sidebar footer stays inside ${width}px ${language}`, async ({ browser }, info) => {
      const session = await layoutSession(browser, { authenticated: false, language, width, height: 900 });
      try {
        await session.goto('/');
        if (width < 1280) await session.page.locator('.topbar .menu-button').click();
        await expectFooterContained(session.page);
        if (width === 1920 && language === 'de_de') await retainScreenshot(session.page, info, 'sidebar-footer-de-1920');
        expect(session.errors).toEqual([]);
      } finally { await session.context.close(); }
    });
  }
}

test('sidebar footer follows configured width and keeps language usable in a short viewport', async ({ browser }) => {
  const session = await layoutSession(browser, { authenticated: false, width: 1440, height: 420, language: 'de_de' });
  try {
    await session.goto('/');
    for (const width of ['220px', '18rem', '340px']) {
      await session.page.evaluate(value => document.documentElement.style.setProperty('--sidebar-expanded', value), width);
      await expectFooterContained(session.page);
      const language = session.page.locator('#website-sidebar .language-switcher-trigger');
      await language.scrollIntoViewIfNeeded();
      await expect(language).toBeInViewport();
      await language.click();
      await expect(session.page.getByRole('dialog')).toBeVisible();
      await session.page.keyboard.press('Escape');
      await expect(language).toBeFocused();
    }
  } finally { await session.context.close(); }
});
