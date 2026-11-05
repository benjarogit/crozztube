import { test, expect } from '@playwright/test';
import { layoutSession, expectContained } from '../fixtures/readonly-layout';
import { representativeRoutes, resolveRoute } from '../fixtures/route-inventory';
import { catalogue } from '../fixtures/catalogue';

test.use({ trace: 'off', video: 'off' });

// Firefox/WebKit exercise distinct rendering engines, not Chromium device skins.
for (const width of [320, 390, 768, 1280, 1920, 2560]) {
  for (const route of representativeRoutes) {
    test(`${width}px ${route}`, async ({ browser }, testInfo) => {
      const session = await layoutSession(browser, { width, height: width >= 1920 ? 1080 : 900 });
      try {
        await session.goto(resolveRoute(route, catalogue()));
        await expectContained(session.page, `${route} at ${width}px`);
        expect(session.failedGets).toEqual([]);
        expect(session.errors).toEqual([]);
      } catch (error) {
        await testInfo.attach('layout', { body: await session.page.screenshot(), contentType: 'image/png' });
        throw error;
      } finally { await session.context.close(); }
    });
  }
}
