import React, { useEffect, useMemo, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { useI18n } from '../i18n.jsx';
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
  const abortRef = useRef(null);
  const transferRef = useRef('');
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
  const busy = phase !== 'idle';
  const totalSeconds = Math.max(5, Math.floor(Number(duration || video?.duration_seconds || form.end || 5)));
  const clipSeconds = Math.max(5, Math.min(60, Number(form.end) - Number(form.start)));
  const maximumClipSeconds = Math.max(5, Math.min(60, totalSeconds - Number(form.start)));
  const maximumStart = Math.max(0, totalSeconds - clipSeconds);
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

  useEffect(() => {
    if (!open) return undefined;
    setForm({ title: video?.title ? `${video.title} clip` : '', visibility: 'private', ...initialRange });
    setPhase('idle');
    setProgress(0);
    setError('');
    setFallback(!eligibleSource);
    transferRef.current = '';
    const previous = document.activeElement;
    window.requestAnimationFrame(() => dialogRef.current?.querySelector('input')?.focus());
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
      previous?.focus?.();
    };
  }, [open, video?.slug]);

  useEffect(() => () => {
    abortRef.current?.abort();
    if (transferRef.current) {
      api.request(`/clip-transfers/${transferRef.current}/cancel`, { method: 'POST', body: '{}' }).catch(() => {});
    }
  }, [api]);

  const close = async () => {
    abortRef.current?.abort();
    const transferId = transferRef.current;
    transferRef.current = '';
    if (transferId) {
      api.request(`/clip-transfers/${transferId}/cancel`, { method: 'POST', body: '{}' }).catch(() => {});
    }
    onClose();
  };

  const create = async event => {
    event.preventDefault();
    setError('');
    setFallback(false);
    setProgress(0);
    const controller = new AbortController();
    abortRef.current = controller;
    let clip;
    try {
      setPhase('encoding');
      onCaptureStateChange?.(true);
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
    } finally {
      if (clip?.objectUrl) URL.revokeObjectURL(clip.objectUrl);
      abortRef.current = null;
      onCaptureStateChange?.(false);
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
        <label><span>{t('Clip title')}</span>
          <input data-i18n-skip value={form.title} maxLength={200} required disabled={busy} onChange={event => setForm(current => ({ ...current, title: event.target.value }))}/></label>
        <div className="clip-composer-timing">
          <div className="clip-composer-slider-field">
            <div className="clip-composer-slider-heading">
              <label htmlFor="clip-start">{t('Start time in seconds')}</label>
              <output htmlFor="clip-start" data-i18n-skip>{formatTimestamp(form.start)}</output>
            </div>
            <input id="clip-start" type="range" min="0" max={maximumStart} step="1" required disabled={busy}
              value={Math.min(Number(form.start), maximumStart)}
              aria-valuetext={formatTimestamp(form.start)}
              onChange={event => {
                const start = Number(event.target.value);
                setForm(current => ({ ...current, start, end: start + clipSeconds }));
              }}/>
            <div className="clip-composer-slider-scale" data-i18n-skip><span>0:00</span><span>{formatTimestamp(maximumStart)}</span></div>
          </div>
          <div className="clip-composer-slider-field">
            <div className="clip-composer-slider-heading">
              <label htmlFor="clip-duration">{t('Duration')}</label>
              <output htmlFor="clip-duration" data-i18n-skip>{formatTimestamp(clipSeconds)}</output>
            </div>
            <input id="clip-duration" type="range" min="5" max={maximumClipSeconds} step="1" required disabled={busy}
              value={Math.min(clipSeconds, maximumClipSeconds)}
              aria-valuetext={formatTimestamp(clipSeconds)}
              onChange={event => {
                const seconds = Number(event.target.value);
                setForm(current => ({ ...current, end: Number(current.start) + seconds }));
              }}/>
            <div className="clip-composer-duration-presets" aria-label={t('Duration')}>
              {[15, 30, 60].map(seconds => <button key={seconds} type="button" disabled={busy || seconds > maximumClipSeconds}
                aria-pressed={clipSeconds === seconds}
                onClick={() => setForm(current => ({ ...current, end: Number(current.start) + seconds }))}>
                <span data-i18n-skip>{seconds} s</span>
              </button>)}
            </div>
          </div>
          <div className="clip-composer-selection" data-i18n-skip>
            <strong>{formatTimestamp(form.start)} – {formatTimestamp(form.end)}</strong>
            <span>{formatTimestamp(clipSeconds)}</span>
          </div>
        </div>
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
