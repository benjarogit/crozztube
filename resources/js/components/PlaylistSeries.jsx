import React, { useEffect, useId, useState } from 'react';
import { useI18n } from '../i18n.jsx';
import { seriesRequestError } from '../series-context.js';
import '../styles/playlist-series.css';

/** Episode metadata belongs to the playlist; this editor never reorders videos. */
export default function PlaylistSeries({ playlistId, isOwner, isSeries, api, onSaved }) {
  const { t } = useI18n();
  const id = useId();
  const [data, setData] = useState(null);
  const [draft, setDraft] = useState(null);
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);
  const [attempt, setAttempt] = useState(0);
  useEffect(() => {
    let active = true;
    setData(null); setDraft(null); setError('');
    api.request(`/playlists/${playlistId}/series`).then(result => {
      if (active) { setData(result); setDraft(result); }
    }).catch(err => { if (active) setError(err.message); });
    return () => { active = false; };
  }, [playlistId, api, attempt]);
  if (!isOwner && !data?.is_series && !error && !isSeries) return null;
  const canEdit = Boolean(isOwner && data?.can_manage_series);
  const editEpisode = (videoId, field, value) => setDraft(current => ({ ...current, episodes: current.episodes.map(e => e.video_id === videoId ? { ...e, [field]: value } : e) }));
  const save = async event => {
    event.preventDefault(); if (busy || !canEdit) return;
    setBusy(true); setError('');
    try {
      const result = await api.request(`/playlists/${playlistId}/series`, { method: 'PUT', body: JSON.stringify({
        expected_revision: data.series_revision, is_series: draft.is_series,
        episodes: draft.episodes.map(e => ({ video_id: e.video_id,
          season_number: e.season_number === '' || e.season_number == null ? null : Number(e.season_number),
          episode_number: e.episode_number === '' || e.episode_number == null ? null : Number(e.episode_number),
          episode_title: e.episode_title || null })),
      }) });
      setData(result); setDraft(result); onSaved?.(result);
    } catch (err) { setError(seriesRequestError(err, t('Unable to update playlist.'))); }
    finally { setBusy(false); }
  };
  return <section className="playlist-series-panel">
    <details><summary>{t('Series')}</summary>
      {error && <div role="alert"><p>{error}</p><button type="button" className="btn" disabled={busy} onClick={() => setAttempt(a => a + 1)}>{t('Refresh')}</button></div>}
      {!draft ? !error && <p role="status">{t('Loading...')}</p> : canEdit ? <form onSubmit={save} aria-busy={busy}>
        <label className="playlist-series-toggle"><input type="checkbox" disabled={busy} checked={draft.is_series} onChange={e => setDraft(current => ({ ...current, is_series: e.target.checked }))}/>{t('Series')}</label>
        <div className="playlist-series-rows">{draft.episodes.map(e => <fieldset key={e.video_id} disabled={busy || !draft.is_series}>
          <legend data-i18n-skip>{e.video?.title || `#${e.video_id}`}</legend>
          <label htmlFor={`${id}-season-${e.video_id}`}>{t('Season')}<input id={`${id}-season-${e.video_id}`} type="number" min="0" max="10000" value={e.season_number ?? ''} onChange={event => editEpisode(e.video_id, 'season_number', event.target.value)}/></label>
          <label htmlFor={`${id}-episode-${e.video_id}`}>{t('Episode')}<input id={`${id}-episode-${e.video_id}`} type="number" min="1" max="100000" value={e.episode_number ?? ''} onChange={event => editEpisode(e.video_id, 'episode_number', event.target.value)}/></label>
          <label className="playlist-series-title" htmlFor={`${id}-title-${e.video_id}`}>{t('Title')}<input id={`${id}-title-${e.video_id}`} maxLength={160} value={e.episode_title ?? ''} onChange={event => editEpisode(e.video_id, 'episode_title', event.target.value)}/></label>
        </fieldset>)}</div>
        <div className="playlist-series-actions"><button type="submit" className="btn primary" disabled={busy}>{busy ? t('Saving...') : t('Save')}</button><button type="button" className="btn" disabled={busy} onClick={() => { setDraft(data); setError(''); }}>{t('Cancel')}</button></div>
      </form> : <ol className="playlist-series-episode-list">{draft.episodes.map(e => <li key={e.video_id}><span data-i18n-skip>{e.season_number != null ? `S${e.season_number} · E${e.episode_number}` : ''}</span><span data-i18n-skip>{e.episode_title || e.video?.title}</span></li>)}</ol>}
    </details>
  </section>;
}
