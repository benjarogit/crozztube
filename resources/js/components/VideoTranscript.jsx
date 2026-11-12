import { useEffect, useRef, useState } from 'react';
import { useI18n } from '../i18n.jsx';
import { formatCueTime, visibleTranscript } from './transcript-document.js';

export default function VideoTranscript({ tracks, onSeek, currentSeconds }) {
  const { t } = useI18n();
  const available = Array.isArray(tracks) ? tracks.filter((track) => track?.transcript_url) : [];
  const [language, setLanguage] = useState(available[0]?.id || '');
  const [query, setQuery] = useState('');
  const [documentState, setDocumentState] = useState(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');
  const [open, setOpen] = useState(false);
  const [now, setNow] = useState(0);
  const controller = useRef(null);
  const selected = available.find((track) => track.id === language) || available[0];

  useEffect(() => () => controller.current?.abort(), []);
  useEffect(() => {
    if (!open) return undefined;
    const update = () => setNow(Math.max(0, Number(currentSeconds?.()) || 0));
    update();
    const timer = window.setInterval(update, 500);
    return () => window.clearInterval(timer);
  }, [currentSeconds, open]);

  const load = async (track) => {
    if (!track?.transcript_url) return;
    controller.current?.abort();
    const next = new AbortController();
    controller.current = next;
    setLoading(true);
    setError('');
    setDocumentState(null);
    try {
      const response = await fetch(track.transcript_url, { credentials: 'same-origin', signal: next.signal });
      if (!response.ok) throw new Error('load');
      const type = response.headers.get('content-type') || '';
      const body = type.includes('application/json') ? await response.json() : { cues: null, text: await response.text() };
      if (next.signal.aborted) return;
      const cues = Array.isArray(body?.cues) ? body.cues.filter((cue) => Number.isFinite(Number(cue?.start_seconds))) : null;
      const text = typeof body?.text === 'string' ? body.text : '';
      if ((!cues || !cues.length) && !text.trim()) throw new Error('load');
      setDocumentState({ cues: cues?.length ? cues : null, text: cues?.length ? null : text });
    } catch (failure) {
      if (failure?.name === 'AbortError') return;
      setDocumentState(null);
      setError('The transcript could not be loaded.');
    } finally {
      if (controller.current === next) setLoading(false);
    }
  };

  useEffect(() => {
    if (open && selected) load(selected);
  }, [open, selected?.transcript_url]);

  const view = documentState ? visibleTranscript(documentState, query) : null;
  const activeStart = view?.kind === 'cues'
    ? [...view.cues].reverse().find((cue) => now >= Number(cue.start_seconds))?.start_seconds
    : null;
  const jump = (seconds) => {
    if (!Number.isFinite(Number(seconds))) return;
    onSeek?.(Number(seconds));
  };

  return <details className="watch-transcript" onToggle={(event) => setOpen(event.currentTarget.open)}>
    <summary data-i18n-skip>{t('Transcript')}</summary>
    {available.length > 1 && <div className="watch-transcript-languages" role="tablist" aria-label={t('Transcript language')}>
      {available.map((track) => <button key={track.id} type="button" role="tab" aria-selected={track.id === selected?.id} onClick={() => setLanguage(track.id)} data-i18n-skip>{track.label || track.language}</button>)}
    </div>}
    <label className="watch-transcript-search" data-i18n-skip>{t('Search transcript')}
      <input value={query} onChange={(event) => setQuery(event.target.value)} autoComplete="off" />
    </label>
    {loading && <p role="status" data-i18n-skip>{t('Loading transcript…')}</p>}
    {error && <p className="watch-transcript-error" role="alert" data-i18n-skip>{t(error)} <button type="button" className="text-button" onClick={() => load(selected)}>{t('Retry')}</button></p>}
    {view?.kind === 'cues' && <ol className="watch-transcript-cues">{view.cues.map((cue) => <li key={`${cue.start_seconds}-${cue.text}`}>
      <button type="button" disabled={!onSeek} aria-current={cue.start_seconds === activeStart ? 'true' : undefined} onClick={() => jump(cue.start_seconds)}>
        <time data-i18n-skip>{formatCueTime(cue.start_seconds)}</time>
        <span data-i18n-skip dir="auto">{cue.text}</span>
      </button>
    </li>)}</ol>}
    {view?.kind === 'text' && <div className="watch-transcript-text" tabIndex={0} aria-label={t('Transcript text')} dir="auto" data-i18n-skip>{view.text}</div>}
    {view?.kind === 'empty' && <p data-i18n-skip>{t('No transcript matches.')}</p>}
  </details>;
}
