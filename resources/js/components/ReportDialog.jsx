import React, { useEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { useI18n } from '../i18n.jsx';
import './ReportDialog.css';

/**
 * Shared report flow for videos, comments and profiles.
 *
 * Reviewers need to know what to look for, so a reason is required. The free
 * text description stays optional. The endpoint validates both again.
 */
export const REPORT_REASONS = {
  video: [
    ['spam', 'Spam or misleading'],
    ['abuse', 'Abusive or harmful content'],
    ['violence', 'Violence or graphic content'],
    ['copyright', 'Copyright infringement'],
    ['other', 'Something else'],
  ],
  comment: [
    ['spam', 'Spam'],
    ['harassment', 'Harassment or bullying'],
    ['hate', 'Hate speech'],
    ['sexual', 'Sexual content'],
    ['other', 'Something else'],
  ],
  profile: [
    ['impersonation', 'Impersonation'],
    ['spam', 'Spam'],
    ['harassment', 'Harassment or bullying'],
    ['other', 'Something else'],
  ],
};

const MAX_DETAILS = 500;

export default function ReportDialog({ open, type = 'video', path, label = '', api, Icon, notify, onClose, onReported }) {
  const { t } = useI18n();
  const dialogRef = useRef(null);
  const [reason, setReason] = useState('');
  const [details, setDetails] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');

  useEffect(() => {
    if (!open) return undefined;
    setReason('');
    setDetails('');
    setError('');
    setBusy(false);
    const previous = document.activeElement;
    const keydown = (event) => {
      if (event.key === 'Escape') {
        event.preventDefault();
        onClose?.();
        return;
      }
      if (event.key !== 'Tab' || !dialogRef.current) return;
      const focusable = [...dialogRef.current.querySelectorAll('button:not(:disabled), input:not(:disabled), textarea:not(:disabled)')];
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
    const frame = window.requestAnimationFrame(() => dialogRef.current?.querySelector('select')?.focus());
    return () => {
      document.removeEventListener('keydown', keydown);
      window.cancelAnimationFrame(frame);
      previous?.focus?.();
    };
  }, [open]);

  if (!open) return null;
  const options = REPORT_REASONS[type] || REPORT_REASONS.video;

  const submit = async (event) => {
    event.preventDefault();
    if (!reason || busy) return;
    setBusy(true);
    setError('');
    try {
      const data = await api.request(path, {
        method: 'POST',
        body: JSON.stringify({ reason, details: details.trim() }),
        trackPageLoading: false,
      });
      notify?.(data?.message || t('Report submitted for review.'));
      onReported?.(data);
      onClose?.();
    } catch (submitError) {
      setError(submitError.message || t('The report could not be sent. Try again.'));
    } finally {
      setBusy(false);
    }
  };

  return createPortal(<div className="collection-modal-backdrop report-dialog-backdrop" onMouseDown={(event) => { if (event.target === event.currentTarget && !busy) onClose?.(); }}>
    <section ref={dialogRef} className="report-dialog" role="dialog" aria-modal="true" aria-labelledby="report-dialog-title">
      <header>
        <div>
          <h2 id="report-dialog-title">{t(type === 'profile' ? 'Report profile' : type === 'comment' ? 'Report comment' : 'Report video')}</h2>
          {label && <p data-i18n-skip>{label}</p>}
        </div>
        <button type="button" className="clip-composer-close" onClick={() => onClose?.()} disabled={busy} aria-label={t('Close')}><Icon name="close" size={20}/></button>
      </header>
      <form onSubmit={submit}>
        <label htmlFor="report-reason">{t('Reason')}
          <select id="report-reason" value={reason} onChange={(event) => setReason(event.target.value)} required>
            <option value="">{t('Choose a reason')}</option>
            {options.map(([value, text]) => <option key={value} value={value}>{t(text)}</option>)}
          </select>
        </label>
        <label htmlFor="report-details">{t('More details (optional)')}
          <textarea id="report-details" value={details} maxLength={MAX_DETAILS} onChange={(event) => setDetails(event.target.value)} />
        </label>
        <small className="report-dialog-count" data-i18n-skip>{details.length}/{MAX_DETAILS}</small>
        {error && <p className="report-dialog-error" role="alert">{error}</p>}
        <footer>
          <button type="button" className="btn subtle" onClick={() => onClose?.()} disabled={busy}>{t('Cancel')}</button>
          <button type="submit" className="btn primary" disabled={busy || !reason}>{busy ? t('Sending...') : t('Send report')}</button>
        </footer>
      </form>
    </section>
  </div>, document.body);
}
