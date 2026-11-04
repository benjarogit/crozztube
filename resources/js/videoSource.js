// Use the URL path only: signed URLs and fragments must not become extensions.
export function inferVideoType(source) {
  const type = source?.type || source?.mime_type || source?.mimeType || source?.video_mime_type;
  if (type) return type;
  const path = String(source?.src || source?.url || source?.video_url || '').split(/[?#]/, 1)[0].toLowerCase();
  const extension = path.match(/\.([a-z0-9]+)$/)?.[1];
  return ({
    mp4: 'video/mp4', m4v: 'video/mp4', webm: 'video/webm', mov: 'video/quicktime',
    mkv: 'video/x-matroska', ogv: 'video/ogg', ogg: 'video/ogg', avi: 'video/x-msvideo',
    mpeg: 'video/mpeg', mpg: 'video/mpeg', '3gp': 'video/3gpp', '3gpp': 'video/3gpp',
    m3u8: 'application/vnd.apple.mpegurl', mpd: 'application/dash+xml',
  })[extension] || 'video/mp4';
}

export function videoStreamType(sources) {
  // Vidstack's "unknown" lets its HLS/DASH provider infer live, DVR or VOD from
  // the manifest. A forced on-demand prop overrides that inference.
  return sources?.some((source) => /mpegurl|dash\+xml/i.test(inferVideoType(source)))
    ? 'unknown'
    : 'on-demand';
}
