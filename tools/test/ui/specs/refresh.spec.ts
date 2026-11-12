import { test, expect, type APIRequestContext } from '@playwright/test';
import { execFileSync } from 'node:child_process';

/**
 * Auto-refresh tests need to force server-side source states (expired URL,
 * failed resolve). They run against the local docker stack and always restore
 * the original row in afterAll.
 */

const USER = process.env.TEST_USER || 'benny';
const PASSWORD = process.env.TEST_PASSWORD || '';
const DB_CONTAINER = process.env.DB_CONTAINER || 'crozztube-mysql';

type VideoRef = { id: number; slug: string };

let token = '';
let video: VideoRef;

function sql(statement: string): string {
  return execFileSync('docker', [
    'exec', DB_CONTAINER, 'mysql', '-N', '-B',
    '-ucrozztube', '-pcrozztube-local-db', 'crozztube', '-e', statement,
  ], { stdio: ['ignore', 'pipe', 'ignore'] }).toString().trim();
}

function tinker(code: string): void {
  execFileSync('docker', ['exec', 'crozztube-web', 'php', 'artisan', 'tinker', '--execute', code], {
    stdio: ['ignore', 'ignore', 'ignore'],
  });
}

function clearDebounce(id: number): void {
  tinker(`Cache::forget('source-resolver:req.${id}'); Cache::forget('source-resolver:video.${id}');`);
}

async function firstImportedVideo(request: APIRequestContext): Promise<VideoRef> {
  const response = await request.get('/api/videos?per_page=50');
  expect(response.ok()).toBeTruthy();
  const payload = await response.json();
  const items = Array.isArray(payload) ? payload : payload.data || [];
  // HLS sources have an expiry cycle; direct files use a 24 h TTL and would
  // make this suite depend on an external host. Prefer a YouTube import.
  const found =
    items.find(
      (item: VideoRef & { source_type?: string; import_provider?: string }) =>
        item.source_type === 'import' && item.import_provider === 'youtube',
    ) || items.find((item: VideoRef & { source_type?: string }) => item.source_type === 'import');
  expect(found, 'no imported video available for UI tests').toBeTruthy();
  return { id: found.id, slug: found.slug };
}

let backup = '';

test.beforeAll(async ({ request }) => {
  if (!PASSWORD) throw new Error('Set TEST_PASSWORD in your local environment before authenticated tests.');
  const response = await request.post('/api/auth/login', {
    data: { login: USER, password: PASSWORD, device_name: 'playwright-refresh' },
  });
  expect(response.ok()).toBeTruthy();
  token = (await response.json()).token;
  video = await firstImportedVideo(request);

  backup = sql(
    `SELECT CONCAT_WS('|', state, COALESCE(media_url,''), COALESCE(expires_at,''), ` +
    `COALESCE(resolved_at,''), COALESCE(error_message,'')) ` +
    `FROM crozztube_import_sources WHERE video_id=${video.id};`
  );
  expect(backup, 'import source row must exist').not.toBe('');
});

test.afterAll(async () => {
  // Let user-triggered refresh jobs finish before restoring, otherwise the job
  // writes over the restored row and leaves the next suite with a moving target.
  const idle = () =>
    sql('SELECT COUNT(*) FROM jobs;') === '0' &&
    sql(`SELECT state FROM crozztube_import_sources WHERE video_id=${video.id};`) !== 'refreshing';

  for (let attempt = 0; attempt < 45 && !idle(); attempt += 1) {
    await new Promise((resolve) => setTimeout(resolve, 2000));
  }

  const [state, mediaUrl, expiresAt, resolvedAt, errorMessage] = backup.split('|');
  const restoreExpires = expiresAt ? `'${expiresAt}'` : 'NULL';
  const restoreResolved = resolvedAt ? `'${resolvedAt}'` : 'NULL';
  const restoreError = errorMessage ? `'${errorMessage}'` : 'NULL';
  sql(
    `UPDATE crozztube_import_sources SET state='${state}', media_url='${mediaUrl}', ` +
    `expires_at=${restoreExpires}, resolved_at=${restoreResolved}, ` +
    `error_message=${restoreError}, refresh_started_at=NULL ` +
    `WHERE video_id=${video.id};`
  );
  clearDebounce(video.id);
});

test.beforeEach(async ({ page }) => {
  await page.addInitScript((value: string) => {
    window.localStorage.setItem('playhub_token', value);
    if (!window.sessionStorage.getItem('crz-test-init')) {
      window.localStorage.removeItem('crz.mirror.prefs');
      window.sessionStorage.setItem('crz-test-init', '1');
    }
  }, token);
});

test('expired direct source renews and reloads the player', async ({ page }) => {
  test.setTimeout(180_000);

  const before = sql(`SELECT COALESCE(resolved_at, '1970-01-01') FROM crozztube_import_sources WHERE video_id=${video.id};`);
  sql(`UPDATE crozztube_import_sources SET state='fresh', expires_at='2000-01-01 00:00:00' WHERE video_id=${video.id};`);
  clearDebounce(video.id);

  await page.addInitScript(() => {
    const loads = Number(window.sessionStorage.getItem('crz-test-loads') || 0) + 1;
    window.sessionStorage.setItem('crz-test-loads', String(loads));
  });

  await page.goto(`/watch/${video.slug}`);

  const banner = page.locator('.crz-source-banner');
  await expect(banner).toHaveAttribute('data-state', 'refreshing', { timeout: 30_000 });
  await expect(banner).toContainText(/Renewing|expired/i);

  // The enhancer reloads once a fresh URL is stored.
  await expect
    .poll(async () => page.evaluate(() => Number(window.sessionStorage.getItem('crz-test-loads') || 0)), {
      timeout: 150_000,
      intervals: [1000, 2000, 5000],
    })
    .toBeGreaterThan(1);

  await expect(page.locator('.crz-source-banner')).toHaveCount(0, { timeout: 30_000 });

  const after = sql(`SELECT COALESCE(resolved_at, '1970-01-01') FROM crozztube_import_sources WHERE video_id=${video.id};`);
  expect(after > before).toBeTruthy();
  expect(sql(`SELECT state FROM crozztube_import_sources WHERE video_id=${video.id};`)).toBe('fresh');
});

test('failed source shows the error banner with mirror fallback', async ({ page }) => {
  // No direct source + a hard error: the enhancer must not spin forever, it
  // offers Retry and the provider mirror instead.
  sql(
    `UPDATE crozztube_import_sources SET state='error', error_message='test failure', ` +
    `media_url='', expires_at=NULL, refresh_started_at=NULL WHERE video_id=${video.id};`
  );

  await page.goto(`/watch/${video.slug}`);

  const banner = page.locator('.crz-source-banner');
  await expect(banner).toHaveAttribute('data-state', 'error', { timeout: 30_000 });
  await expect(banner).toContainText('test failure');
  await expect(banner.getByRole('button', { name: 'Retry' })).toBeVisible();

  const mirrorButton = banner.getByRole('button', { name: 'Open mirror' });
  if (await mirrorButton.count()) {
    await mirrorButton.click();
    await expect(page.locator('.crz-mirror-layer')).toBeVisible();
    await expect(page.locator('.crz-mirror-toggle')).toHaveAttribute('aria-pressed', 'true');
  } else {
    // Link-mode providers (e.g. plain file URLs) render an external link.
    await expect(banner.getByRole('button', { name: 'Open original' })).toBeVisible();
  }
});
