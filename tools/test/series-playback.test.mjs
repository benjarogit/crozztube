import test from 'node:test';
import assert from 'node:assert/strict';
import {
  resolveNextPlayback,
  episodeLabel,
  episodeTitle,
  PLAYBACK_SOURCE_AUTOPLAY_OFF,
  PLAYBACK_SOURCE_DISCOVER,
  PLAYBACK_SOURCE_NONE,
  PLAYBACK_SOURCE_PLAYLIST,
  PLAYBACK_SOURCE_QUEUE,
  PLAYBACK_SOURCE_SERIES,
} from '../../resources/js/seriesPlayback.js';

const episode = (slug, season = 1, number = 1) => ({ slug, season_number: season, episode_number: number, title: slug });

test('autoplay off never suggests another video', () => {
  const result = resolveNextPlayback({
    queue: [episode('queued')],
    seriesNext: episode('next-episode'),
    autoplayEnabled: false,
  });
  assert.equal(result.item, null);
  assert.equal(result.source, PLAYBACK_SOURCE_AUTOPLAY_OFF);
});

test('the personal queue keeps priority over the next episode', () => {
  const result = resolveNextPlayback({
    queue: [episode('queued-1'), episode('queued-2')],
    seriesNext: episode('next-episode', 2, 4),
    playlistNext: episode('playlist-next'),
    discover: [episode('related')],
  });
  assert.equal(result.item.slug, 'queued-1');
  assert.equal(result.source, PLAYBACK_SOURCE_QUEUE);
});

test('the next episode follows the queue and beats the rest of the playlist', () => {
  const result = resolveNextPlayback({
    queue: [],
    seriesNext: episode('next-episode', 2, 4),
    playlistNext: episode('playlist-next'),
    discover: [episode('related')],
  });
  assert.equal(result.item.slug, 'next-episode');
  assert.equal(result.source, PLAYBACK_SOURCE_SERIES);
});

test('a playlist without series metadata still advances inside the playlist', () => {
  const result = resolveNextPlayback({
    queue: [],
    seriesNext: null,
    playlistNext: episode('playlist-next'),
    discover: [episode('related')],
  });
  assert.equal(result.item.slug, 'playlist-next');
  assert.equal(result.source, PLAYBACK_SOURCE_PLAYLIST);
});

test('discovery is the last fallback', () => {
  const result = resolveNextPlayback({ queue: [], discover: [episode('related')] });
  assert.equal(result.item.slug, 'related');
  assert.equal(result.source, PLAYBACK_SOURCE_DISCOVER);
});

test('empty sources report none instead of failing', () => {
  const result = resolveNextPlayback({});
  assert.equal(result.item, null);
  assert.equal(result.source, PLAYBACK_SOURCE_NONE);
});

test('the current video and slugless entries are skipped', () => {
  const result = resolveNextPlayback({
    currentSlug: 'current',
    queue: [{ title: 'no slug' }, episode('current'), episode('queued-after-current')],
    seriesNext: episode('current'),
  });
  assert.equal(result.item.slug, 'queued-after-current');
  assert.equal(result.source, PLAYBACK_SOURCE_QUEUE);
});

test('episode labels stay in the series context', () => {
  assert.equal(episodeLabel({ season_number: 2, episode_number: 7 }), 'S2 E7');
  assert.equal(episodeLabel({ season_number: 1 }, 4), 'S1 E4');
  assert.equal(episodeLabel(null), '');
  assert.equal(episodeTitle({ episode_title: 'Pilot', title: 'Video title' }), 'Pilot');
  assert.equal(episodeTitle({ episode_title: '', title: 'Video title' }), 'Video title');
});
