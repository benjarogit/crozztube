import path from 'node:path';
import { readFileSync } from 'node:fs';
import { chromium, expect as baseExpect } from '@playwright/test';
import { applicationJson, layoutSession, expectContained } from './fixtures/readonly-layout.ts';

const expect = baseExpect.configure({ timeout: 15_000 });
const build = process.env.SERIES_TEST_BUILD;
if (!build) throw new Error('Set SERIES_TEST_BUILD to the isolated Vite output directory.');
const entry = JSON.parse(readFileSync(path.join(build, 'manifest.json')))['resources/js/main.jsx'];
const batch = JSON.parse(readFileSync(new URL('../../i18n/batches/series-romance-first.json', import.meta.url)));
const locales = Object.keys(batch.messages.Series);
const videos = applicationJson('/api/videos?type=video&per_page=6', false).data
  .filter(video => video?.slug)
  .slice(0, 2)
  .map((video, index) => ({ ...video, season_number: 1, episode_number: index + 1, episode_label: 'S1 E' + (index + 1) }));
if (videos.length !== 2) throw new Error('Series locale fixture needs two catalogue entries.');
const [first, next] = videos;
const playlist = {
  id: 987654321, user_id: Number(process.env.LAYOUT_USER_ID || '1'), title: 'Series language fixture',
  kind: 'series', viewer_role: 'owner', series_status: 'completed', privacy: 'Public', videos, videos_count: videos.length,
  video: first, video_ids: videos.map(video => video.id), channel: first.channel,
  series: { season_count: 1, episode_count: 2, seasons: [{ number: 1, episode_count: 2 }] },
};
const detail = video => ({ slug: video.slug, title: video.title, video_id: video.id, season_number: 1,
  episode_number: video.episode_number, label: video.episode_label });

const browser = await chromium.launch({ headless: true });
try {
  for (const locale of locales) {
    const message = key => batch.messages[key][locale];
    const session = await layoutSession(browser, {
      language: locale, width: 390, touch: true, dark: true, strictHttps: true,
      respond: url => {
        const json = value => ({ status: 200, body: JSON.stringify(value) });
        if (url === '/api/source-resolver/status/slug/' + first.slug) return { status: 404, body: '{"message":"Not found."}' };
        if (url.startsWith('/api/playlists?') || url === '/api/playlists') return json({ playlists: [playlist] });
        if (url.split('?')[0] === '/api/playlists/' + playlist.id) return json({ playlist, viewer_role: 'owner', vote_counts: {}, members: [] });
      },
      transform: (url, payload) => url === '/api/videos/' + first.slug ? {
        ...payload,
        video: { ...payload.video, source_type: 'upload', import_provider: null, import_url: null, embed_url: null,
          playback_pending: false, video_url: '/fixtures/viewer.mp4', video_sources: [{ src: '/fixtures/viewer.mp4', type: 'video/mp4' }] },
        series: { ...playlist.series, id: playlist.id, title: playlist.title, kind: 'series', status: 'ongoing',
          current: detail(first), next: detail(next) },
      } : payload,
    });
    const { context, page, writes, errors, failedGets } = session;
    const goto = route => page.goto(route, { waitUntil: 'domcontentloaded' });
    page.on('response', response => {
      const url = new URL(response.url());
      if (url.pathname.startsWith('/api/playlists') && response.status() >= 400) console.error('Playlist fixture:', response.status(), url.pathname);
    });
    try {
      await context.route('**/build/assets/**', async route => {
        const file = path.basename(new URL(route.request().url()).pathname);
        try {
          await route.fulfill({ path: path.join(build, 'assets', file) });
        } catch (error) {
          if (error.code !== 'ENOENT' && !String(error).includes('ENOENT')) throw error;
          await route.fallback();
        }
      });
      await context.route(url => url.pathname.startsWith('/watch/') || url.pathname === '/playlists', async route => {
        if (!route.request().isNavigationRequest()) return route.fallback();
        const response = await route.fetch({ maxRedirects: 0 });
        const body = (await response.text()).replace(/\/build\/assets\/main-[\w-]+\.js/g, '/build/' + entry.file)
          .replace(/\/build\/assets\/main-[\w-]+\.css/g, '/build/' + entry.css[0]);
        if (!body.includes('/build/' + entry.file)) throw new Error('Candidate frontend entry is missing.');
        await route.fulfill({ response, body });
      });
      await goto('/watch/' + first.slug);
      await expect(page.locator('.watch-next-episode')).toContainText(message('Next episode'));
      await expect(page.locator('.watch-next-episode')).toContainText('S1 E2');
      await expect(page.locator('.watch-autoplay-toggle')).toHaveCount(1);
      await expectContained(page, locale + ' watch');

      await goto('/playlists?id=' + playlist.id);
      await expect(page.locator('.playlist-meta-line')).toContainText('2 ' + message('episodes'));
      await expect(page.locator('.playlist-side-card').filter({ hasText: message('About this series') }))
        .toContainText(new RegExp('1 ' + message('season') + '(?![\\p{L}])', 'u'));
      await expectContained(page, locale + ' playlist');
      const edit = page.getByRole('button', { name: message('Edit series'), exact: true });
      await edit.click();
      const editor = page.locator('.playlist-episode-editor');
      await expect(editor).toContainText(message('Seasons and episodes'));
      await expect(editor).toContainText(message('Leave the episode number empty to number it automatically from its position in the season.'));
      const tip = Object.keys(batch.messages).find(key => key.includes('turn autoplay on or off'));
      await expect(page.locator('.playlist-tip-card')).toContainText(message(tip));
      await expect(page.locator('.playlist-preview-copy')).toContainText(message('Series completed'));
      const episodeRows = editor.locator('.playlist-episode-row');
      await expect(episodeRows).toHaveCount(2);
      await episodeRows.nth(1).getByRole('spinbutton').nth(1).fill('1');
      await expect(editor.getByRole('alert')).toContainText(message('Episode numbers must be unique within a season.'));
      await expect(page.getByRole('dialog').locator('button[type="submit"]')).toBeDisabled();
      await expectContained(page, locale + ' editor');
      expect(writes).toEqual([]);
      expect(errors).toEqual([]);
      expect(failedGets).toEqual([]);
      console.log('PASS', locale, 'watch, series editor and duplicate warning at 390px');
    } finally { await context.close(); }
  }
} finally { await browser.close(); }
