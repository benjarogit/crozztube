import ThumbnailImage, { useThumbnailUrl } from './components/ThumbnailImage.jsx';
import React, { useEffect, useMemo, useRef, useState } from 'react';
import { createRoot } from 'react-dom/client';
import { MediaPlayer, MediaProvider, Track } from '@vidstack/react';
import { DefaultVideoGestures, DefaultVideoLayout, defaultLayoutIcons } from '@vidstack/react/player/layouts/default';
import { isPlaybackInterrupted, playMediaWhenReady } from './mediaPlayback.js';
import { inferVideoType, videoStreamType } from './videoSource.js';
import '@vidstack/react/player/styles/default/theme.css';
import '@vidstack/react/player/styles/default/layouts/video.css';
import './embed-player.css';

const config = window.__PLAYHUB_EMBED__ || {};

function numberParameter(params, name) {
  const value = Number(params.get(name));
  return Number.isFinite(value) && value > 0 ? Math.floor(value) : 0;
}

function booleanParameter(params, name, fallback = false) {
  const value = params.get(name);
  if (value === null) return fallback;
  return ['1', 'true', 'yes'].includes(value.toLowerCase());
}

function videoSources(video) {
  const sources = Array.isArray(video?.video_sources) && video.video_sources.length
    ? video.video_sources
    : video?.video_url ? [{ src: video.video_url, type: video.video_mime_type }] : [];

  return sources
    .map((source) => ({ ...source, src: source.src || source.url, type: inferVideoType(source) }))
    .filter((source) => source.src);
}

function externalEmbedUrl(value, options) {
  if (!value) return '';
  try {
    const url = new URL(value);
    const host = url.hostname.toLowerCase();
    if (host === 'www.dailymotion.com' || host === 'dailymotion.com') {
      if (options.start) url.searchParams.set('start', String(options.start));
      url.searchParams.set('autoplay', options.autoplay ? '1' : '0');
      url.searchParams.set('mute', options.muted ? '1' : '0');
    } else if (host === 'player.twitch.tv') {
      url.searchParams.set('autoplay', options.autoplay ? 'true' : 'false');
      url.searchParams.set('muted', options.muted ? 'true' : 'false');
      if (options.start && url.searchParams.has('video')) {
        const hours = Math.floor(options.start / 3600);
        const minutes = Math.floor((options.start % 3600) / 60);
        const seconds = options.start % 60;
        url.searchParams.set('time', `${hours ? `${hours}h` : ''}${minutes ? `${minutes}m` : ''}${seconds}s`);
      }
    }
    return url.toString();
  } catch (_) {
    return '';
  }
}

async function requestVideo(slug) {
  const headers = new Headers({ Accept: 'application/json' });
  try {
    const token = localStorage.getItem('playhub_token') || sessionStorage.getItem('playhub_token');
    if (token) headers.set('Authorization', `Bearer ${token}`);
  } catch (_) {}

  const response = await fetch(`/api/videos/${encodeURIComponent(slug)}`, {
    headers,
    credentials: 'same-origin',
  });
  const payload = await response.json().catch(() => ({}));
  if (!response.ok) throw new Error(payload.message || 'This video is unavailable.');
  return payload.video;
}

function EmbedPlayer() {
  const playerRef = useRef(null);
  const playbackRequestRef = useRef(null);
  const startAppliedRef = useRef(false);
  const [video, setVideo] = useState(null);
  const resolvedThumbnail = useThumbnailUrl(video || {}, config.thumbnailUrl);
  const [error, setError] = useState('');
  const [playbackError, setPlaybackError] = useState('');
  const [playerReady, setPlayerReady] = useState(false);
  const [started, setStarted] = useState(false);
  const [starting, setStarting] = useState(false);
  const [autoplayBlocked, setAutoplayBlocked] = useState(false);
  const params = useMemo(() => new URLSearchParams(window.location.search), []);
  const options = useMemo(() => ({
    autoplay: booleanParameter(params, 'autoplay'),
    muted: booleanParameter(params, 'muted', booleanParameter(params, 'mute')),
    loop: booleanParameter(params, 'loop'),
    controls: !['0', 'false'].includes(String(params.get('controls') || '').toLowerCase()),
    start: numberParameter(params, 'start') || numberParameter(params, 't'),
  }), [params]);

  useEffect(() => {
    let cancelled = false;
    requestVideo(config.slug)
      .then((nextVideo) => {
        if (cancelled) return;
        setVideo(nextVideo);
        document.title = `${nextVideo.title} - ${config.siteName || 'PlayHub'}`;
      })
      .catch((requestError) => {
        if (!cancelled) setError(requestError.message || 'This video is unavailable.');
      });
    return () => {
      cancelled = true;
      playbackRequestRef.current?.abort();
    };
  }, []);

  const sources = useMemo(() => videoSources(video), [video]);
  const providerEmbedUrl = useMemo(() => externalEmbedUrl(video?.embed_url, options), [video?.embed_url, options]);
  const captionType = String(video?.captions_url || '').split('?')[0].split('.').pop()?.toLowerCase();
  const caption = video?.captions_url ? {
    src: video.captions_url,
    type: ['vtt', 'srt', 'ssa', 'ass'].includes(captionType) ? captionType : 'vtt',
    kind: 'subtitles',
    label: video.language || 'Captions',
    lang: video.language_code || 'en',
    default: true,
  } : null;

  const applyStartTime = () => {
    if (!options.start || startAppliedRef.current || !playerRef.current) return;
    startAppliedRef.current = true;
    try { playerRef.current.currentTime = options.start; } catch (_) {}
  };

  const handleCanPlay = () => {
    applyStartTime();
    setPlayerReady(true);
    setPlaybackError('');
  };

  const startPlayback = async () => {
    if (!playerRef.current || starting) return;
    playbackRequestRef.current?.abort();
    const request = new AbortController();
    playbackRequestRef.current = request;
    setStarting(true);
    setPlaybackError('');
    try {
      await playMediaWhenReady(playerRef.current, { signal: request.signal, reload: true });
    } catch (playError) {
      if (!request.signal.aborted && !isPlaybackInterrupted(playError)) setPlaybackError('Unable to play this video. Click to retry.');
    } finally {
      if (playbackRequestRef.current === request && !request.signal.aborted) {
        playbackRequestRef.current = null;
        setStarting(false);
      }
    }
  };

  if (error) return <PlayerState video={video} message={error} />;
  if (!video) return <PlayerState loading />;
  if (video.sale?.is_paid && !video.sale?.has_access) {
    return <PlayerState video={video} message="Purchase this video on PlayHub to watch it." action="Watch on PlayHub" />;
  }
  if (providerEmbedUrl) {
    return <iframe className="embed-provider-player" src={providerEmbedUrl} title={video.title} allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" referrerPolicy="strict-origin-when-cross-origin" allowFullScreen />;
  }
  if (!sources.length) return <PlayerState video={video} message="This video does not have a playable source." />;

  return <div className="embed-player-shell">
    <MediaPlayer ref={playerRef} className="embed-media-player" src={sources} title={video.title} poster={resolvedThumbnail || undefined} preload="metadata" load="eager" autoPlay={options.autoplay} muted={options.muted} loop={options.loop} playsInline viewType="video" streamType={videoStreamType(sources)} onCanPlay={handleCanPlay} onLoadedMetadata={applyStartTime} onPause={() => { if ((playerRef.current?.provider?.media || playerRef.current?.querySelector?.('video, audio'))?.paused !== false) { playbackRequestRef.current?.abort(); playbackRequestRef.current = null; setStarting(false); } }} onPlay={() => setStarting(true)} onPlaying={() => { setStarted(true); setStarting(false); setAutoplayBlocked(false); setPlaybackError(''); }} onAutoPlayFail={() => { setAutoplayBlocked(true); setStarting(false); }} onError={() => { setPlayerReady(false); setStarting(false); setPlaybackError('Unable to play this video. Click to retry.'); }}>
      <MediaProvider mediaProps={{ 'x-webkit-airplay': 'allow' }}>
        {caption && <Track {...caption} />}
      </MediaProvider>
      {options.controls ? <DefaultVideoLayout icons={defaultLayoutIcons} /> : <DefaultVideoGestures />}
    </MediaPlayer>
    {(playbackError || (!started && (!options.autoplay || autoplayBlocked))) && <button type="button" className="embed-poster-play" onClick={startPlayback} disabled={starting || (!playerReady && !playbackError)} aria-busy={starting || (!playerReady && !playbackError)} aria-label={playbackError ? `Retry ${video.title}` : playerReady ? `Play ${video.title}` : `Loading ${video.title}`}>
      {video.thumbnail_url && <ThumbnailImage video={video} alt="" />}
      <span className="embed-poster-shade" />
      {!starting && (playerReady || playbackError) ? <span className="embed-play-icon" aria-hidden="true"><i /></span> : <span className="embed-spinner" aria-hidden="true" />}
      {playbackError && <strong className="embed-playback-error" role="alert">{playbackError}</strong>}
    </button>}
  </div>;
}

function PlayerState({ loading = false, video = null, message = '', action = '' }) {
  const poster = video?.thumbnail_url || config.thumbnailUrl;
  return <section className="embed-state" role={loading ? 'status' : 'alert'}>
    {poster && <ThumbnailImage video={video || {}} src={poster} alt="" />}
    <span className="embed-state-shade" />
    <div className="embed-state-content">
      {loading ? <span className="embed-spinner" aria-label="Loading video" /> : <>
        <strong>{message}</strong>
        {action && <a href={config.watchUrl} target="_blank" rel="noopener noreferrer">{action}</a>}
      </>}
    </div>
  </section>;
}

if (config.primaryColor && CSS.supports('color', config.primaryColor)) {
  document.documentElement.style.setProperty('--embed-accent', config.primaryColor);
}
if (config.fontFamily && CSS.supports('font-family', config.fontFamily)) {
  document.documentElement.style.setProperty('--embed-font', config.fontFamily);
}

createRoot(document.getElementById('embed-player-root')).render(<EmbedPlayer />);
