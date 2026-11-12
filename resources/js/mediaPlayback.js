export function isPlaybackInterrupted(error) {
  return error?.name === 'AbortError' && error.playbackCancelled === true;
}

function playbackAbortError() {
  const error = new Error('Playback was cancelled.');
  error.name = 'AbortError';
  error.playbackCancelled = true;
  return error;
}

function nativeMediaElement(player) {
  return player?.provider?.media
    || player?.querySelector?.('video, audio')
    || player?.el?.querySelector?.('video, audio')
    || null;
}

// Vidstack's play() rejects before can-play. Native play() can buffer first,
// and calling it during the click also preserves the browser's user activation.
export function playMediaWhenReady(player, { signal, reload = false, timeoutMs = 20000 } = {}) {
  if (!player) return Promise.reject(new Error('The video player is unavailable.'));
  if (signal?.aborted) return Promise.reject(playbackAbortError());

  return new Promise((resolve, reject) => {
    const target = player.el || player;
    const media = nativeMediaElement(player);
    let restoreTime = reload && media?.error ? Math.max(0, Number(media.currentTime || player.currentTime || 0)) : 0;
    let started = false;
    let settled = false;
    let timer;
    const cleanup = () => {
      clearTimeout(timer);
      target.removeEventListener?.('can-play', onReady);
      target.removeEventListener?.('error', onError);
      media?.removeEventListener?.('loadedmetadata', restorePosition);
      signal?.removeEventListener('abort', onAbort);
    };
    const finish = (error) => {
      if (settled) return;
      settled = true;
      cleanup();
      if (error) reject(error);
      else resolve();
    };
    const onAbort = () => finish(playbackAbortError());
    const onError = (event) => finish(new Error(event.detail?.message || 'The video could not be loaded.'));
    const begin = (media = null) => {
      if (started || settled) return;
      started = true;
      try {
        const result = media ? media.play() : player.play();
        Promise.resolve(result).then(() => finish(), finish);
      } catch (error) {
        finish(error);
      }
    };
    const restorePosition = () => {
      if (!restoreTime || !media) return;
      const duration = Number(media.duration);
      try {
        media.currentTime = Number.isFinite(duration) && duration > 0 ? Math.min(restoreTime, Math.max(0, duration - 0.05)) : restoreTime;
        restoreTime = 0;
      } catch (_) {
        // A provider may not allow seeking until can-play.
      }
    };
    const onReady = () => { restorePosition(); begin(); };

    target.addEventListener?.('can-play', onReady);
    target.addEventListener?.('error', onError);
    signal?.addEventListener('abort', onAbort, { once: true });
    timer = setTimeout(() => {
      const error = new Error('The video took too long to load.');
      error.name = 'PlaybackTimeoutError';
      finish(error);
    }, timeoutMs);

    if (reload && media?.error) {
      media.addEventListener?.('loadedmetadata', restorePosition, { once: true });
      media.load();
    }
    if (player.state?.canPlay && !media?.error && !(reload && media?.readyState === 0)) begin();
    // HLS/DASH must initialize their manifest, audio groups and text renderer
    // before native play. A native early canplay can otherwise bypass setup.
    else if (media && !['hls', 'dash'].includes(player.provider?.type)
      && !/mpegurl|dash\+xml/i.test(String(player.state?.source?.type || ''))
      && (media.currentSrc || media.getAttribute?.('src'))) begin(media);
    else player.startLoading?.();
  });
}
