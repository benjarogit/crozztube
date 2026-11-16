import React, { useCallback, useEffect, useRef, useState } from 'react';
import { useI18n } from '../i18n.jsx';
import './CaptchaChallenge.css';

/**
 * Renders the captcha challenge the server issued.
 *
 * The provider is picked on the server and bound to the challenge token; this
 * component only draws the matching widget and reports the answer/response.
 * The invisible reCAPTCHA v3 runs automatically and is escalated server-side
 * when its score is too low.
 */
const scriptPromises = new Map();

function loadScript(src) {
  if (!src) return Promise.reject(new Error('missing script'));
  if (scriptPromises.has(src)) return scriptPromises.get(src);

  const promise = new Promise((resolve, reject) => {
    const existing = Array.from(document.querySelectorAll('script[src]')).find((script) => script.src === src);
    if (existing) {
      if (existing.dataset.loaded === '1') {
        resolve();
        return;
      }
      existing.addEventListener('load', () => resolve(), { once: true });
      existing.addEventListener('error', () => reject(new Error('script failed')), { once: true });
      return;
    }
    const script = document.createElement('script');
    script.src = src;
    script.async = true;
    script.defer = true;
    script.addEventListener('load', () => {
      script.dataset.loaded = '1';
      resolve();
    }, { once: true });
    script.addEventListener('error', () => reject(new Error('script failed')), { once: true });
    document.head.appendChild(script);
  });

  scriptPromises.set(src, promise);
  return promise;
}

export default function CaptchaChallenge({ api, context = 'registration', active = false, onChange, reloadKey = 0 }) {
  const { t } = useI18n();
  const [challenge, setChallenge] = useState(null);
  const [answer, setAnswer] = useState('');
  const [response, setResponse] = useState('');
  const [honeypot, setHoneypot] = useState('');
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');
  const widgetRef = useRef(null);
  const widgetId = useRef(null);
  const startedAt = useRef(Date.now());
  const honeypotRef = useRef('');
  const onChangeRef = useRef(onChange);
  onChangeRef.current = onChange;

  const load = useCallback(async () => {
    if (!active || !api?.request) {
      setChallenge(null);
      setAnswer('');
      setResponse('');
      setError('');
      return;
    }
    setLoading(true);
    setError('');
    try {
      const data = await api.request('/captcha/challenge', {
        method: 'POST',
        body: JSON.stringify({
          context,
          captcha_honeypot: honeypotRef.current,
          captcha_elapsed_ms: Date.now() - startedAt.current,
        }),
        trackPageLoading: false,
      });
      setChallenge(data?.required ? data : null);
      setAnswer('');
      setResponse('');
      if (data?.required) startedAt.current = Date.now();
    } catch (loadError) {
      setChallenge(null);
      setError(loadError?.message || t('The security check could not be loaded. Try again.'));
    } finally {
      setLoading(false);
    }
  }, [active, api, context, t]);

  useEffect(() => {
    load();
  }, [load, reloadKey]);

  useEffect(() => {
    onChangeRef.current?.({
      token: challenge?.token || '',
      answer,
      response,
      provider: challenge?.provider || '',
      honeypot,
      elapsed_ms: Date.now() - startedAt.current,
    });
  }, [challenge, answer, response, honeypot]);

  useEffect(() => {
    if (!challenge || ['simple', 'question'].includes(challenge.kind)) return undefined;
    let cancelled = false;
    loadScript(challenge.script)
      .then(() => {
        if (cancelled || !widgetRef.current || !challenge) return;
        if (challenge.kind === 'recaptcha_v2') {
          window.grecaptcha?.ready(() => {
            if (cancelled || !widgetRef.current) return;
            widgetId.current = window.grecaptcha.render(widgetRef.current, {
              sitekey: challenge.siteKey,
              callback: (value) => setResponse(String(value || '')),
              'expired-callback': () => setResponse(''),
            });
          });
        } else if (challenge.kind === 'recaptcha_v3') {
          window.grecaptcha?.ready(() => {
            window.grecaptcha.execute(challenge.siteKey, { action: challenge.action || 'submit' })
              .then((value) => setResponse(String(value || '')))
              .catch(() => setError(t('The security check could not be completed. Try again.')));
          });
        } else if (challenge.kind === 'hcaptcha') {
          widgetId.current = window.hcaptcha?.render(widgetRef.current, {
            sitekey: challenge.siteKey,
            callback: (value) => setResponse(String(value || '')),
            'expired-callback': () => setResponse(''),
          });
        } else if (challenge.kind === 'turnstile') {
          widgetId.current = window.turnstile?.render(widgetRef.current, {
            sitekey: challenge.siteKey,
            callback: (value) => setResponse(String(value || '')),
            'expired-callback': () => setResponse(''),
          });
        }
      })
      .catch(() => {
        if (!cancelled) setError(t('The security check could not be loaded. Try again.'));
      });

    return () => {
      cancelled = true;
      try {
        if (widgetId.current !== null) {
          if (challenge.kind === 'hcaptcha') window.hcaptcha?.remove?.(widgetId.current);
          else if (challenge.kind === 'turnstile') window.turnstile?.remove?.(widgetId.current);
          else if (challenge.kind === 'recaptcha_v2') window.grecaptcha?.reset?.(widgetId.current);
        }
      } catch {
        // Widget already detached; nothing to clean up.
      }
      widgetId.current = null;
    };
  }, [challenge, t]);

  if (!active) return null;

  return <div className="captcha-challenge">
    <input
      className="captcha-honeypot"
      type="text"
      tabIndex={-1}
      autoComplete="off"
      aria-hidden="true"
      value={honeypot}
      onChange={(event) => { honeypotRef.current = event.target.value; setHoneypot(event.target.value); }}
    />
    {loading && !challenge && <p className="captcha-status" role="status">{t('Loading security check...')}</p>}
    {!loading && !challenge && !error && <p className="captcha-status">{t('No security check is required right now.')}</p>}
    {error && <p className="captcha-error" role="alert">{error}</p>}
    {challenge?.kind === 'simple' && <>
      <button type="button" className="captcha-preview" onClick={load} title={t('Refresh captcha')} aria-label={t('Refresh captcha')}>
        {challenge.image ? <img src={challenge.image} alt={t('Captcha image')} /> : <span>{t('Loading captcha...')}</span>}
      </button>
      <label>{t('Security check')}<input value={answer} onChange={(event) => setAnswer(event.target.value)} required autoComplete="off" placeholder={t('Enter the text from the image.')} /></label>
    </>}
    {challenge?.kind === 'question' && <label>{t('Security question')}<small className="captcha-question" data-i18n-skip>{challenge.question}</small><input value={answer} onChange={(event) => setAnswer(event.target.value)} required autoComplete="off" placeholder={t('Enter the answer.')} /></label>}
    {challenge && !['simple', 'question'].includes(challenge.kind) && <div className="captcha-remote">
      <div ref={widgetRef} className="notranslate" />
      {challenge.kind === 'recaptcha_v3' && <small className="captcha-status">{t('This form is protected by reCAPTCHA.')}</small>}
    </div>}
    {challenge && challenge.kind !== 'recaptcha_v3' && <button type="button" className="text-button center" onClick={load}>{t('Refresh security check')}</button>}
  </div>;
}
