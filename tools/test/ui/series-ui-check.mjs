import { chromium, expect } from '@playwright/test';
import { applicationJson, layoutSession, layoutProblems } from './fixtures/readonly-layout.ts';

const listed = applicationJson('/api/videos?type=video&per_page=1', false).data[0];
// A controlled upload source keeps this check about the series UI. An imported
// catalogue entry may legitimately ask the source-resolver plugin to refresh
// its stale source, which is a write and unrelated to the series surface.
const video = {
  ...listed,
  source_type: 'upload',
  import_provider: null,
  import_url: null,
  embed_url: null,
  playback_pending: false,
  video_url: '/fixtures/viewer.mp4',
  video_sources: [{ src: '/fixtures/viewer.mp4', type: 'video/mp4' }],
  renditions: [],
};
const series = {
  id: 987654321,
  title: 'Series fixture',
  kind: 'series',
  status: 'ongoing',
  season_count: 1,
  episode_count: 2,
  current: { slug: video.slug, video_id: video.id, title: video.title, season_number: 1, episode_number: 1, episode_title: 'Pilot', label: 'S1 E1' },
  next: { slug: 'series-fixture-next-episode', video_id: 1, title: 'Next fixture', season_number: 1, episode_number: 2, episode_title: 'The next one', label: 'S1 E2' },
  seasons: [],
};
const browser = await chromium.launch({ headless: true });
const session = await layoutSession(browser, {
  authenticated: false,
  // The synthetic payload is an upload. The application answers 404 on the
  // resolver status route for a non-imported video, so the source-resolver
  // plugin stays idle instead of refreshing a catalogue import.
  respond: path => path === `/api/source-resolver/status/slug/${video.slug}`
    ? { status: 404, body: '{"message":"Not found."}' }
    : undefined,
  transform: (path, payload) => path === `/api/videos/${video.slug}`
    ? { ...payload, video: { ...payload.video, ...video }, series }
    : payload,
});
const { page } = session;
try {
  await session.goto(`/watch/${video.slug}`);
  await expect(page.locator('.watch-series-chip')).toContainText('Series fixture');
  await expect(page.locator('.watch-series-chip')).toContainText('S1 E1');
  await expect(page.locator('.watch-next-episode')).toContainText('Next episode');
  await expect(page.locator('.watch-next-episode')).toContainText('The next one');
  await expect(page.locator('.watch-next-episode')).toContainText('S1 E2');
  expect(await page.locator('.watch-autoplay-toggle').count()).toBe(1);
  expect(await layoutProblems(page)).toEqual([]);
  await page.setViewportSize({ width: 375, height: 812 });
  expect(await layoutProblems(page)).toEqual([]);
  expect(session.errors).toEqual([]);
  expect(session.writes).toEqual([]);
  console.log('Series UI fixture: chip, next-episode card, single autoplay toggle and no overflow at 1440/375.');
} finally {
  await session.context.close();
  await browser.close();
}
