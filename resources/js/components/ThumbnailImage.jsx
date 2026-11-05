import React, { useEffect, useState } from 'react';

export function thumbnailCandidates(video = {}, src) {
  return [...new Set([src, video.thumbnail_url, video.poster_url, ...(video.thumbnail_candidates || []), video.fallback_thumbnail_url]
    .filter((url) => typeof url === 'string' && /^(https?:\/\/|\/media\/|blob:|data:image\/(?:jpeg|png|webp|gif);)/i.test(url)))];
}

export function BlankThumbnail({ className = '', ...props }) {
  return <svg className={`crz-thumbnail-empty ${className}`} viewBox="0 0 160 90" role="img" aria-label="Preview unavailable" {...props}>
    <rect width="160" height="90" fill="#272a30" />
    <path d="M64 57V33h32v24H64Zm2-2h28L84 43l-8 8-4-4-6 8Z" fill="#858b95" />
  </svg>;
}

export default function ThumbnailImage({ video = {}, src, onError, ...props }) {
  const candidates = thumbnailCandidates(video, src);
  const identity = JSON.stringify(candidates);
  const [failed, setFailed] = useState([]);
  useEffect(() => setFailed([]), [identity]);
  const current = candidates.find((url) => !failed.includes(url));
  if (!current) return <BlankThumbnail className={props.className} />;
  return <img {...props} src={current} data-thumbnail-fallback="" onError={() => setFailed((old) => [...old, current])} />;
}

/** Native video posters do not emit reliable image errors; resolve them in-browser. */
export function useThumbnailUrl(video = {}, src) {
  const identity = JSON.stringify(thumbnailCandidates(video, src));
  const [resolved, setResolved] = useState({ identity: '', url: '' });
  useEffect(() => {
    let cancelled = false;
    const candidates = JSON.parse(identity);
    let index = 0;
    let image;
    const next = () => {
      if (cancelled) return;
      const url = candidates[index++];
      if (!url) { setResolved({ identity, url: '' }); return; }
      image = new Image();
      image.onload = () => { if (!cancelled) setResolved({ identity, url }); };
      image.onerror = next;
      image.src = url;
    };
    next();
    return () => { cancelled = true; if (image) { image.onload = null; image.onerror = null; } };
  }, [identity]);
  return resolved.identity === identity ? resolved.url : JSON.parse(identity)[0] || '';
}
