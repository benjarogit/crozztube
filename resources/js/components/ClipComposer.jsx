import React, { useEffect, useMemo, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { useI18n } from '../i18n.jsx';
import {
  canMirrorMedia,
  createPreviewFrameLoop,
  createSeekScheduler,
  paintMediaFrame,
  restorePlayback,
  seekMedia,
  snapshotPlayback,
  waitForPresentedFrame,
} from '../clips/clipPreviewSession.js';
import './ClipComposer.css';

const fallbackCodes = new Set(['unsupported', 'invalid_source', 'source_unreadable', 'source_unavailable', 'source_timeout']);
const wait = (milliseconds, signal) => new Promise((resolve, reject) => {
  const timer = window.setTimeout(resolve, milliseconds);
  signal.addEventListener('abort', () => {
    window.clearTimeout(timer);
    reject(new DOMException('The clip was canceled.', 'AbortError'));
  }, { once: true });
});
const formatTimestamp = value => {
  const seconds = Math.max(0, Math.floor(Number(value) || 0));
  const hours = Math.floor(seconds / 3600);
  const minutes = Math.floor((seconds % 3600) / 60);
  const remainder = seconds % 60;
  return hours
    ? `${hours}:${String(minutes).padStart(2, '0')}:${String(remainder).padStart(2, '0')}`
    : `${minutes}:${String(remainder).padStart(2, '0')}`;
};
const clamp = (value, min, max) => Math.min(max, Math.max(min, value));

export default function ClipComposer({
  open,
  api,
  video,
  sourceUrl,
  getMediaElement,
  currentTime,
  duration,
  Icon,
  onClose,
  onCreated,
  onTimeLink,
  onCaptureStateChange,
}) {
  const { t } = useI18n();
  const dialogRef = useRef(null);
  const canvasRef = useRef(null);
  const abortRef = useRef(null);
  const transferRef = useRef('');
  const snapshotRef = useRef(null);
  const previewPlayingRef = useRef(false);
  const stopFrameLoopRef = useRef(null);
  const seekSchedulerRef = useRef(null);
  const phaseRef = useRef('idle');
  const formRef = useRef({ start: 0, end: 30 });
  const initialRange = useMemo(() => {
    const total = Math.max(0, Number(duration || video?.duration_seconds || 0));
    const requestedStart = Math.max(0, Math.floor(Number(currentTime || 0)));
    const start = total >= 5 ? Math.min(requestedStart, Math.max(0, Math.floor(total - 5))) : requestedStart;
    return { start, end: total ? Math.min(total, start + 30) : start + 30 };
  }, [open, currentTime, duration, video?.duration_seconds]);
  const [form, setForm] = useState({ title: '', visibility: 'private', ...initialRange });
  const [phase, setPhase] = useState('idle');
  const [progress, setProgress] = useState(0);
  const [error, setError] = useState('');
  const [fallback, setFallback] = useState(false);
  const [previewReady, setPreviewReady] = useState(false);
  const [previewPlaying, setPreviewPlaying] = useState(false);
  const [previewCursor, setPreviewCursor] = useState(initialRange.start);
  const busy = phase !== 'idle';
  const totalSeconds = Math.max(5, Math.floor(Number(duration || video?.duration_seconds || form.end || 5)));
  const clipSeconds = Math.max(5, Math.min(60, Number(form.end) - Number(form.start)));
  const detailSpan = Math.min(totalSeconds, 180);
  const [detailOrigin, setDetailOrigin] = useState(0);
  const detailStart = clamp(detailOrigin, 0, Math.max(0, totalSeconds - detailSpan));
  const detailEnd = Math.min(totalSeconds, detailStart + detailSpan);
  const detailWidth = Math.max(1, detailEnd - detailStart);
  const startPercent = ((Number(form.start) - detailStart) / detailWidth) * 100;
  const endPercent = ((Number(form.end) - detailStart) / detailWidth) * 100;
  const cursorInView = Number(previewCursor) >= detailStart && Number(previewCursor) <= detailEnd;
  const cursorPercent = cursorInView ? ((Number(previewCursor) - detailStart) / detailWidth) * 100 : startPercent;
  const overviewClip = totalSeconds ? (Number(form.start) / totalSeconds) * 100 : 0;
  const overviewClipWidth = totalSeconds ? Math.max((clipSeconds / totalSeconds) * 100, 0.8) : 100;
  const mountedMedia = getMediaElement?.();
  const directSourceEligible = (
    /^https?:\/\//i.test(sourceUrl || '')
    && !/\.(?:m3u8|mpd)(?:$|[?#])/i.test(sourceUrl)
  );
  const eligibleSource = directSourceEligible || (
    mountedMedia?.readyState >= 1
    && Number.isFinite(Number(mountedMedia.duration))
    && Number(mountedMedia.duration) > 0
  );

  formRef.current = form;
  phaseRef.current = phase;
  previewPlayingRef.current = previewPlaying;

  const stopPreviewLoop = () => {
    stopFrameLoopRef.current?.();
    stopFrameLoopRef.current = null;
  };

  const paintPreview = (media = getMediaElement?.()) => {
    const painted = paintMediaFrame(canvasRef.current, media);
    if (painted) {
      setPreviewReady(true);
      setPreviewCursor(Number(media?.currentTime || formRef.current.start));
    }
    return painted;
  };

  const syncPreviewToTime = async (seconds = Number(formRef.current.start)) => {
    const media = getMediaElement?.();
    if (!media || !canMirrorMedia(media)) {
      setPreviewReady(false);
      return false;
    }
    media.pause();
    const ok = await seekMedia(media, seconds);
    if (!ok) {
      setPreviewReady(false);
      return false;
    }
    await waitForPresentedFrame(media);
    const painted = paintPreview(media);
    setPreviewCursor(seconds);
    return painted;
  };

  const stopPreviewPlayback = async ({ resetToStart = true } = {}) => {
    const media = getMediaElement?.();
    previewPlayingRef.current = false;
    setPreviewPlaying(false);
    stopPreviewLoop();
    if (!media) return;
    try {
      media.pause();
    } catch {
      // Ignore players that reject pause during dialog teardown.
    }
    if (resetToStart) await syncPreviewToTime(Number(formRef.current.start));
  };

  const updateStart = startValue => {
    const length = clamp(Number(formRef.current.end) - Number(formRef.current.start), 5, 60);
    const start = clamp(Math.floor(Number(startValue) || 0), 0, Math.max(0, totalSeconds - length));
    setForm(current => ({ ...current, start, end: start + length }));
  };

  const updateEnd = endValue => {
    const start = Number(formRef.current.start);
    const end = clamp(Math.floor(Number(endValue) || 0), start + 5, Math.min(totalSeconds, start + 60));
    setForm(current => ({ ...current, end }));
  };

  const applyDuration = seconds => {
    const length = clamp(Math.floor(Number(seconds) || 30), 5, Math.min(60, totalSeconds));
    const start = Math.min(Number(formRef.current.start), Math.max(0, totalSeconds - length));
    setForm(current => ({ ...current, start, end: start + length }));
    return start;
  };

  useEffect(() => {
    if (!open) return undefined;
    setForm({ title: video?.title ? `${video.title} clip` : '', visibility: 'private', ...initialRange });
    const openedTotal = Math.max(5, Math.floor(Number(duration || video?.duration_seconds || initialRange.end || 5)));
    const openedSpan = Math.min(openedTotal, 180);
    const openedMid = (initialRange.start + initialRange.end) / 2;
    setDetailOrigin(clamp(Math.floor(openedMid - openedSpan / 2), 0, Math.max(0, openedTotal - openedSpan)));
    setPhase('idle');
    setProgress(0);
    setError('');
    setFallback(!eligibleSource);
    setPreviewReady(false);
    setPreviewPlaying(false);
    setPreviewCursor(initialRange.start);
    transferRef.current = '';
    const media = getMediaElement?.();
    snapshotRef.current = snapshotPlayback(media);
    onCaptureStateChange?.(true);
    if (media) {
      try {
        media.pause();
      } catch {
        // Keep the dialog usable even if the player rejects pause.
      }
    }
    seekSchedulerRef.current = createSeekScheduler(async (seconds) => {
      if (previewPlayingRef.current || phaseRef.current !== 'idle') return;
      await syncPreviewToTime(seconds);
    });
    window.requestAnimationFrame(() => {
      syncPreviewToTime(initialRange.start);
      dialogRef.current?.querySelector('.clip-composer-preview-toggle')?.focus();
    });
    const previous = document.activeElement;
    const keydown = event => {
      if (event.key === 'Escape') {
        close();
        return;
      }
      if (event.key !== 'Tab' || !dialogRef.current) return;
      const focusable = [...dialogRef.current.querySelectorAll('button:not(:disabled), input:not(:disabled), select:not(:disabled)')];
      if (!focusable.length) return;
      const first = focusable[0];
      const last = focusable[focusable.length - 1];
      if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
      }
    };
    document.addEventListener('keydown', keydown);
    return () => {
      document.removeEventListener('keydown', keydown);
      seekSchedulerRef.current?.cancel();
      seekSchedulerRef.current = null;
      stopPreviewLoop();
      const snapshot = snapshotRef.current;
      snapshotRef.current = null;
      restorePlayback(getMediaElement?.(), snapshot).finally(() => onCaptureStateChange?.(false));
      previous?.focus?.();
    };
  }, [open, video?.slug]);

  useEffect(() => () => {
    abortRef.current?.abort();
    if (transferRef.current) {
      api.request(`/clip-transfers/${transferRef.current}/cancel`, { method: 'POST', body: '{}' }).catch(() => {});
    }
  }, [api]);

  useEffect(() => {
    if (!open) return undefined;
    setDetailOrigin((current) => {
      const span = Math.min(totalSeconds, 180);
      const viewEnd = Math.min(totalSeconds, current + span);
      const pad = Math.min(12, Math.floor(span / 10));
      if (Number(form.start) >= current + pad && Number(form.end) <= viewEnd - pad) return current;
      const midpoint = (Number(form.start) + Number(form.end)) / 2;
      return clamp(Math.floor(midpoint - span / 2), 0, Math.max(0, totalSeconds - span));
    });
    return undefined;
  }, [open, form.start, form.end, totalSeconds]);

  useEffect(() => {
    if (!open || busy || previewPlaying || fallback) return undefined;
    seekSchedulerRef.current?.schedule(Number(form.start));
    setPreviewCursor(Number(form.start));
    return undefined;
  }, [open, form.start, form.end, busy, previewPlaying, fallback]);

  const placeClip = (startValue) => {
    const length = clamp(Number(formRef.current.end) - Number(formRef.current.start), 5, Math.min(60, totalSeconds));
    const start = clamp(Math.floor(Number(startValue) || 0), 0, Math.max(0, totalSeconds - length));
    setForm(current => ({ ...current, start, end: start + length }));
    return start;
  };

  const onDetailPointerDown = event => {
    if (busy || event.target.closest('input')) return;
    const rect = event.currentTarget.getBoundingClientRect();
    if (!rect.width) return;
    const ratio = clamp((event.clientX - rect.left) / rect.width, 0, 1);
    const time = detailStart + ratio * detailWidth;
    seekSchedulerRef.current?.commit(placeClip(time - clipSeconds / 2));
  };

  const close = async () => {
    abortRef.current?.abort();
    const transferId = transferRef.current;
    transferRef.current = '';
    if (transferId) {
      api.request(`/clip-transfers/${transferId}/cancel`, { method: 'POST', body: '{}' }).catch(() => {});
    }
    await stopPreviewPlayback({ resetToStart: false });
    onClose();
  };

  const togglePreviewPlayback = async () => {
    if (busy || fallback) return;
    const media = getMediaElement?.();
    if (!media || !canMirrorMedia(media)) {
      setPreviewReady(false);
      return;
    }
    if (previewPlayingRef.current) {
      await stopPreviewPlayback({ resetToStart: false });
      paintPreview(media);
      return;
    }
    const start = Number(formRef.current.start);
    const end = Number(formRef.current.end);
    const resumeFrom = Number(media.currentTime || start);
    const playFrom = resumeFrom >= end - 0.05 || resumeFrom < start ? start : resumeFrom;
    media.pause();
    const ok = await seekMedia(media, playFrom);
    if (!ok) {
      setPreviewReady(false);
      return;
    }
    paintPreview(media);
    previewPlayingRef.current = true;
    setPreviewPlaying(true);
    stopPreviewLoop();
    stopFrameLoopRef.current = createPreviewFrameLoop(media, () => {
      const current = Number(media.currentTime || 0);
      paintPreview(media);
      if (current >= end - 0.05 || media.ended) {
        media.pause();
        previewPlayingRef.current = false;
        setPreviewPlaying(false);
        stopPreviewLoop();
        seekMedia(media, end).then(() => paintPreview(media));
        setPreviewCursor(end);
      }
    });
    try {
      await media.play();
    } catch {
      await stopPreviewPlayback({ resetToStart: true });
    }
  };

  const create = async event => {
    event.preventDefault();
    setError('');
    setFallback(false);
    setProgress(0);
    await stopPreviewPlayback({ resetToStart: false });
    const controller = new AbortController();
    abortRef.current = controller;
    let clip;
    try {
      setPhase('encoding');
      const { encodeClip } = await import('../clips/clipEncoder.js');
      clip = await encodeClip({
        sourceUrl,
        mediaElement: directSourceEligible ? null : getMediaElement?.(),
        start: Number(form.start),
        end: Number(form.end),
        signal: controller.signal,
        onProgress: value => setProgress(Math.round(value * 100)),
      });

      setPhase('waiting');
      const admitted = await api.request(`/videos/${encodeURIComponent(video.slug)}/clip-transfers`, {
        method: 'POST',
        signal: controller.signal,
        body: JSON.stringify({
          request_key: crypto.randomUUID(),
          bytes: clip.bytes,
          sha256: clip.sha256,
          title: form.title.trim(),
          start: Number(form.start),
          end: Number(form.end),
          visibility: form.visibility,
        }),
      });
      let transfer = admitted.transfer;
      transferRef.current = transfer.id;
      while (transfer.state === 'queued') {
        await wait(2500, controller.signal);
        transfer = (await api.request(`/clip-transfers/${transfer.id}`, {
          signal: controller.signal,
          trackPageLoading: false,
        })).transfer;
      }
      if (transfer.state !== 'uploading') throw new Error('The upload slot is no longer available.');

      const grant = await api.request(`/clip-transfers/${transfer.id}/upload-grant`, {
        method: 'POST',
        signal: controller.signal,
        body: '{}',
      });
      const upload = grant.upload;
      const body = new FormData();
      Object.entries(upload.fields || {}).forEach(([key, value]) => body.append(key, String(value)));
      body.append('file', clip.blob, `${transfer.id}.mp4`);
      setPhase('uploading');
      const response = await fetch(upload.url, { method: 'POST', body, signal: controller.signal });
      if (!response.ok) throw new Error('The browser upload failed.');

      setPhase('finalizing');
      const completed = await api.request(`/clip-transfers/${transfer.id}/uploaded`, {
        method: 'POST',
        signal: controller.signal,
        body: '{}',
      });
      transferRef.current = '';
      onCreated(completed.transfer);
    } catch (failure) {
      if (failure?.name === 'AbortError') return;
      if (fallbackCodes.has(failure?.code)) setFallback(true);
      setError('Your clip could not be created. Please try again.');
      setPhase('idle');
      await syncPreviewToTime(Number(form.start));
    } finally {
      if (clip?.objectUrl) URL.revokeObjectURL(clip.objectUrl);
      abortRef.current = null;
    }
  };

  if (!open || typeof document === 'undefined') return null;
  return createPortal(<div className="clip-composer-backdrop" onMouseDown={event => {
    if (event.target === event.currentTarget && !busy) close();
  }}>
    <section ref={dialogRef} className="clip-composer" role="dialog" aria-modal="true" aria-labelledby="clip-composer-title">
      <header>
        <div><h2 id="clip-composer-title">{t('Create a clip')}</h2>
          <p>{t('Choose 5 to 60 seconds. Your browser prepares the clip before uploading it directly to temporary storage.')}</p></div>
        <button type="button" className="clip-composer-close" onClick={close} aria-label={t('Close')}><Icon name="close" size={20}/></button>
      </header>
      {fallback ? <div className="clip-composer-fallback">
        <Icon name="link" size={22}/>
        <div><strong>{t('This source cannot be clipped in your browser.')}</strong>
          <p>{t('You can still share a link that starts at the current time.')}</p></div>
        <button type="button" className="btn primary" onClick={() => { onTimeLink(); onClose(); }}>{t('Copy time link')}</button>
      </div> : <form onSubmit={create}>
        <div className="clip-composer-preview" data-ready={previewReady ? 'true' : 'false'} data-playing={previewPlaying ? 'true' : 'false'}>
          <div className="clip-composer-preview-frame">
            <canvas ref={canvasRef} aria-hidden="true" />
            {!previewReady && <div className="clip-composer-preview-empty" role="status">{t('Preview')}</div>}
            <button
              type="button"
              className="clip-composer-preview-toggle"
              disabled={busy || !eligibleSource}
              aria-pressed={previewPlaying}
              aria-label={t(previewPlaying ? 'Pause' : 'Play')}
              onClick={togglePreviewPlayback}
            >
              {previewReady && !previewPlaying && <span className="clip-composer-preview-affordance" aria-hidden="true"><Icon name="play" size={28}/></span>}
            </button>
            <div className="clip-composer-player-controls">
              <div className="clip-composer-player-track" aria-hidden="true">
                <i className="clip-composer-player-selection" style={{ insetInlineStart: `${overviewClip}%`, width: `${overviewClipWidth}%` }} />
              </div>
              <label className="sr-only" htmlFor="clip-player-start">{t('Seek')}</label>
              <input
                id="clip-player-start"
                className="clip-composer-player-scrubber"
                type="range"
                min="0"
                max={Math.max(0, totalSeconds - clipSeconds)}
                step="1"
                value={Math.min(Number(form.start), Math.max(0, totalSeconds - clipSeconds))}
                disabled={busy}
                aria-valuetext={formatTimestamp(form.start)}
                onChange={event => {
                  const start = placeClip(event.currentTarget.value);
                  seekSchedulerRef.current?.schedule(start);
                }}
                onPointerUp={event => seekSchedulerRef.current?.commit(Number(event.currentTarget.value))}
                onKeyUp={event => seekSchedulerRef.current?.commit(Number(event.currentTarget.value))}
              />
              <span className="clip-composer-player-time" data-i18n-skip>{formatTimestamp(form.start)} / {formatTimestamp(totalSeconds)}</span>
            </div>
          </div>
        </div>
        <div
          className="clip-composer-timing"
          style={{
            '--clip-start': `${startPercent}%`,
            '--clip-end': `${endPercent}%`,
            '--clip-cursor': `${cursorPercent}%`,
          }}
        >
          <div className="clip-composer-timeline-meta" data-i18n-skip>
            <strong>{formatTimestamp(form.start)} – {formatTimestamp(form.end)}</strong>
            <span>{formatTimestamp(clipSeconds)}</span>
          </div>
          <div className="clip-composer-timeline" role="group" aria-label={t('Duration')}>
            <div className="clip-composer-timeline-track" onPointerDown={onDetailPointerDown}>
              <i className="clip-composer-timeline-selection" />
              {cursorInView && <i className="clip-composer-timeline-cursor" />}
            </div>
            <label className="sr-only" htmlFor="clip-start">{t('Start time in seconds')}</label>
            <input
              id="clip-start"
              className="clip-composer-handle clip-composer-handle-start"
              type="range"
              min={detailStart}
              max={detailEnd}
              step="1"
              required
              disabled={busy}
              value={clamp(Number(form.start), detailStart, detailEnd)}
              aria-valuetext={formatTimestamp(form.start)}
              onChange={event => updateStart(event.target.value)}
              onPointerUp={event => seekSchedulerRef.current?.commit(Number(event.currentTarget.value))}
              onKeyUp={event => seekSchedulerRef.current?.commit(Number(event.currentTarget.value))}
            />
            <label className="sr-only" htmlFor="clip-end">{t('End time in seconds')}</label>
            <input
              id="clip-end"
              className="clip-composer-handle clip-composer-handle-end"
              type="range"
              min={detailStart}
              max={detailEnd}
              step="1"
              required
              disabled={busy}
              value={clamp(Number(form.end), detailStart, detailEnd)}
              aria-valuetext={formatTimestamp(form.end)}
              onChange={event => updateEnd(event.target.value)}
              onPointerUp={() => seekSchedulerRef.current?.commit(Number(formRef.current.start))}
              onKeyUp={() => seekSchedulerRef.current?.commit(Number(formRef.current.start))}
            />
          </div>
          <div className="clip-composer-slider-scale" data-i18n-skip>
            <span>{formatTimestamp(detailStart)}</span>
            <span>{formatTimestamp(detailEnd)}</span>
          </div>
          <div className="clip-composer-duration-presets" aria-label={t('Duration')}>
            {[15, 30, 60].map(seconds => <button key={seconds} type="button" disabled={busy || seconds > totalSeconds}
              aria-pressed={clipSeconds === seconds}
              onClick={() => seekSchedulerRef.current?.commit(applyDuration(seconds))}>
              <span data-i18n-skip>{seconds} s</span>
            </button>)}
          </div>
        </div>
        <label><span>{t('Clip title')}</span>
          <input data-i18n-skip value={form.title} maxLength={200} required disabled={busy} onChange={event => setForm(current => ({ ...current, title: event.target.value }))}/></label>
        <label><span>{t('Visibility')}</span>
          <select value={form.visibility} disabled={busy} onChange={event => setForm(current => ({ ...current, visibility: event.target.value }))}>
            <option value="private">{t('Private')}</option>
            <option value="public">{t('Public')}</option>
          </select></label>
        {busy && <div className="clip-composer-progress" role="status" aria-live="polite">
          <progress max="100" value={phase === 'encoding' ? progress : undefined}/>
          <span>{t(phase === 'encoding' ? 'Preparing clip in your browser… {progress}%'
            : phase === 'waiting' ? 'Waiting for an upload slot…'
              : phase === 'uploading' ? 'Uploading directly to temporary storage…'
                : 'Finishing your clip upload…', { progress })}</span>
        </div>}
        {error && <p className="clip-composer-error" role="alert">{t(error)}</p>}
        <footer><button type="button" className="btn subtle" onClick={close}>{t(busy ? 'Cancel' : 'Close')}</button>
          <button type="submit" className="btn primary" disabled={busy}>{t(busy ? 'Creating clip…' : 'Create a clip')}</button></footer>
      </form>}
    </section>
  </div>, document.body);
}
