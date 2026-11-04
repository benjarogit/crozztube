import React, { useEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { useI18n } from '../i18n.jsx';
import { createWebUgcConsent, webPolicyLinks } from '../webUgcConsent.js';
import '../styles/ugc-consent.css';

function ConsentDialog({ controller, state, origin, canManagePages = false }) {
  const { t } = useI18n(); const [checked, setChecked] = useState(false);
  const panel = useRef(null); const policy = state.status?.policy;
  const links = webPolicyLinks(policy, origin);
  const available = policy?.available === true && Boolean(links.terms && links.privacy);
  useEffect(() => {
    const previous = document.activeElement;
    panel.current?.querySelector('button')?.focus();
    const keyboard = event => {
      if (event.key === 'Escape') { event.preventDefault(); controller.dismiss(); }
      if (event.key !== 'Tab') return;
      const controls = [...(panel.current?.querySelectorAll('a[href],button:not([disabled]),input:not([disabled])') || [])];
      const first = controls[0]; const last = controls.at(-1);
      if (!first) { event.preventDefault(); return; }
      if (event.shiftKey && (document.activeElement === first || !panel.current?.contains(document.activeElement))) { event.preventDefault(); last.focus(); }
      else if (!event.shiftKey && (document.activeElement === last || !panel.current?.contains(document.activeElement))) { event.preventDefault(); first.focus(); }
    };
    document.addEventListener('keydown', keyboard);
    return () => { document.removeEventListener('keydown', keyboard); if (previous?.isConnected) previous.focus?.(); };
  }, [controller]);
  return <div className="collection-modal-backdrop content-delete-confirm-backdrop ugc-consent-backdrop" role="dialog" aria-modal="true" aria-labelledby="ugc-consent-title" aria-describedby="ugc-consent-description" onMouseDown={event => { if (event.target === event.currentTarget) controller.dismiss(); }}>
    <section ref={panel} className="ugc-consent-dialog" aria-busy={state.loading || state.accepting}>
      <header><h2 id="ugc-consent-title">{t('Community safety and Terms')}</h2><button type="button" className="btn subtle compact" aria-label={t('Close')} onClick={controller.dismiss}>×</button></header>
      <p id="ugc-consent-description">{t('Before posting, commenting, messaging or going live, review and accept the current Terms of Use and Privacy Policy.')}</p>
      <p>{t('You can keep browsing without accepting. Reporting, blocking, deleting your content and signing out remain available.')}</p>
      {state.loading && <p role="status">{t('Loading current policy…')}</p>}
      <nav aria-label={t('Legal documents')}>{links.terms && <a href={links.terms} target="_blank" rel="noopener noreferrer">{t('Read Terms of Use')}</a>}{links.privacy && <a href={links.privacy} target="_blank" rel="noopener noreferrer">{t('Read Privacy Policy')}</a>}</nav>
      {!state.loading && policy && !available && <div role="alert">
        <p>{t(policy.available
          ? 'The legal page addresses do not match this website. An administrator must check the website URL configuration.'
          : 'The website administrator must publish and enable the Terms of Use and Privacy Policy before they can be accepted.')}</p>
        <p>{t('You can continue browsing, report or block users, and manage your existing content.')}</p>
        {canManagePages && <a className="btn subtle" href="/admin/pages" onClick={controller.dismiss}>{t('Manage legal pages')}</a>}
      </div>}
      {state.error && <p role="alert">{t('Could not confirm the current policy. Please refresh and try again.')}</p>}
      {available && <label className="ugc-consent-check"><input type="checkbox" checked={checked} disabled={state.loading || state.accepting} onChange={event => setChecked(event.target.checked)} /><span>{t('I have read and accept the Terms of Use and Privacy Policy')}</span></label>}
      <footer><button type="button" className="btn subtle" onClick={controller.dismiss}>{t('Browse without accepting')}</button><button type="button" className="btn subtle" disabled={state.loading || state.accepting} onClick={() => void controller.refresh(false)}>{t('Refresh policy')}</button>{available && <button type="button" className="btn primary" disabled={!checked || state.loading || state.accepting} onClick={() => { if (controller.owns(state.owner) && controller.snapshot().status?.policy.version === policy?.version) void controller.accept(checked); }}>{t(state.accepting ? 'Saving…' : 'Accept and continue')}</button>}</footer>
    </section>
  </div>;
}

export default function WebUgcConsent({ user, api }) {
  const ownerUser = useRef(user); ownerUser.current = user;
  const [state, setState] = useState(null);
  const [controller] = useState(() => createWebUgcConsent({
    request: (path, options) => api.request(path, options), origin: window.location.origin, onChange: setState,
    getOwner: () => api.token() && Number.isSafeInteger(ownerUser.current?.id)
      ? { id: ownerUser.current.id, epoch: api.authenticationEpoch() } : null,
  }));
  useEffect(() => { controller.synchronize(); }, [controller, user?.id]);
  useEffect(() => {
    const synchronize = () => controller.synchronize();
    const required = event => controller.required(event.detail?.epoch);
    const storage = event => { if (event.key === 'playhub_token' || event.key === null) synchronize(); };
    window.addEventListener('playhub:auth-changed', synchronize);
    window.addEventListener('playhub:ugc-terms-required', required);
    window.addEventListener('storage', storage);
    return () => {
      window.removeEventListener('playhub:auth-changed', synchronize);
      window.removeEventListener('playhub:ugc-terms-required', required);
      window.removeEventListener('storage', storage);
      controller.dispose();
    };
  }, [controller]);
  if (!state?.visible || !state.owner || state.owner.id !== user?.id || state.owner.epoch !== api.authenticationEpoch()) return null;
  const settings = user?.settings || {};
  const admin = settings.admin;
  const canManagePages = (typeof admin === 'object' && admin !== null ? Boolean(admin.enabled) : Boolean(admin))
    || Boolean(settings.is_admin) || ['admin', 'super admin', 'owner'].includes(String(settings.role || settings.account_role || '').toLowerCase().replace(/[_-]+/g, ' '));
  return createPortal(<ConsentDialog key={`${state.owner.id}:${state.owner.epoch}:${state.status?.policy.version ?? 'loading'}`} controller={controller} state={state} origin={window.location.origin} canManagePages={canManagePages} />, document.body);
}
