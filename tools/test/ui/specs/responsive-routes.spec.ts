import { test, expect } from '@playwright/test';
import { layoutSession, expectContained } from '../fixtures/readonly-layout';
import { routeInventory, resolveRoute } from '../fixtures/route-inventory';
import { catalogue } from '../fixtures/catalogue';
import { retainScreenshot } from '../fixtures/screenshots';

test.use({ trace: 'off', video: 'off' });

for (const width of [360, 1024, 1440]) {
  test.describe(`${width}px complete route inventory`, () => {
    for (const entry of routeInventory) {
      test(`${entry.area}: ${entry.name}`, async ({ browser }, testInfo) => {
        const session = await layoutSession(browser, { width, height: 900, authenticated: !entry.guest });
        try {
          const path = resolveRoute(entry.path, catalogue());
          await session.goto(path);
          await expectContained(session.page, `${entry.name} at ${width}px`);
          expect(session.failedGets, 'real fixture GET responses must succeed').toEqual([]);
          expect(session.errors, 'rendering must not throw').toEqual([]);
          if (entry.path === '/' || (entry.path === '/settings' && !entry.guest && width === 360)) {
            await retainScreenshot(session.page, testInfo, `${entry.path === '/' ? 'home' : 'settings'}-${width}`);
          }
        } catch (error) {
          await testInfo.attach('layout', { body: await session.page.screenshot(), contentType: 'image/png' });
          throw error;
        } finally {
          await session.context.close();
        }
      });
    }
  });
}

test('inventory includes every route family, enabled plugin page and unique scenario', async ({ browser }) => {
  expect(new Set(routeInventory.map(route => route.area))).toEqual(new Set(['public', 'account', 'creator', 'admin', 'plugin', 'disabled']));
  expect(new Set(routeInventory.map(route => `${route.guest === true}:${route.path}`)).size).toBe(routeInventory.length);
  const session = await layoutSession(browser);
  try {
    await session.goto('/admin/plugins');
    const pluginPaths = await session.page.evaluate(() => {
      const runtime = (window as any).PlayHub;
      return [...(runtime?.getPages?.() || []), ...(runtime?.getAdminPages?.() || [])].map(page => page.path);
    });
    for (const path of pluginPaths) expect(routeInventory.map(route => route.path), `enabled plugin page ${path} needs a responsive inventory entry`).toContain(path);
  } finally { await session.context.close(); }
});
