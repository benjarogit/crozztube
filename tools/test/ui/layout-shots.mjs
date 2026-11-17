/**
 * Five-area layout evidence: home, video cards, navigation, series detail and
 * watch. Light/dark, English/German, desktop/mobile, one browser at a time.
 *
 * Like the other shot scripts it renders the real application against
 * read-only API responses, never writes application data and never downloads
 * media. The series playlist is a fixture because the local database has no
 * published multi-season series.
 *
 * Usage: node tools/test/ui/layout-shots.mjs <label> [outputDir]
 */
import fs from 'node:fs';
import path from 'node:path';
import { chromium } from '@playwright/test';
import { applicationJson, layoutSession, layoutProblems } from './fixtures/readonly-layout.ts';

const label = process.argv[2] || 'current';
const outDir = process.argv[3] || `/tmp/opencode/shots/${label}`;
fs.mkdirSync(outDir, { recursive: true });

const sourceVideos = applicationJson('/api/videos?type=video&per_page=6', false).data
  .filter((video) => video?.slug)
  .slice(0, 6);

const LONG = {
  en: {
    series: 'The long road home: a documentary series about landscapes, seasons and the people who keep travelling',
    episodes: [
      'The first episode with a deliberately long title about leaving the coast before sunrise',
      'The second episode about the quiet hours between arrival and the first real conversation',
    ],
  },
  de: {
    series: 'Der lange Weg nach Hause: Eine Dokumentarserie über Landschaften, Jahreszeiten und Menschen, die immer weiterreisen',
    episodes: [
      'Die erste Folge mit einem absichtlich langen Titel über die Abreise von der Küste vor Sonnenaufgang',
      'Die zweite Folge über die stillen Stunden zwischen Ankunft und dem ersten echten Gespräch',
    ],
  },
};

const seriesId = 987654321;

const episode = (video, index, language) => {
  const season = index < 3 ? 1 : 2;
  const number = (index % 3) + 1;
  return {
    ...video,
    season_number: season,
    episode_number: number,
    episode_label: `S${season} E${number}`,
    episode_title: LONG[language].episodes[index % LONG[language].episodes.length],
    episode_description: video.description,
    aired_at: null,
  };
};

const playlistFor = (language) => {
  const videos = sourceVideos.map((video, index) => episode(video, index, language));
  return {
    id: seriesId,
    user_id: 1,
    channel_id: videos[0]?.channel?.id || null,
    title: LONG[language].series,
    description: 'A slow documentary about travelling, waiting and the places in between, produced by a small independent team.',
    privacy: 'Public',
    kind: 'series',
    series_status: 'ongoing',
    series: {
      season_count: 2,
      episode_count: videos.length,
      seasons: [1, 2].map((number) => ({
        number,
        episode_count: videos.filter((video) => video.season_number === number).length,
      })),
    },
    color: null,
    cover_video_id: videos[0]?.id || null,
    videos_count: videos.length,
    video: videos[0] || null,
    videos,
    video_ids: videos.map((video) => video.id),
    channel: videos[0]?.channel || null,
    created_at: '2026-10-01T10:00:00Z',
    updated_at: '2026-11-15T10:00:00Z',
    viewer_role: 'viewer',
    pending_invite: false,
    vote_counts: {},
    members: [],
  };
};

const resume = (language) => {
  const video = episode(sourceVideos[1], 1, language);
  return { ...video, progress: 38, current_time: 240, duration_seconds: 631, progress_updated_at: '2026-11-16T09:00:00Z' };
};

const servePlaylist = (page, playlist) => page.route('**/api/playlists**', async (route) => {
  const url = new URL(route.request().url());
  if (url.pathname === '/api/playlists') {
    await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ playlists: [{ ...playlist, videos: [] }] }) });
    return;
  }
  if (url.pathname === `/api/playlists/${playlist.id}`) {
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ playlist, viewer_role: 'viewer', vote_counts: {}, members: [], pending_invite: false }),
    });
    return;
  }
  await route.fallback();
});

const continueTransform = (watched) => (apiPath, payload) => (
  apiPath.startsWith('/api/continue-watching') ? { ...payload, data: [watched] } : payload
);

/**
 * A six-video fixture cannot fill hero, trending, continue and discovery at
 * once; discovery would stay empty and leave no card grid to photograph.
 * Clones keep the real titles, channels and thumbnails but never resolve as
 * detail pages, so nothing is clicked in this evidence run.
 */
const expandVideos = (apiPath, payload) => {
  if (!apiPath.startsWith('/api/videos?') || !Array.isArray(payload.data)) return payload;
  const ordered = [...payload.data].sort((left, right) => parseInt(left?.id, 10) - parseInt(right?.id, 10));
  const base = ordered.filter((item) => item?.slug).slice(0, 3);
  if (!base.length) return payload;
  const data = [...ordered];
  // Hero (4), trending (6) and continue (1) draw from the same pools; keep
  // enough discovery rows to photograph a full shelf after those exclusions.
  for (let index = 0; data.length < 24; index += 1) {
    const item = base[index % base.length];
    data.push({
      ...item,
      id: Number(item.id || 0) + 100000 + index,
      slug: `${item.slug}-layout-${index}`,
      title: `${item.title} ${index + 2}`,
      views_count: 1200 * (index + 2),
    });
  }
  return { ...payload, data };
};

const homeTransform = (watched) => (apiPath, payload) => (
  expandVideos(apiPath, continueTransform(watched)(apiPath, payload))
);

const watchTransform = (playlist) => (apiPath, payload) => {
  if (apiPath.startsWith(`/api/playlists/${playlist.id}`)) {
    return { ...payload, playlist, viewer_role: 'viewer', vote_counts: {}, members: [], pending_invite: false };
  }
  const match = apiPath.match(/^\/api\/videos\/([^?]+)/);
  if (!match) return payload;
  const current = playlist.videos.find((video) => video.slug === decodeURIComponent(match[1])) || playlist.videos[0];
  return {
    ...payload,
    series: {
      id: playlist.id,
      title: playlist.title,
      kind: 'series',
      status: 'ongoing',
      season_count: 2,
      episode_count: playlist.videos.length,
      current: {
        slug: current.slug,
        video_id: current.id,
        title: current.title,
        season_number: current.season_number,
        episode_number: current.episode_number,
        episode_title: current.episode_title,
        label: current.episode_label,
      },
      next: playlist.videos[1] ? {
        slug: playlist.videos[1].slug,
        video_id: playlist.videos[1].id,
        title: playlist.videos[1].title,
        season_number: playlist.videos[1].season_number,
        episode_number: playlist.videos[1].episode_number,
        episode_title: playlist.videos[1].episode_title,
        label: playlist.videos[1].episode_label,
      } : null,
      seasons: [],
    },
  };
};

const pageTransform = (playlist, watched) => (apiPath, payload) => (
  homeTransform(watched)(apiPath, watchTransform(playlist)(apiPath, payload))
);

const shots = [];const record = (name, problems, session) => {
  shots.push({
    name,
    problems: problems.length ? problems : 'none',
    writes: session.writes,
    errors: session.errors,
  });
};

const browser = await chromium.launch({ headless: true });
try {
  for (const language of ['en', 'de']) {
    const playlist = playlistFor(language);
    const watched = resume(language);
    for (const dark of [false, true]) {
      const theme = dark ? 'dark' : 'light';
      const desktop = await layoutSession(browser, {
        authenticated: true,
        language,
        dark,
        width: 1440,
        height: 980,
        transform: pageTransform(playlist, watched),
      });
      try {
        const { page } = desktop;
        await servePlaylist(page, playlist);

        await desktop.goto('/');
        await page.waitForTimeout(400);
        const homeName = `home-${language}-${theme}-desktop.png`;
        await page.screenshot({ path: path.join(outDir, homeName), fullPage: false });
        record(homeName, await layoutProblems(page), desktop);

        const grid = page.locator('.home-page .content-section .video-grid').first();
        if (await grid.count()) {
          const cardsName = `cards-${language}-${theme}-desktop.png`;
          await grid.screenshot({ path: path.join(outDir, cardsName) });
          record(cardsName, await layoutProblems(page), desktop);
        }

        const navName = `navigation-${language}-${theme}-desktop.png`;
        await page.locator('#website-sidebar').screenshot({ path: path.join(outDir, navName) });
        record(navName, [], desktop);

        await desktop.goto(`/playlists?id=${playlist.id}`);
        await page.waitForTimeout(350);
        const seriesName = `series-${language}-${theme}-desktop.png`;
        await page.screenshot({ path: path.join(outDir, seriesName), fullPage: true });
        record(seriesName, await layoutProblems(page), desktop);

        await desktop.goto(`/watch/${playlist.videos[0].slug}`);
        await page.waitForTimeout(350);
        const watchName = `watch-${language}-${theme}-desktop.png`;
        await page.screenshot({ path: path.join(outDir, watchName), fullPage: false });
        record(watchName, await layoutProblems(page), desktop);
      } finally {
        await desktop.context.close();
      }

      const mobile = await layoutSession(browser, {
        authenticated: true,
        language,
        dark,
        width: 390,
        height: 844,
        touch: true,
        transform: pageTransform(playlist, watched),
      });
      try {
        const { page } = mobile;
        await servePlaylist(page, playlist);

        await mobile.goto('/');
        await page.waitForTimeout(400);
        const homeName = `home-${language}-${theme}-mobile.png`;
        await page.screenshot({ path: path.join(outDir, homeName), fullPage: false });
        record(homeName, await layoutProblems(page), mobile);

        const grid = page.locator('.home-page .content-section .video-grid').first();
        if (await grid.count()) {
          const cardsName = `cards-${language}-${theme}-mobile.png`;
          await grid.screenshot({ path: path.join(outDir, cardsName) });
          record(cardsName, await layoutProblems(page), mobile);
        }

        const menu = page.getByRole('button', { name: 'Open navigation' });
        if (await menu.count()) {
          await menu.click();
          await page.waitForTimeout(250);
          const navName = `navigation-${language}-${theme}-mobile.png`;
          await page.screenshot({ path: path.join(outDir, navName), fullPage: false });
          record(navName, await layoutProblems(page), mobile);
          await page.keyboard.press('Escape');
        }

        await mobile.goto(`/playlists?id=${playlist.id}`);
        await page.waitForTimeout(350);
        const seriesName = `series-${language}-${theme}-mobile.png`;
        await page.screenshot({ path: path.join(outDir, seriesName), fullPage: true });
        record(seriesName, await layoutProblems(page), mobile);

        await mobile.goto(`/watch/${playlist.videos[0].slug}`);
        await page.waitForTimeout(350);
        const watchName = `watch-${language}-${theme}-mobile.png`;
        await page.screenshot({ path: path.join(outDir, watchName), fullPage: false });
        record(watchName, await layoutProblems(page), mobile);
      } finally {
        await mobile.context.close();
      }
    }
  }
} finally {
  await browser.close();
}

console.log(JSON.stringify({ label, outDir, shots }, null, 2));
