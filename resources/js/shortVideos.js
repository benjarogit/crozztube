export const MAX_SHORT_SECONDS = 60;

export function qualifiesShort(duration, width, height, sourceType = '') {
  const seconds = Number(duration), w = Number(width), h = Number(height);
  return sourceType !== 'live_replay' && Number.isFinite(seconds) && seconds > 0 && seconds <= MAX_SHORT_SECONDS
    && Number.isFinite(w) && Number.isFinite(h) && w > 0 && h > w;
}

export function isShortVideoRecord(video) {
  if (!video || video.source_type === 'live_replay') return false;
  const seconds = Number(video.duration_seconds || 0), width = Number(video.video_width || 0), height = Number(video.video_height || 0);
  if (seconds > MAX_SHORT_SECONDS || (width > 0 && height > 0 && height <= width)) return false;
  if (seconds > 0 && width > 0 && height > 0) return qualifiesShort(seconds, width, height, video.source_type);
  // Thin route/queue snapshots may omit media metadata. A server-derived flag
  // is usable there, but can never override contradictory duration or shape.
  return video.is_short === true;
}
