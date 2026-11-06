import { useEffect, useRef, useState } from 'react';
import { useI18n } from '../i18n.jsx';

export default function VideoTranscript({ url }) {
  const { t } = useI18n();
  const [text, setText] = useState('');
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');
  const controller = useRef(null);
  useEffect(() => () => controller.current?.abort(), []);

  const load = async () => {
    if (loading || text) return;
    controller.current = new AbortController();
    setLoading(true);
    setError('');
    try {
      const response = await fetch(url, { credentials: 'same-origin', signal: controller.current.signal });
      if (!response.ok) throw new Error('The transcript could not be loaded.');
      setText(await response.text());
    } catch (failure) {
      if (failure.name !== 'AbortError') setError('The transcript could not be loaded.');
    } finally {
      setLoading(false);
    }
  };

  return <details className="watch-transcript" onToggle={(event) => { if (event.currentTarget.open) load(); }}>
    <summary data-i18n-skip>{t('Transcript')}</summary>
    {loading && <p role="status" data-i18n-skip>{t('Loading transcript…')}</p>}
    {error && <p role="alert" data-i18n-skip>{t(error)} <button type="button" className="text-button" onClick={load}>{t('Retry')}</button></p>}
    {text && <div className="watch-transcript-text" tabIndex={0} aria-label={t('Transcript text')} dir="auto" data-i18n-skip>{text}</div>}
  </details>;
}
