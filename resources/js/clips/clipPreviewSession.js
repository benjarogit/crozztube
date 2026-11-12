const SEEK_SETTLE_MS = 5000;

const waitForEvent = (media, success, failure = [], timeout = SEEK_SETTLE_MS) => new Promise((resolve, reject) => {
  let settled = false;
  const finish = (handler) => (event) => {
    if (settled) return;
    settled = true;
    window.clearTimeout(timer);
    media.removeEventListener(success, onSuccess);
    failure.forEach((name) => media.removeEventListener(name, onFailure));
    handler(event);
  };
  const onSuccess = finish(() => resolve());
  const onFailure = finish(() => reject(new Error('The media preview could not seek.')));
  const timer = window.setTimeout(finish(() => reject(new Error('The media preview seek timed out.'))), timeout);
  media.addEventListener(success, onSuccess);
  failure.forEach((name) => media.addEventListener(name, onFailure));
});

export function snapshotPlayback(media) {
  if (!media || typeof media.pause !== 'function') return null;
  return {
    currentTime: Number(media.currentTime || 0),
    paused: Boolean(media.paused),
    playbackRate: Number(media.playbackRate) || 1,
  };
}

export async function restorePlayback(media, snapshot) {
  if (!media || !snapshot) return;
  try {
    media.pause();
  } catch {
    // Ignore players that reject pause during teardown.
  }
  media.playbackRate = snapshot.playbackRate || 1;
  const target = Math.max(0, Number(snapshot.currentTime) || 0);
  if (Math.abs(Number(media.currentTime || 0) - target) > 0.2) {
    try {
      media.currentTime = target;
      if (media.seeking) await waitForEvent(media, 'seeked', ['error']);
    } catch {
      // Leave the media at the best available position.
    }
  }
  if (!snapshot.paused) {
    try {
      await media.play();
    } catch {
      // Autoplay may be blocked after the dialog closes.
    }
  }
}

export function canMirrorMedia(media) {
  return Boolean(
    media
    && typeof media.play === 'function'
    && typeof media.pause === 'function'
    && media.readyState >= 1
    && Number.isFinite(Number(media.duration))
    && Number(media.duration) > 0
    && Number(media.videoWidth || 0) > 0
    && Number(media.videoHeight || 0) > 0
  );
}

export function paintMediaFrame(canvas, media) {
  if (!canvas || !canMirrorMedia(media)) return false;
  const width = Math.max(1, Number(media.videoWidth) || 16);
  const height = Math.max(1, Number(media.videoHeight) || 9);
  if (canvas.width !== width || canvas.height !== height) {
    canvas.width = width;
    canvas.height = height;
  }
  const context = canvas.getContext('2d', { alpha: false });
  if (!context) return false;
  try {
    context.drawImage(media, 0, 0, canvas.width, canvas.height);
    return true;
  } catch {
    return false;
  }
}

export async function seekMedia(media, seconds) {
  if (!media) return false;
  const duration = Number(media.duration);
  const target = Math.max(0, Math.min(Number.isFinite(duration) ? duration : Number(seconds) || 0, Number(seconds) || 0));
  if (Math.abs(Number(media.currentTime || 0) - target) <= 0.05) return true;
  try {
    media.currentTime = target;
    if (media.seeking) await waitForEvent(media, 'seeked', ['error']);
    return true;
  } catch {
    return false;
  }
}

export function createPreviewFrameLoop(media, onFrame) {
  let cancelled = false;
  let frameHandle = null;
  let usingVideoCallback = false;

  const stop = () => {
    cancelled = true;
    if (frameHandle == null) return;
    if (usingVideoCallback && typeof media?.cancelVideoFrameCallback === 'function') {
      media.cancelVideoFrameCallback(frameHandle);
    } else {
      window.cancelAnimationFrame(frameHandle);
    }
    frameHandle = null;
  };

  const tick = () => {
    if (cancelled) return;
    onFrame();
    if (cancelled) return;
    if (typeof media?.requestVideoFrameCallback === 'function') {
      usingVideoCallback = true;
      frameHandle = media.requestVideoFrameCallback(() => tick());
      return;
    }
    usingVideoCallback = false;
    frameHandle = window.requestAnimationFrame(() => tick());
  };

  tick();
  return stop;
}

export function createSeekScheduler(seek, delay = 160) {
  let timer = null;
  let latest = null;
  const flush = async () => {
    timer = null;
    const value = latest;
    latest = null;
    if (value == null) return;
    await seek(value);
  };
  return {
    schedule(seconds) {
      latest = seconds;
      if (timer != null) window.clearTimeout(timer);
      timer = window.setTimeout(() => {
        flush();
      }, delay);
    },
    async commit(seconds) {
      if (timer != null) {
        window.clearTimeout(timer);
        timer = null;
      }
      latest = null;
      await seek(seconds);
    },
    cancel() {
      if (timer != null) window.clearTimeout(timer);
      timer = null;
      latest = null;
    },
  };
}
