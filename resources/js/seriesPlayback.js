/**
 * Playback priority for a series, playlist or plain watch page.
 *
 * The personal queue always wins: a viewer who queued videos expects those
 * before an autoplay suggestion. Only then does the next episode follow, then
 * the rest of the playlist, then discovery. Autoplay is an explicit switch,
 * so nothing is returned while it is off.
 *
 * Kept free of React and browser APIs so the rule can be tested directly.
 */
export const PLAYBACK_SOURCE_QUEUE = 'queue';
export const PLAYBACK_SOURCE_SERIES = 'series';
export const PLAYBACK_SOURCE_PLAYLIST = 'playlist';
export const PLAYBACK_SOURCE_DISCOVER = 'discover';
export const PLAYBACK_SOURCE_NONE = 'none';
export const PLAYBACK_SOURCE_AUTOPLAY_OFF = 'autoplay-off';

const usable = (item, currentSlug) => Boolean(item?.slug) && String(item.slug) !== String(currentSlug || '');

export function resolveNextPlayback({
  queue = [],
  seriesNext = null,
  playlistNext = null,
  discover = [],
  currentSlug = '',
  autoplayEnabled = true,
} = {}) {
  if (!autoplayEnabled) return { item: null, source: PLAYBACK_SOURCE_AUTOPLAY_OFF };

  const queued = (queue || []).find((item) => usable(item, currentSlug));
  if (queued) return { item: queued, source: PLAYBACK_SOURCE_QUEUE };

  if (usable(seriesNext, currentSlug)) return { item: seriesNext, source: PLAYBACK_SOURCE_SERIES };

  if (usable(playlistNext, currentSlug)) return { item: playlistNext, source: PLAYBACK_SOURCE_PLAYLIST };

  const discovered = (discover || []).find((item) => usable(item, currentSlug));
  if (discovered) return { item: discovered, source: PLAYBACK_SOURCE_DISCOVER };

  return { item: null, source: PLAYBACK_SOURCE_NONE };
}

/** Human label for an episode row; falls back to the position in the season. */
export function episodeLabel(episode, fallbackNumber = null) {
  if (!episode) return '';
  const season = Number(episode.season_number || 1);
  const number = Number(episode.episode_number || fallbackNumber || 0);
  if (!number) return '';
  return `S${season} E${number}`;
}

/** Episode title falls back to the video title so a series never shows blanks. */
export function episodeTitle(episode) {
  return String(episode?.episode_title || episode?.title || '').trim();
}
