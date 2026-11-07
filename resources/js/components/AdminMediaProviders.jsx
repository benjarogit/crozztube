import React, { useEffect, useState } from 'react';
import { useI18n } from '../i18n.jsx';

const definitions = {
  directupload: { name: 'Directupload', links: [['Upload form', 'https://www.directupload.eu/yourload.php']], image: true, fields: [
    ['enabled', 'Enabled', 'checkbox'], ['minute_limit', 'Uploads per minute', 'number'], ['day_limit', 'Uploads per day', 'number'],
  ], guide: ['No account key is needed. {platform} uses the public upload form.', 'Image links are public outside {platform}. A connection test uploads a small synthetic image.'] },
  fastpic: { name: 'Fastpic', links: [['Fastpic API & ShareX', 'https://fastpic.org/keys'], ['Fastpic account', 'https://fastpic.org/account']], image: true, fields: [
    ['enabled', 'Enabled', 'checkbox'], ['key', 'API Key', 'password'], ['minute_limit', 'Uploads per minute', 'number'], ['day_limit', 'Uploads per day', 'number'],
  ], guide: ['Open API & ShareX, sign in and confirm your email address. Create a separate API key for {platform} and copy the key when it is shown.', 'Paste that key into API Key, save the connection and upload a test image.', 'Image links are public outside {platform}. A connection test uploads a small synthetic image.'] },
  postimages: { name: 'Postimages', links: [['Postimages API access', 'https://postimages.org/login/api'], ['Create a Postimages account', 'https://postimages.org/signup']], image: true, fields: [
    ['enabled', 'Enabled', 'checkbox'], ['key', 'API Key', 'password'], ['client_o', 'Client compatibility value O', 'text'], ['client_m', 'Client compatibility value M', 'text'],
    ['minute_limit', 'Uploads per minute', 'number'], ['day_limit', 'Uploads per day', 'number'], ['month_limit', 'Uploads per month', 'number'],
  ], guide: ['Open the API access page, sign in and copy your personal API key. Paste it into API Key below.', 'The O and M values are public compatibility defaults, not your account key. Change them only when the provider tells you to.', 'Image links are public outside {platform}. A connection test uploads a small synthetic image.'] },
  oracle: { name: 'Oracle', links: [['Oracle customer secret keys', 'https://cloud.oracle.com/identity/domains/my-profile/auth-tokens'], ['Oracle buckets', 'https://cloud.oracle.com/object-storage/buckets']], fields: [
    ['region', 'Region', 'text'], ['namespace', 'Namespace', 'text'], ['bucket', 'Bucket', 'text'], ['access_key_id', 'Access Key', 'password'], ['secret_access_key', 'Secret Key', 'password'],
  ], guide: ['Open Oracle buckets and choose your home region and compartment. Create a private Standard bucket or open your existing one.', 'Copy the selected region into Region, the Object Storage namespace from the bucket details into Namespace, and the bucket name into Bucket.', 'Open Oracle customer secret keys. Under Tokens and keys → Customer secret keys, generate a new pair. Copy its access key into Access Key and the secret shown once into Secret Key. API signing keys and auth tokens are different credentials.', 'Save the connection, then check bucket access. This test does not upload a video.'] },
  odysee: { name: 'Odysee', links: [['Odysee channels', 'https://odysee.com/$/channels'], ['Create an Odysee account', 'https://odysee.com/$/signup']], fields: [
    ['channel_id', 'Channel ID', 'text'], ['auth_token', 'Account token', 'password'],
  ], guide: ['Create an Odysee account and a channel for {platform} clips.', 'Sign in below, choose your channel and save the connection. You do not need to find an API key.', 'Unlisted videos remain accessible to anyone with their link. {platform} access restrictions do not protect that external link.'] },
};

export default function AdminMediaProviders({ api, platformName = 'CrozzTube', renderProvider, renderIcon, controls }) {
  const { t: translate } = useI18n();
  const t = (message) => translate(message, { platform: platformName });
  const { Field, Secret, Toggle, Select } = controls;
  const [providers, setProviders] = useState(null);
  const [drafts, setDrafts] = useState({});
  const [selected, setSelected] = useState('oracle');
  const [busy, setBusy] = useState('');
  const [feedback, setFeedback] = useState(null);
  const [loadKey, setLoadKey] = useState(0);
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [channels, setChannels] = useState([]);
  const [testUrl, setTestUrl] = useState('');
  useEffect(() => {
    let active = true;
    const controller = new AbortController();
    if (!api?.request) { setFeedback({ error: true, text: 'Admin API client is unavailable.' }); return undefined; }
    api.request('/admin/media-providers', { signal: controller.signal }).then((data) => {
      if (!active) return;
      if (!data?.providers?.oracle || !data.providers.odysee) throw new Error('Media provider settings could not be loaded. Try again.');
      setProviders(data.providers);
      setDrafts(data.providers);
      setFeedback(null);
    }).catch((error) => { if (active) setFeedback({ error: true, text: error.message || 'Media provider settings could not be loaded. Try again.' }); });
    return () => { active = false; controller.abort(); };
  }, [api, loadKey]);
  const definition = definitions[selected];
  const draft = drafts[selected] || {};
  const saved = providers?.[selected] || {};
  const clear = draft.clear_secrets || [];
  const dirty = definition.fields.some(([field]) => draft[field] !== saved[field]) || clear.length > 0 || Boolean(draft.connection_id);
  const setField = (field, value) => {
    setDrafts((current) => ({ ...current, [selected]: { ...current[selected], [field]: value, clear_secrets: (current[selected]?.clear_secrets || []).filter((key) => key !== field) } }));
    setFeedback(null); setTestUrl('');
  };
  const toggleClear = (field) => { setDrafts((current) => {
    const values = current[selected] || {};
    const clearing = values.clear_secrets || [];
    return { ...current, [selected]: { ...values, [field]: '', clear_secrets: clearing.includes(field) ? clearing.filter((key) => key !== field) : [...clearing, field] } };
  }); setFeedback(null); setTestUrl(''); };
  const save = async (event) => {
    event.preventDefault();
    if (busy || !dirty) return;
    setBusy('save'); setFeedback(null); setTestUrl('');
    const changes = Object.fromEntries(definition.fields.map(([field, , type]) => [field, type === 'number' ? Number(draft[field]) : (draft[field] ?? (type === 'checkbox' ? false : ''))]));
    try {
      const data = await api.request(`/admin/media-providers/${selected}`, { method: 'PUT', body: JSON.stringify({ changes, clear_secrets: clear, ...(draft.connection_id ? { connection_id: draft.connection_id } : {}) }) });
      setProviders(data.providers);
      setDrafts((current) => ({ ...current, [selected]: data.providers[selected] }));
      if (selected === 'odysee') setChannels([]);
      setFeedback({ text: data.message || 'Media provider settings saved.' });
    } catch (error) { setFeedback({ error: true, text: error.message || 'Media provider settings could not be saved. Try again shortly.' }); }
    finally { setBusy(''); }
  };
  const test = async () => {
    if (busy || dirty) return;
    setBusy('test'); setFeedback(null); setTestUrl('');
    try {
      const data = await api.request(`/admin/media-providers/${selected}/test`, { method: 'POST', body: '{}' });
      setFeedback({ text: data.message, warning: Boolean(data.warning) });
      // Provider parsers validate image hostnames server-side; only open HTTPS links.
      if (/^https:\/\//.test(data.test_image_url || '')) setTestUrl(data.test_image_url);
    } catch (error) { setFeedback({ error: true, text: error.message || 'The connection test failed. Check the saved settings and provider availability.' }); }
    finally { setBusy(''); }
  };
  const login = async (event) => {
    event.preventDefault();
    if (busy) return;
    setBusy('login'); setFeedback(null);
    try {
      const data = await api.request('/admin/media-providers/odysee/connect', { method: 'POST', body: JSON.stringify({ email, password }) });
      setChannels(data.channels);
      setDrafts((current) => ({ ...current, odysee: { ...current.odysee, auth_token: '', clear_secrets: [], connection_id: data.connection_id,
        channel_id: data.channels.some((channel) => channel.id === current.odysee?.channel_id) ? current.odysee.channel_id : data.channels.length === 1 ? data.channels[0].id : '' } }));
      setFeedback({ text: 'Choose your channel and save the connection within five minutes.' });
    } catch (error) { setFeedback({ error: true, text: error.message || 'Odysee sign-in failed. Your saved connection is unchanged.' }); }
    finally { setBusy(''); setPassword(''); }
  };
  const field = ([key, label, type]) => {
    if (type === 'checkbox') return <Toggle key={key} label={t(label)} checked={Boolean(draft[key])} disabled={Boolean(busy)} onChange={(value) => setField(key, value)} />;
    if (key === 'channel_id' && draft.connection_id) return <Select key={key} label={t('Channel')} value={draft[key] || ''} disabled={Boolean(busy)} onChange={(value) => setField(key, value)} options={[["", t('Choose a channel')], ...channels.map((channel) => [channel.id, channel.name])]} />;
    if (type === 'password') return <Secret key={key} label={t(label)} value={draft[key] || ''} configured={Boolean(saved.configured_secrets?.[key])} clear={clear.includes(key)} disabled={Boolean(busy)} onChange={(value) => setField(key, value)} onClear={() => toggleClear(key)} />;
    return <Field key={key} label={t(label)} type={type} value={draft[key] ?? ''} disabled={Boolean(busy)} required
      min={type === 'number' ? 1 : undefined} max={type === 'number' ? 1000000 : undefined} maxLength={type === 'text' ? 512 : undefined}
      pattern={key === 'channel_id' ? '[a-f0-9]{40}' : undefined} onChange={(value) => setField(key, value)} />;
  };
  return <section className="admin-media-providers" aria-label={t('External media services')} data-i18n-skip>
    {feedback && <p className={`admin-media-feedback ${feedback.error ? 'is-error' : feedback.warning ? 'is-warning' : ''}`} role={feedback.error ? 'alert' : 'status'}>{t(feedback.text)}</p>}
    {!providers ? <div className="admin-media-loading">{feedback?.error ? <button type="button" className="admin-outline-action" onClick={() => setLoadKey((key) => key + 1)}>{t('Retry')}</button> : <p role="status">{t('Loading settings...')}</p>}</div> : <>
      <div className="admin-integration-tabs admin-media-provider-choice" role="group" aria-label={t('Provider')}>
        {['oracle', 'odysee', 'directupload', 'fastpic', 'postimages'].map((key) => {
          const item = definitions[key];
          const configured = item.fields.every(([field, , type]) => type === 'password'
            ? providers[key]?.configured_secrets?.[field]
            : type !== 'text' || Boolean(providers[key]?.[field]?.trim()));
          return <React.Fragment key={key}>{renderProvider({ icon: key === 'oracle' ? 'storage' : 'cloud', name: item.name, status: t(item.image && !providers[key]?.enabled ? 'Disabled' : configured ? 'Configured' : 'Not configured'), active: selected === key, wide: key === 'oracle', disabled: Boolean(busy), onClick: () => { setSelected(key); setPassword(''); setFeedback(null); setTestUrl(''); } })}</React.Fragment>;
        })}
      </div>
      <details className="admin-media-guide"><summary>{t('Setup guide')}</summary><ol>{definition.guide.map((text) => <li key={text}>{t(text)}</li>)}</ol><div className="admin-media-guide-links">{definition.links.map(([label, url]) => <a href={selected === 'oracle' && /^[a-z]{2}(?:-[a-z0-9]+){2,4}$/.test(draft.region || '') ? `${url}?region=${encodeURIComponent(draft.region)}` : url} key={url} target="_blank" rel="noopener noreferrer">{t(label)}</a>)}</div></details>
      {selected === 'odysee' && <details className="admin-media-login"><summary>{t('Connect your Odysee account')}</summary><p>{t('Your password is used only to sign in. It is never saved.')}</p>
        <form onSubmit={login} className="admin-media-fields"><label className="admin-field"><span>{t('Email')}</span><div><input type="email" autoComplete="username" value={email} required disabled={Boolean(busy)} onChange={(event) => setEmail(event.target.value)} /></div></label>
          <label className="admin-field"><span>{t('Password')}</span><div><input type="password" autoComplete="current-password" value={password} required disabled={Boolean(busy)} onChange={(event) => setPassword(event.target.value)} /></div></label>
          <button type="submit" className="admin-outline-action" disabled={Boolean(busy)}>{busy === 'login' ? t('Signing in...') : t('Sign in to Odysee')}</button></form>
      </details>}
      <form onSubmit={save} className="admin-media-fields" aria-label={t('Media provider settings')}>
        {definition.fields.filter(([key]) => selected !== 'odysee' || key !== 'auth_token').map(field)}
        {selected === 'odysee' && !draft.connection_id && <details className="admin-media-token"><summary>{t('Use an existing account token')}</summary>{field(definition.fields.find(([key]) => key === 'auth_token'))}</details>}
        <p className="admin-media-field-help">{t('Leave credential fields blank to keep their saved values. Use Remove credential to remove a credential when you save.')}</p>
        <div className="admin-media-actions"><button type="button" className="admin-outline-action" disabled={Boolean(busy) || dirty} onClick={test}>{renderIcon()}{busy === 'test' ? t('Testing...') : t(definition.image ? 'Upload test image' : 'Test Connection')}</button>
          <button type="submit" className="admin-outline-action primary-text" disabled={Boolean(busy) || !dirty || (Boolean(draft.connection_id) && !draft.channel_id)}>{renderIcon()}{busy === 'save' ? t('Saving...') : t('Save connection')}</button></div>
        {dirty && <p className="admin-media-field-help">{t('Save your changes before testing the connection.')}</p>}
        {testUrl && <a className="admin-media-test-link" href={testUrl} target="_blank" rel="noopener noreferrer">{t('Open test image')}</a>}
      </form>
    </>}
  </section>;
}
