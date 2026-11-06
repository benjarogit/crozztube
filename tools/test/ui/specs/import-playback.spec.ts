import { test, expect } from '@playwright/test';
import { applicationJson, layoutSession } from '../fixtures/readonly-layout';

test.use({ trace: 'off', video: 'off' });
const chapters = [{ start_time: 0, title: 'Intro' }, { start_time: 5, title: 'Short section' }, { start_time: 20, title: 'Main' }];

for (const firstStatus of ['pending', 'fresh', 'error']) {
  test(`BitChute ${firstStatus}: native playback recovers and embed requires Mirror`, async ({ browser }) => {
    let ready = false;
    let videoRequests = 0;
    const items = applicationJson('/api/videos?per_page=50', false).data || [];
    const slug = items.find((video: any) => !video.is_short && video.source_type === 'import')?.slug;
    expect(slug, 'One public imported video is required').toBeTruthy();
    const original = applicationJson(`/api/videos/${slug}`, false);
    const session = await layoutSession(browser, { authenticated: false, transform: (path, payload) => {
      if (path === `/api/videos/${slug}`) {
        videoRequests++;
        const resolved = firstStatus !== 'error' && videoRequests > 1;
        return { ...original, player_ads: null, video: { ...original.video, import_provider: 'bitchute',
          video_url: resolved ? 'https://cdn.example.test/native.mp4' : null,
          video_sources: resolved ? [{ src: 'https://cdn.example.test/native.mp4', type: 'video/mp4' }] : [],
          embed_url: null, playback_pending: !resolved,
        } };
      }
      if (path.startsWith('/api/source-resolver/status/slug/')) {
        return { source: { ...payload.source, state: firstStatus === 'error' ? 'error' : ready || firstStatus === 'fresh' ? 'fresh' : 'pending',
          fresh: firstStatus !== 'error' && (ready || firstStatus === 'fresh'), refreshing: false, has_direct: false,
          auto_refresh_allowed: false, error_message: firstStatus === 'error' ? 'Test resolution failure' : null,
          mirror: { mode: 'iframe', provider: 'bitchute', label: 'BitChute', embed_url: 'https://www.bitchute.com/embed/chaptertest1/' },
        } };
      }
      return payload;
    } });
    await session.context.route('https://www.bitchute.com/embed/**', route => route.fulfill({ contentType: 'text/html', body: '<title>Provider mirror fixture</title>' }));
    try {
      await session.goto(`/watch/${slug}`);
      const { page } = session;
      await expect(page.locator('.crz-mirror-toggle')).toBeVisible();
      if (firstStatus === 'error') {
        await expect(page.locator('.crz-source-banner')).toContainText('Test resolution failure');
      } else {
        ready = true;
        await expect(page.locator('.watch-vidstack-player')).toBeVisible({ timeout: 20_000 });
        await expect(page.locator('.crz-source-banner')).toHaveCount(0);
        expect(videoRequests).toBe(2);
      }
      await expect(page.locator('.watch-embed-player,.crz-mirror-layer')).toHaveCount(0);
      await expect(page.locator('.crz-mirror-toggle')).toHaveAttribute('aria-pressed', 'false');
      await page.locator('.crz-mirror-toggle').click();
      await expect(page.locator('.crz-mirror-layer iframe')).toHaveAttribute('src', 'https://www.bitchute.com/embed/chaptertest1/');
      await page.locator('.crz-mirror-toggle').click();
      await expect(page.locator('.crz-mirror-layer')).toHaveCount(0);
      expect(session.writes).toEqual([]);
      expect(session.errors).toEqual([]);
    } finally { await session.context.close().catch(() => {}); }
  });
}

for (const mode of ['detected', 'none', 'manual']) {
  const detected = mode === 'detected';
  test(`import ${mode === 'detected' ? 'selects detected chapters' : mode === 'none' ? 'leaves missing chapters off' : 'supports manual chapters'}`, async ({ browser }) => {
    const session = await layoutSession(browser);
    const { page } = session;
    page.setDefaultTimeout(10_000);
    const openReview = async () => {
      await page.getByRole('button', { name: 'Next', exact: true }).click();
      await expect(page.getByRole('heading', { name: '3 Visibility', exact: true })).toBeVisible();
      await page.getByRole('button', { name: 'Next', exact: true }).click();
    };
    let published: any;
    await session.context.route('**/api/video-imports/metadata', route => {
      const body = route.request().postDataJSON();
      return route.fulfill({ json: { metadata: { provider: body.provider, source_url: body.url, title: 'Chapter import fixture',
        description: 'Test details', duration_seconds: 120, chapters: detected ? chapters : [], chapters_source: 'provider', is_short: false,
      } } });
    });
    await session.context.route('**/api/video-imports', route => {
      published = route.request().postDataJSON();
      return route.fulfill({ status: 201, json: { video: { id: 999999, slug: 'chapter-fixture', title: published.title, status: 'ready' } } });
    });
    try {
      await session.goto('/upload');
      const importToggle = page.getByRole('button', { name: 'Import from URL', exact: true });
      if (await importToggle.count()) await importToggle.click();
      await page.getByRole('textbox', { name: 'Video URL', exact: true }).fill('https://www.bitchute.com/video/chaptertest1/');
      await expect(page.getByRole('button', { name: 'Fetch details', exact: true })).toBeEnabled();
      await expect(page.locator('input[value="Chapter import fixture"]')).toBeVisible();
      const category = page.locator('.upload-label').filter({ hasText: /^Category/ }).locator('..').getByRole('combobox');
      await category.press('ArrowDown');
      await page.getByRole('option', { disabled: false }).first().click();
      await openReview();
      const checkbox = page.getByRole('checkbox', { name: /Upload with chapters/ });
      if (detected) {
        await expect(checkbox).toBeChecked();
        await expect(page.getByRole('textbox', { name: 'Chapter 2 start time', exact: true })).toHaveValue('0:05');
        // Deliberate disable survives another metadata request.
        await checkbox.uncheck();
        await page.locator('.wizard-step').filter({ hasText: 'Details' }).click();
        await page.getByRole('button', { name: 'Fetch details', exact: true }).click();
        await expect(page.getByRole('button', { name: 'Fetch details', exact: true })).toBeEnabled();
        await openReview();
        await expect(checkbox).not.toBeChecked();
        await checkbox.check();
      } else {
        await expect(checkbox).not.toBeChecked();
        await expect(page.locator('.upload-chapter-row')).toHaveCount(0);
        if (mode === 'manual') {
          await checkbox.check();
          await expect(page.locator('.upload-chapter-row')).toHaveCount(2);
          await page.getByRole('textbox', { name: 'Chapter 1 name', exact: true }).fill('Manual intro');
          await page.getByRole('textbox', { name: 'Chapter 2 name', exact: true }).fill('Manual main');
        }
      }
      await page.getByRole('button', { name: 'Publish', exact: true }).click();
      await expect.poll(() => published).toBeTruthy();
      expect(published.chapters_enabled).toBe(mode !== 'none');
      expect(published.chapters).toEqual(detected ? chapters : mode === 'none' ? [] : [{ start_time: 0, title: 'Manual intro' }, { start_time: 60, title: 'Manual main' }]);
      expect(session.writes).toEqual([]);
      expect(session.errors).toEqual([]);
    } finally { await session.context.close().catch(() => {}); }
  });
}
