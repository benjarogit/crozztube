import React from 'react';
import { useI18n } from '../i18n';

export default function ImportMetadataSummary({ provider, automatic, metadata, fetching }) {
  const { t } = useI18n();
  if (!provider?.enabled) return null;
  const captions = Array.isArray(metadata?.captions) ? metadata.captions.length : 0;
  const chapters = Array.isArray(metadata?.chapters) ? metadata.chapters.length : 0;

  return <div className="upload-import-summary" data-i18n-skip role="status" aria-live="polite" aria-busy={fetching}>
    <div className="upload-import-provider">
      <strong>{provider.label}</strong>
      <span>{t(automatic ? 'Provider detected automatically' : 'Selected provider')}</span>
    </div>
    {provider.help && <p className="upload-import-help">{t(provider.help)}</p>}
    {metadata && <dl className="upload-import-results">
      <div>
        <dt>{t('Subtitles')}</dt>
        <dd><strong>{captions ? t('Subtitle languages found: {count}', { count: captions }) : t('None found')}</strong>
          <small>{t(captions ? 'Available subtitles and a transcript will be imported.' : 'No available subtitle tracks found.')}</small>
        </dd>
      </div>
      <div>
        <dt>{t('Chapters')}</dt>
        <dd><strong>{chapters >= 2 ? t('Chapters found: {count}', { count: chapters }) : t('None found')}</strong>
          <small>{t(chapters >= 2 ? 'Review or disable them before publishing.' : 'You can add chapters manually in Review.')}</small>
        </dd>
      </div>
    </dl>}
  </div>;
}
