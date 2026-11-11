import React, { useEffect, useRef, useState } from 'react';
import { useI18n } from '../i18n.jsx';
import { languageTag } from '../localizationFormat.js';
import { embedPermissions } from '../embedPermissions.js';
import './ClipUploads.css';

const terminal = new Set(['ready', 'failed', 'cancelled']);
const labels = {
  queued: 'Waiting for an upload slot', uploading: 'Waiting for your browser upload',
  sealing: 'Preparing your clip', registering: 'Uploading your clip to Odysee', publishing: 'Uploading your clip to Odysee',
  processing: 'Waiting for Odysee confirmation', awaiting_confirmation: 'Waiting for Odysee confirmation',
  releasing: 'Waiting for Odysee confirmation', ready: 'Your clip is ready to watch.',
  failed: 'Your clip could not be confirmed.', cancelled: 'Your clip upload has been canceled.',
};

export default function ClipUploads({ api, navigate, Icon, compact = false, routeSearch = '' }) {
  const { t, language } = useI18n();
  const [items, setItems] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [actionError, setActionError] = useState('');
  const [retry, setRetry] = useState(0);
  const [busyId, setBusyId] = useState(null);
  const [playing, setPlaying] = useState(null);
  const [collections, setCollections] = useState([]);
  const [collectionTitle, setCollectionTitle] = useState('');
  const [collectionVisibility, setCollectionVisibility] = useState('private');
  const [deleteId, setDeleteId] = useState('');
  const alive = useRef(true);
  const rows = useRef(new Map());
  const selected = new URLSearchParams(routeSearch).get('clip') || '';
  const selectedId = /^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/.test(selected) ? selected : '';
  const Heading = compact ? 'h2' : 'h1';
  const ItemHeading = compact ? 'h3' : 'h2';
  const positionLabel = value => {
    try { return new Intl.NumberFormat(languageTag(language)).format(value); } catch { return String(value); }
  };
  useEffect(() => { alive.current = true; return () => { alive.current = false; }; }, []);
  useEffect(() => {
    let active = true, timer;
    const controller = new AbortController();
    const load = async () => {
      try {
        const [data, collectionData] = await Promise.all([
          api.request('/clip-transfers', { signal: controller.signal, trackPageLoading: false }),
          api.request('/clip-collections', { signal: controller.signal, trackPageLoading: false }),
        ]);
        const rows = Array.isArray(data.transfers) ? data.transfers : [];
        if (active) setCollections(Array.isArray(collectionData.collections) ? collectionData.collections : []);
        if (selectedId && !rows.some(row => row.id === selectedId)) {
          try {
            const old = await api.request(`/clip-transfers/${selectedId}`, { signal: controller.signal, trackPageLoading: false });
            if (old.transfer) rows.unshift(old.transfer);
          } catch { /* Missing jobs never reveal another user's state. */ }
        }
        if (active) { setItems(rows); setError(''); }
      } catch { if (active) setError('Your clip uploads could not be loaded.'); }
      finally { if (active) { setLoading(false); timer = setTimeout(load, document.hidden ? 60000 : 15000); } }
    };
    load();
    return () => { active = false; controller.abort(); clearTimeout(timer); };
  }, [api, selectedId, retry]);

  const cancel = async item => {
    setBusyId(item.id); setActionError('');
    try {
      const result = await api.request(`/clip-transfers/${item.id}/cancel`, { method: 'POST', body: '{}' });
      if (alive.current && result.transfer) {
        setItems(items => items.map(row => row.id === item.id ? result.transfer : row));
        requestAnimationFrame(() => { if (alive.current) rows.current.get(item.id)?.focus({ preventScroll: true }); });
      }
    } catch { if (alive.current) setActionError('Your clip upload could not be canceled. Please try again.'); }
    finally { if (alive.current) setBusyId(null); }
  };
  const watch = async item => {
    if (compact) { navigate(`/clip-uploads?clip=${item.id}`); return; }
    setBusyId(item.id); setActionError('');
    try {
      const result = await api.request(`/clip-transfers/${item.id}/playback`);
      const url = new URL(result.embed_url);
      if (url.protocol !== 'https:' || url.hostname !== 'odysee.com' || url.username || url.password || url.port || !url.pathname.startsWith('/$/embed/')) throw new Error();
      if (alive.current) setPlaying({ id: item.id, url: url.toString() });
    } catch { if (alive.current) setActionError('Your clip could not be opened. Please try again.'); }
    finally { if (alive.current) setBusyId(null); }
  };
  const updateVisibility = async (item, visibility) => {
    setBusyId(item.id); setActionError('');
    try {
      const result = await api.request(`/clip-transfers/${item.id}`, {
        method: 'PATCH', body: JSON.stringify({ visibility }),
      });
      if (alive.current && result.transfer) setItems(current => current.map(row => row.id === item.id ? result.transfer : row));
    } catch { if (alive.current) setActionError('Your clip visibility could not be changed. Please try again.'); }
    finally { if (alive.current) setBusyId(null); }
  };
  const addToCollection = async (item, collectionId) => {
    if (!collectionId) return;
    setBusyId(item.id); setActionError('');
    try {
      await api.request(`/clip-collections/${collectionId}/clips/${item.id}`, { method: 'PUT', body: '{}' });
      if (alive.current) setItems(current => current.map(row => row.id === item.id
        ? { ...row, collections: [...(row.collections || []).filter(entry => entry.id !== collectionId), collections.find(entry => entry.id === collectionId)].filter(Boolean) }
        : row));
    } catch { if (alive.current) setActionError('Your clip could not be added to the collection. Please try again.'); }
    finally { if (alive.current) setBusyId(null); }
  };
  const removeFromCollection = async (item, collectionId) => {
    setBusyId(item.id); setActionError('');
    try {
      await api.request(`/clip-collections/${collectionId}/clips/${item.id}`, { method: 'DELETE' });
      if (alive.current) setItems(current => current.map(row => row.id === item.id
        ? { ...row, collections: (row.collections || []).filter(entry => entry.id !== collectionId) }
        : row));
    } catch { if (alive.current) setActionError('Your clip could not be removed from the collection. Please try again.'); }
    finally { if (alive.current) setBusyId(null); }
  };
  const createCollection = async event => {
    event.preventDefault();
    setBusyId('collection'); setActionError('');
    try {
      const result = await api.request('/clip-collections', {
        method: 'POST',
        body: JSON.stringify({ title: collectionTitle.trim(), visibility: collectionVisibility }),
      });
      if (alive.current && result.collection) {
        setCollections(current => [result.collection, ...current]);
        setCollectionTitle('');
      }
    } catch { if (alive.current) setActionError('Your clip collection could not be created. Please try again.'); }
    finally { if (alive.current) setBusyId(null); }
  };
  const deleteClip = async item => {
    setBusyId(item.id); setActionError('');
    try {
      const result = await api.request(`/clip-transfers/${item.id}`, { method: 'DELETE' });
      if (alive.current && result.transfer) {
        setItems(current => current.map(row => row.id === item.id ? result.transfer : row));
        setDeleteId('');
      }
    } catch { if (alive.current) setActionError('Your clip could not be deleted. Please try again.'); }
    finally { if (alive.current) setBusyId(null); }
  };

  if (compact && !items.length) return null;
  const content = <>
    <header className="clip-uploads-heading"><div><Heading>{t('Your clip uploads')}</Heading>
      <p>{t('Track your uploads. You can watch your clips once Odysee confirms they are available.')}</p></div>
      {compact && <button type="button" className="btn subtle" onClick={() => navigate('/clip-uploads')}>{t('Manage clip uploads')}</button>}
    </header>
    {loading && !items.length && <p role="status">{t('Loading your clip uploads…')}</p>}
    {error && <div className="clip-uploads-error" role="alert"><p>{t(error)}</p><button type="button" className="btn subtle" onClick={() => setRetry(value => value + 1)}>{t('Try again')}</button></div>}
    {actionError && <p role="alert" className="error">{t(actionError)}</p>}
    {!compact && <section className="clip-collections-manager" aria-labelledby="clip-collections-title">
      <div><h2 id="clip-collections-title">{t('Clip collections')}</h2>
        <p>{t('Group your clips into private or public collections.')}</p></div>
      <form onSubmit={createCollection}>
        <label><span>{t('Collection title')}</span><input value={collectionTitle} maxLength={120} required onChange={event => setCollectionTitle(event.target.value)} /></label>
        <label><span>{t('Visibility')}</span><select value={collectionVisibility} onChange={event => setCollectionVisibility(event.target.value)}><option value="private">{t('Private')}</option><option value="public">{t('Public')}</option></select></label>
        <button type="submit" className="btn subtle" disabled={busyId === 'collection'}>{t(busyId === 'collection' ? 'Creating collection…' : 'Create collection')}</button>
      </form>
    </section>}
    {!loading && !error && selectedId && !items.some(row => row.id === selectedId) && <p role="status">{t('This clip upload is not available.')}</p>}
    {!loading && !error && !items.length && <p className="state-card">{t('You have no clip uploads yet.')}</p>}
    <ul className="clip-transfer-list">{items.map(item => {
      const canWatch = item.state === 'ready' && item.can_watch === true && Boolean(item.available_at) && !item.cancel_requested;
      const pending = !terminal.has(item.state);
      const status = pending && item.cancel_requested ? 'Canceling your clip upload…' : (labels[item.state] || 'Waiting for Odysee confirmation');
      return <li key={item.id} ref={node => { if (node) rows.current.set(item.id, node); else rows.current.delete(item.id); }} tabIndex={-1} className="clip-transfer-row" data-state={item.state} data-selected={item.id === selectedId ? 'true' : undefined}>
        <div className="clip-transfer-copy"><ItemHeading data-i18n-skip>{item.title}</ItemHeading>
          <p className="clip-transfer-status" role="status" aria-live="polite" aria-atomic="true"><Icon name={canWatch ? 'check' : 'clock'} size={16} />{t(status)}</p>
          {item.state === 'queued' && item.position && <p>{t('Your position in the queue: {position}.', { position: positionLabel(item.position) })}</p>}
          {pending && !item.cancel_requested && item.state !== 'queued' && item.state !== 'uploading' && <p>{t('We are waiting for Odysee to confirm your clip. You can leave this page; we will notify you when it is ready.')}</p>}
          {['queued', 'uploading'].includes(item.state) && <p>{t('Keep the video page open until your browser upload is finished.')}</p>}
          {item.state === 'failed' && <p>{t('Your clip is not available here. Please contact support if you need help.')}</p>}
          {item.original && <button type="button" className="clip-original-link" onClick={() => navigate(`/watch/${encodeURIComponent(item.original.slug)}?t=${Math.floor(Number(item.start || 0))}`)}>{t('Open original video')}: <span data-i18n-skip>{item.original.title}</span></button>}
          {!compact && item.collections?.length > 0 && <div className="clip-collection-tags" aria-label={t('Clip collections')}>{item.collections.map(collection => <span key={collection.id}><span data-i18n-skip>{collection.title}</span><button type="button" disabled={busyId === item.id} onClick={() => removeFromCollection(item, collection.id)} aria-label={t('Remove from {collection}', { collection: collection.title })}><Icon name="close" size={12}/></button></span>)}</div>}
        </div>
        <div className="clip-transfer-actions">
          {canWatch && <button type="button" className="btn primary" disabled={busyId === item.id} onClick={() => watch(item)}><Icon name="play" size={16} />{t('Watch clip')}</button>}
          {canWatch && !compact && <select aria-label={t('Clip visibility')} value={item.visibility} disabled={busyId === item.id} onChange={event => updateVisibility(item, event.target.value)}><option value="private">{t('Private')}</option><option value="public">{t('Public')}</option></select>}
          {!compact && collections.length > 0 && <select aria-label={t('Add clip to collection')} value="" disabled={busyId === item.id} onChange={event => addToCollection(item, event.target.value)}><option value="">{t('Add to collection…')}</option>{collections.filter(collection => !(item.collections || []).some(entry => entry.id === collection.id)).map(collection => <option key={collection.id} value={collection.id} data-i18n-skip>{collection.title}</option>)}</select>}
          {item.can_retry && item.original && <button type="button" className="btn subtle" onClick={() => navigate(`/watch/${encodeURIComponent(item.original.slug)}?t=${Math.floor(Number(item.start || 0))}&clip=create`)}>{t('Retry clip')}</button>}
          {pending && !item.cancel_requested && <button type="button" className="btn subtle" disabled={busyId === item.id} onClick={() => cancel(item)}>{t('Cancel clip upload')}</button>}
          {!pending && !item.cancel_requested && <button type="button" className="btn subtle danger" disabled={busyId === item.id} onClick={() => setDeleteId(item.id)}>{t('Delete clip')}</button>}
        </div>
        {deleteId === item.id && <div className="clip-delete-confirm" role="alertdialog" aria-label={t('Delete clip')}><p>{t('Delete this clip from Odysee and all of your collections?')}</p><div><button type="button" className="btn subtle" onClick={() => setDeleteId('')}>{t('Keep clip')}</button><button type="button" className="btn danger" disabled={busyId === item.id} onClick={() => deleteClip(item)}>{t('Delete clip')}</button></div></div>}
        {playing?.id === item.id && canWatch && <div className="clip-transfer-player"><iframe key={playing.id} src={playing.url} title={item.title} allow={embedPermissions()} allowFullScreen referrerPolicy="strict-origin-when-cross-origin" /></div>}
      </li>;
    })}</ul>
  </>;
  return compact ? <section className="clip-uploads-panel" aria-label={t('Your clip uploads')}>{content}</section>
    : <main className="content-area clip-uploads-page">{content}</main>;
}
