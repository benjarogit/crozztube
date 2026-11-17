/**
 * Series detail and watch design evidence.
 *
 * Renders the real series surfaces against read-only API responses. The
 * playlist payload is a deliberate fixture because the local database has no
 * published multi-season series; video fields still come from the real API, so
 * durations, channels and thumbnails stay realistic. No request writes data.
 *
 * Usage: node tools/test/ui/series-shots.mjs [outputDir]
 */
import fs from 'node:fs';
import path from 'node:path';
import { chromium } from '@playwright/test';
import { applicationJson, layoutSession, layoutProblems } from './fixtures/readonly-layout.ts';

const outDir = process.argv[2] || '/tmp/opencode/shots/series';
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

const seriesId = 987654321;

/**
 * The local database has no published multi-season series, so the playlist
 * endpoints are answered from a fixture. This route only touches playlist
 * paths; every other API call still goes to the read-only fixture.
 */
const servePlaylist = (page, playlist) => page.route('**/api/playlists**', async (route) => {
  const path = new URL(route.request().url()).pathname;
  if (path === '/api/playlists') {
    await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ playlists: [{ ...playlist, videos: [] }] }) });
    return;
  }
  if (path === `/api/playlists/${playlist.id}`) {
    await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ playlist, viewer_role: 'viewer', vote_counts: {}, members: [], pending_invite: false }) });
    return;
  }
  await route.fallback();
});

const continueTransform = (watched) => (apiPath, payload) => {
  if (apiPath.startsWith('/api/continue-watching')) {
    return { ...payload, data: [watched] };
  }
  return payload;
};

const watchTransform = (playlist) => (apiPath, payload) => {
  if (apiPath.startsWith('/api/playlists/987654321')) {
    return { ...payload, playlist, viewer_role: 'viewer', vote_counts: {}, members: [], pending_invite: false };
  }
  const match = apiPath.match(/^\/api\/videos\/([^?]+)/);
  if (match) {
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
        current: { slug: current.slug, video_id: current.id, title: current.title, season_number: current.season_number, episode_number: current.episode_number, episode_title: current.episode_title, label: current.episode_label },
        next: playlist.videos[1] ? { slug: playlist.videos[1].slug, video_id: playlist.videos[1].id, title: playlist.videos[1].title, season_number: playlist.videos[1].season_number, episode_number: playlist.videos[1].episode_number, episode_title: playlist.videos[1].episode_title, label: playlist.videos[1].episode_label } : null,
        seasons: [],
      },
    };
  }
  return payload;
};

const shots = [];
const browser = await chromium.launch({ headless: true });
try {
  for (const language of ['en', 'de']) {
    const playlist = playlistFor(language);
    const watched = resume(language);
    for (const dark of [false, true]) {
      const session = await layoutSession(browser, {
        authenticated: true, language, dark, width: 1440, height: 1000,
        transform: continueTransform(watched),
      });
      try {
        await servePlaylist(session.page, playlist);
        await session.goto(`/playlists?id=${playlist.id}`);
        await session.page.waitForTimeout(350);
        const name = `series-${language}-${dark ? 'dark' : 'light'}.png`;
        const problems = await layoutProblems(session.page);
        await session.page.screenshot({ path: path.join(outDir, name), fullPage: true });
        shots.push({ name, problems: problems.length ? problems : 'none', writes: session.writes, errors: session.errors });
      } finally {
        await session.context.close();
      }
    }
    const mobile = await layoutSession(browser, {
      authenticated: true, language, dark: false, width: 390, height: 844,
      transform: continueTransform(watched),
    });
    try {
      await servePlaylist(mobile.page, playlist);
      await mobile.goto(`/playlists?id=${playlist.id}`);
      await mobile.page.waitForTimeout(350);
      const name = `series-mobile-${language}-light.png`;
      const problems = await layoutProblems(mobile.page);
      await mobile.page.screenshot({ path: path.join(outDir, name), fullPage: true });
      shots.push({ name, problems: problems.length ? problems : 'none', writes: mobile.writes, errors: mobile.errors });
      const actions = await mobile.page.locator('.playlist-actions .btn').evaluateAll((nodes) => nodes.map((node) => node.textContent.trim()));
      await mobile.page.locator('.playlist-actions').scrollIntoViewIfNeeded();
      await mobile.page.waitForTimeout(150);
      const actionsName = `series-mobile-actions-${language}.png`;
      await mobile.page.screenshot({ path: path.join(outDir, actionsName), fullPage: false });
      shots.push({ name: actionsName, actions, problems: (await layoutProblems(mobile.page)).length ? 'layout' : 'none' });
    } finally {
      await mobile.context.close();
    }

    const watch = await layoutSession(browser, {
      authenticated: true, language, dark: false, width: 1440, height: 1000,
      transform: watchTransform(playlist),
    });
    try {
      await watch.goto(`/watch/${playlist.videos[0].slug}`);
      await watch.page.waitForTimeout(350);
      const name = `watch-${language}-light.png`;
      const problems = await layoutProblems(watch.page);
      await watch.page.screenshot({ path: path.join(outDir, name), fullPage: false });
      shots.push({ name, problems: problems.length ? problems : 'none', writes: watch.writes, errors: watch.errors });
      await watch.page.setViewportSize({ width: 390, height: 844 });
      await watch.page.waitForTimeout(250);
      const mobileName = `watch-mobile-${language}-light.png`;
      const mobileProblems = await layoutProblems(watch.page);
      await watch.page.screenshot({ path: path.join(outDir, mobileName), fullPage: false });
      shots.push({ name: mobileName, problems: mobileProblems.length ? mobileProblems : 'none' });
    } finally {
      await watch.context.close();
    }
  }
} finally {
  await browser.close();
}

console.log(JSON.stringify({ outDir, shots }, null, 2));
