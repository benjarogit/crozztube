/** Preserve explicit queue items when playlist context changes. */
export function contextualQueue(queue, currentSlug, playlistId, series = false) {
  const unique = [...new Map((queue || []).filter(item => item?.slug && item.slug !== currentSlug).map(item => [item.slug, item])).values()];
  if (!playlistId) return unique;
  if (series) return unique.filter(item => item.queue_origin === 'explicit' || String(item.playlist_id || '') !== String(playlistId));
  return unique.filter(item => String(item.playlist_id || '') === String(playlistId));
}
export function nextSeriesEpisode(episodes, currentVideoId) {
  const index = (episodes || []).findIndex(e => String(e.video_id) === String(currentVideoId));
  if (index < 0) return null;
  return episodes.slice(index + 1).find(e => e.available && e.video?.slug) || null;
}
export function nextPlaybackTarget({ queue = [], currentSlug, playlistId, series = null, videoId, playlistVideos = [], related = [] }) {
  const queued = contextualQueue(queue, currentSlug, playlistId, Boolean(series?.is_series));
  const queuedNext = queued[0];
  if (queuedNext) return { video: queuedNext, list: queuedNext.playlist_id || '', fromQueue: true };
  if (series?.is_series) { const episode = nextSeriesEpisode(series.episodes, videoId); return episode ? {video: episode.video, list:playlistId,fromQueue:false} : null; }
  const index = playlistVideos.findIndex(v => v.slug === currentSlug);
  const video = playlistId ? (index >= 0 ? playlistVideos[index + 1] : null) : related.find(v => v.slug && v.slug !== currentSlug);
  return video ? { video, list:playlistId || '', fromQueue:false } : null;
}

/** The service rechecks current membership, privacy and video availability. */
export async function freshSeriesTarget(api, { playlistId, currentSlug, videoId, queue, shuffled = false }) {
  const candidates = contextualQueue(queue, currentSlug, playlistId, true).filter(item => Number.isSafeInteger(Number(item.id)) && Number(item.id) > 0).slice(0, 50);
  const params = new URLSearchParams({ after: String(videoId) });
  candidates.forEach(item => params.append('queue[]', String(item.id)));
  const { next_episode: episode } = await api.request('/playlists/' + encodeURIComponent(playlistId) + '/series/next?' + params);
  if (!episode?.video || (shuffled && !episode.from_queue)) return null;
  const queued = episode.from_queue ? candidates.find(item => Number(item.id) === Number(episode.video_id)) : null;
  return { video: episode.video, list: episode.from_queue ? queued?.playlist_id || '' : playlistId, order: queued?.playlist_order, fromQueue: Boolean(episode.from_queue) };
}
/** Series forms show the actionable field explanation from either API envelope. */
export function seriesRequestError(error, fallback) {
  const fields = error?.payload?.error?.validation || error?.payload?.errors;
  const detail = fields && typeof fields === 'object'
    ? Object.values(fields).flatMap(value => Array.isArray(value) ? value : [])
      .find(value => typeof value === 'string' && value.trim())
    : null;
  return detail || error?.message || fallback;
}
