/** Progress bars tolerate legacy values; remaining time requires actual seconds. */
export function continueWatchingState(video = {}) {
  const percent = Number(video.progress);
  const progress = Number.isFinite(percent) ? Math.round(Math.min(100, Math.max(0, percent))) : 0;
  const duration = Number(video.duration_seconds);
  const position = Number(video.current_time);
  const reliable = video.duration_seconds != null && video.current_time != null
    && Number.isFinite(duration) && duration > 0 && Number.isFinite(position) && position >= 0;
  return { progress, remaining: reliable ? Math.ceil(Math.max(0, duration - position)) : null };
}
