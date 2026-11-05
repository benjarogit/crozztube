import { test, expect } from '@playwright/test';

const pixel = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=', 'base64');
const first = 'https://i.fastpic.org/crz-test/primary.png';
const second = 'https://i.postimg.cc/crz-test/secondary.png';

test('thumbnail switches in-browser without backend requests', async ({ page }) => {
  await page.route('**/api/videos?**', async route => {
    const response = await route.fetch();
    const payload = await response.json();
    for (const video of payload.data || []) {
      video.thumbnail_url = first;
      video.thumbnail_candidates = [first, second];
    }
    await route.fulfill({ response, json: payload });
  });
  await page.route(first, route => route.fulfill({ contentType: 'image/png', body: pixel }));
  await page.route(second, route => route.fulfill({ contentType: 'image/png', body: pixel }));
  await page.goto('/');
  const image = page.locator('img[data-thumbnail-fallback]').first();
  await expect(image).toHaveAttribute('src', first);
  await expect.poll(() => image.evaluate((img: HTMLImageElement) => img.complete && img.naturalWidth > 0)).toBeTruthy();
  await page.waitForTimeout(500);
  const apiRequests: string[] = [];
  page.on('request', request => { if (new URL(request.url()).pathname.startsWith('/api/')) apiRequests.push(request.url()); });
  await page.route(first + '?broken', route => route.abort());
  await image.evaluate((img: HTMLImageElement, url: string) => { img.src = url; }, first + '?broken');
  await expect(image).toHaveAttribute('src', second);
  await expect.poll(() => image.evaluate((img: HTMLImageElement) => img.complete && img.naturalWidth > 0)).toBeTruthy();
  expect(apiRequests).toEqual([]);
});

test('all failed thumbnails end in a neutral preview without looping', async ({ page }) => {
  await page.route('**/api/videos?**', async route => {
    const response = await route.fetch();
    const payload = await response.json();
    for (const video of payload.data || []) {
      video.thumbnail_url = first;
      video.thumbnail_candidates = [first, second];
    }
    await route.fulfill({ response, json: payload });
  });
  let attempts = 0;
  await page.route('https://i.fastpic.org/crz-test/**', route => { attempts++; return route.abort(); });
  await page.route('https://i.postimg.cc/crz-test/**', route => { attempts++; return route.abort(); });
  await page.goto('/');
  await expect(page.locator('.thumb .crz-thumbnail-empty').first()).toBeVisible();
  await expect.poll(() => attempts).toBeGreaterThan(0);
  await expect(page.locator(`img[data-thumbnail-fallback][src="${first}"]`)).toHaveCount(0);
  await expect(page.locator(`img[data-thumbnail-fallback][src="${second}"]`)).toHaveCount(0);
  await page.waitForTimeout(500);
  const count = attempts;
  await page.waitForTimeout(500);
  expect(attempts).toBe(count);
});

test('short plays HLS with the adaptive player and supports seeking', async ({ page, request }) => {
  const response = await request.get('/api/videos?type=shorts&per_page=20');
  const payload = await response.json();
  const short = (payload.data || []).find((v: any) => v.video_sources?.some((s: any) => /mpegurl/.test(s.type)));
  expect(short, 'an imported HLS short must exist').toBeTruthy();
  await page.goto(`/shorts/${short.slug}`);
  const player = page.locator('.shorts-detail-provider-player');
  await expect(player).toBeVisible();
  const media = player.locator('video');
  await expect.poll(() => media.evaluate((v: HTMLVideoElement) => v.readyState), {timeout: 30_000}).toBeGreaterThanOrEqual(2);
  await expect.poll(() => media.evaluate((v: HTMLVideoElement) => v.currentTime), {timeout: 20_000}).toBeGreaterThan(0.5);
  await page.locator('input[aria-label="Seek short"]').fill('50');
  await expect.poll(() => media.evaluate((v: HTMLVideoElement) => v.currentTime)).toBeGreaterThan(30);
});

test('chapter track is displayed and selecting a chapter seeks playback', async ({page, request}) => {
  const payload = await (await request.get('/api/videos?type=video&per_page=20')).json();
  const video = (payload.data || []).find((v: any) => v.duration_seconds > 60 && v.video_sources?.length);
  expect(video).toBeTruthy();
  await page.route(`**/api/videos/${video.slug}`, async route => {
    const response = await route.fetch();
    const data = await response.json();
    data.video.chapters_enabled = true;
    data.video.chapters = [{start_time: 0, title: 'Beginning'}, {start_time: 30, title: 'Second chapter'}];
    await route.fulfill({response, json: data});
  });
  await page.goto(`/watch/${video.slug}`);
  const media = page.locator('.watch-vidstack-player video');
  await expect.poll(() => media.evaluate((v: HTMLVideoElement) => v.readyState), {timeout: 30_000}).toBeGreaterThanOrEqual(2);
  const overlay = page.locator('.watch-poster-overlay');
  if (await overlay.isVisible()) await overlay.click();
  await expect.poll(() => media.evaluate((v: HTMLVideoElement) => v.currentTime)).toBeGreaterThan(0.1);
  await page.locator('.watch-vidstack-player').hover();
  const chapterButton = page.getByRole('button', {name: 'Chapters', exact: true}).first();
  await expect(chapterButton).toBeVisible();
  await chapterButton.click();
  await page.getByText('Second chapter', {exact: true}).last().click();
  await expect.poll(() => media.evaluate((v: HTMLVideoElement) => v.currentTime)).toBeGreaterThanOrEqual(29);
});
