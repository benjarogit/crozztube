import { test, expect, type APIRequestContext } from '@playwright/test';

const USER = process.env.TEST_USER || 'benny';
const PASSWORD = process.env.TEST_PASSWORD || 'meZVRW0bYEbpHsJc';

type VideoRef = { id: number; slug: string };

let token = '';
let video: VideoRef | null = null;

async function firstImportedVideo(request: APIRequestContext): Promise<VideoRef> {
  const response = await request.get('/api/videos?per_page=50');
  expect(response.ok()).toBeTruthy();
  const payload = await response.json();
  const items = Array.isArray(payload) ? payload : payload.data || [];
  const found = items.find((item: VideoRef & { source_type?: string }) => item.source_type === 'import');
  expect(found, 'no imported video available for UI tests').toBeTruthy();
  return { id: found.id, slug: found.slug };
}

test.beforeAll(async ({ request }) => {
  const response = await request.post('/api/auth/login', {
    data: { login: USER, password: PASSWORD, device_name: 'playwright-harness' },
  });
  expect(response.ok()).toBeTruthy();
  token = (await response.json()).token;
  expect(token).toBeTruthy();
  video = await firstImportedVideo(request);
});

test.beforeEach(async ({ page }) => {
  await page.addInitScript((value: string) => {
    window.localStorage.setItem('playhub_token', value);
    // Clear the mirror preference once per browser context, not on every
    // navigation: the reload test needs the stored preference to survive.
    if (!window.sessionStorage.getItem('crz-test-init')) {
      window.localStorage.removeItem('crz.mirror.prefs');
      window.sessionStorage.setItem('crz-test-init', '1');
    }
  }, token);
});

test('mirror button toggles the provider embed', async ({ page }) => {
  await page.goto(`/watch/${video!.slug}`);

  const button = page.locator('.crz-mirror-toggle');
  await expect(button).toBeVisible({ timeout: 25_000 });
  await expect(button).toHaveAttribute('aria-pressed', 'false');

  await button.click();
  const layer = page.locator('.crz-mirror-layer');
  await expect(layer).toBeVisible();
  await expect(button).toHaveAttribute('aria-pressed', 'true');

  const src = await layer.locator('iframe').getAttribute('src');
  expect(src).toContain('/embed/');
  expect(src).toContain('autoplay=1');

  await button.click();
  await expect(page.locator('.crz-mirror-layer')).toHaveCount(0);
  await expect(button).toHaveAttribute('aria-pressed', 'false');
});

test('mirror preference survives a reload', async ({ page }) => {
  await page.goto(`/watch/${video!.slug}`);

  const button = page.locator('.crz-mirror-toggle');
  await expect(button).toBeVisible({ timeout: 25_000 });
  await button.click();
  await expect(page.locator('.crz-mirror-layer')).toBeVisible();

  await page.reload();
  await expect(page.locator('.crz-mirror-layer')).toBeVisible({ timeout: 25_000 });
  await expect(page.locator('.crz-mirror-toggle')).toHaveAttribute('aria-pressed', 'true');
});

test('mirror state is synced on SPA navigation', async ({ page }) => {
  await page.goto(`/watch/${video!.slug}`);
  await expect(page.locator('.crz-mirror-toggle')).toBeVisible({ timeout: 25_000 });
  await page.locator('.crz-mirror-toggle').click();
  await expect(page.locator('.crz-mirror-layer')).toBeVisible();

  // Client-side navigation (sidebar item), not a full page load: the overlay
  // must be torn down by the enhancer itself.
  await page.locator('aside.sidebar .nav-item').first().click();
  await expect(page).not.toHaveURL(/\/watch\//, { timeout: 15_000 });
  await expect(page.locator('.crz-mirror-layer')).toHaveCount(0);
  await expect(page.locator('.crz-mirror-toggle')).toHaveCount(0);
  await expect(page.locator('.crz-source-banner')).toHaveCount(0);
});
