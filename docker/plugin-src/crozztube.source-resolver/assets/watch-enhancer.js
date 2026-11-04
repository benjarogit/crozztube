/*
 * CrozzTube watch enhancer (plugin asset).
 *
 * Two jobs, both provider-neutral:
 *   1. Mirror toggle: swap the direct player for the original provider embed.
 *   2. Source status banner: show refresh state and reload when the direct
 *      source has been renewed (see watch-enhancer refresh section).
 *
 * The backend decides *what* the mirror is (provider, embed URL, link mode).
 * This file intentionally contains no provider-specific URLs.
 */
(() => {
  'use strict';

  const API = '/api';
  const STATUS_TTL = 8000;
  const FAIL_BACKOFF = 5000;
  const PREF_KEY = 'crz.mirror.prefs';

  const state = {
    slug: null,
    videoId: null,
    status: null,
    statusAt: 0,
    failedAt: 0,
    missing: false,
    loading: null,
    wantMirror: false,
    wasPlaying: false,
    layer: null,
  };

  // ---------------------------------------------------------------- utilities

  const log = (...args) => {
    if (window.__CRZ_DEBUG__) console.debug('[crz]', ...args);
  };

  function currentSlug() {
    const match = window.location.pathname.match(/^\/watch\/([^/?#]+)/);
    return match ? decodeURIComponent(match[1]) : null;
  }

  function prefs() {
    try {
      const raw = JSON.parse(window.localStorage.getItem(PREF_KEY) || '{}');
      return raw && typeof raw === 'object' ? raw : {};
    } catch {
      return {};
    }
  }

  function setPref(slug, value) {
    try {
      const all = prefs();
      if (value) all[slug] = true;
      else delete all[slug];
      window.localStorage.setItem(PREF_KEY, JSON.stringify(all));
    } catch {
      /* storage can be disabled */
    }
  }

  function svgIcon() {
    return (
      '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" ' +
      'stroke="currentColor" stroke-width="1.8" stroke-linecap="round" ' +
      'stroke-linejoin="round" aria-hidden="true">' +
      '<rect x="2.5" y="4.5" width="19" height="13" rx="2"></rect>' +
      '<path d="M9 21h6"></path><path d="M12 17.5V21"></path>' +
      '<path d="M7 8.5h6"></path><path d="M7 12h4"></path>' +
      '</svg>'
    );
  }

  // ------------------------------------------------------------------- data

  /**
   * One request, no extra video lookup: the plugin resolves the slug itself.
   * A 404 means "not an imported video" and is a final answer, not an error.
   */
  async function fetchStatus(slug) {
    const url = `${API}/source-resolver/status/slug/${encodeURIComponent(slug)}`;
    const response = await fetch(url, {
      headers: { Accept: 'application/json' },
      credentials: 'same-origin',
    });
    if (response.status === 404) return { status: null, missing: true };
    if (!response.ok) throw new Error(`${url} -> ${response.status}`);
    const payload = await response.json();
    return { status: payload.source || null, missing: false };
  }

  async function loadStatus(slug, { force = false } = {}) {
    if (!force && state.slug === slug && state.status && Date.now() - state.statusAt < STATUS_TTL) {
      return state.status;
    }
    if (state.loading && state.loading.slug === slug) return state.loading.promise;

    const promise = (async () => {
      const result = await fetchStatus(slug);
      return result;
    })();

    state.loading = { slug, promise };
    try {
      const result = await promise;
      if (currentSlug() === slug) {
        state.slug = slug;
        state.videoId = result.status?.video_id ?? null;
        state.status = result.status;
        state.missing = result.missing === true;
        state.failedAt = 0;
        state.statusAt = Date.now();
      }
      return result;
    } catch (error) {
      // Back off instead of hammering the endpoint once per tick.
      state.failedAt = Date.now();
      throw error;
    } finally {
      if (state.loading?.promise === promise) state.loading = null;
    }
  }

  // -------------------------------------------------------------------- UI

  function actionBar() {
    return document.querySelector('.watch-toolbar .action-bar');
  }

  function playerStage() {
    return document.querySelector('.watch-player-stage');
  }

  function removeButton() {
    document.querySelectorAll('.crz-mirror-slot').forEach((node) => node.remove());
  }

  function renderButton(status) {
    const mirror = status?.mirror;
    const bar = actionBar();
    if (!bar) return;

    if (!mirror || !mirror.mode) {
      removeButton();
      return;
    }

    let slot = bar.querySelector('.crz-mirror-slot');
    if (!slot) {
      slot = document.createElement('div');
      slot.className = 'crz-mirror-slot';
      const anchor = bar.querySelector('.video-detail-menu-shell');
      bar.insertBefore(slot, anchor || null);
    }
    slot.innerHTML = '';

    if (mirror.mode === 'link') {
      const link = document.createElement('a');
      link.className = 'action-pill crz-mirror-link';
      link.href = mirror.external_url || '#';
      link.target = '_blank';
      link.rel = 'noopener noreferrer';
      link.title = `Original bei ${mirror.label || mirror.provider} öffnen`;
      link.innerHTML = `${svgIcon()}<span>Original</span>`;
      slot.appendChild(link);
      return;
    }

    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'action-pill crz-mirror-toggle';
    button.setAttribute('aria-pressed', state.wantMirror ? 'true' : 'false');
    button.title = `Originalquelle (${mirror.label || mirror.provider}) anzeigen`;
    button.innerHTML = `${svgIcon()}<span>Mirror</span>`;
    button.addEventListener('click', () => toggleMirror(!state.wantMirror));
    slot.appendChild(button);
  }

  function syncButtonState() {
    const button = document.querySelector('.crz-mirror-toggle');
    if (!button) return;
    button.setAttribute('aria-pressed', state.wantMirror ? 'true' : 'false');
    button.classList.toggle('is-active', state.wantMirror);
  }

  function toggleMirror(open) {
    const mirror = state.status?.mirror;
    if (!mirror || mirror.mode !== 'iframe') return;

    state.wantMirror = open;
    if (state.slug) setPref(state.slug, open);

    if (open) openLayer(mirror.embed_url);
    else closeLayer();

    syncButtonState();
  }

  function openLayer(embedUrl) {
    const stage = playerStage();
    if (!stage || !embedUrl) return;

    const video = stage.querySelector('video');
    if (video) {
      state.wasPlaying = !video.paused;
      try { video.pause(); } catch { /* noop */ }
    }

    if (state.layer) {
      const frame = state.layer.querySelector('iframe');
      if (frame && frame.src !== embedUrl) frame.src = embedUrl;
    } else {
      const layer = document.createElement('div');
      layer.className = 'crz-mirror-layer';
      layer.setAttribute('data-crz-mirror', '1');

      const frame = document.createElement('iframe');
      frame.src = embedUrl;
      frame.title = 'Originalquelle';
      frame.allow = 'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; fullscreen';
      frame.allowFullscreen = true;
      frame.referrerPolicy = 'strict-origin-when-cross-origin';
      layer.appendChild(frame);

      stage.appendChild(layer);
      state.layer = layer;
    }

    stage.classList.add('crz-mirror-active');
  }

  function closeLayer() {
    const stage = playerStage();
    if (state.layer) {
      state.layer.remove();
      state.layer = null;
    }
    stage?.classList.remove('crz-mirror-active');

    const video = stage?.querySelector('video');
    if (video && state.wasPlaying) {
      const attempt = video.play();
      if (attempt && typeof attempt.catch === 'function') attempt.catch(() => {});
    }
    state.wasPlaying = false;
  }

  function resetForNavigation(slug) {
    state.slug = slug;
    state.videoId = null;
    state.status = null;
    state.statusAt = 0;
    state.failedAt = 0;
    state.missing = false;
    state.wantMirror = false;
    state.wasPlaying = false;
    closeLayer();
    removeButton();
  }

  // ------------------------------------------------------------------- loop

  async function tick() {
    const slug = currentSlug();
    if (!slug) {
      if (state.slug) resetForNavigation(null);
      return;
    }

    if (state.slug !== slug) {
      const wanted = prefs()[slug] === true;
      resetForNavigation(slug);
      state.wantMirror = wanted;
    }

    // Keep the player state consistent if React re-rendered the stage.
    if (state.wantMirror && state.status?.mirror?.mode === 'iframe' && !document.querySelector('.crz-mirror-layer')) {
      openLayer(state.status.mirror.embed_url);
    }

    if (!state.status) {
      if (state.missing) return;
      if (state.failedAt && Date.now() - state.failedAt < FAIL_BACKOFF) return;
      try {
        await loadStatus(slug);
      } catch (error) {
        log('status failed', error);
        return;
      }
      if (state.slug !== slug) return;
      renderButton(state.status);
      if (state.wantMirror && state.status?.mirror?.mode === 'iframe') {
        openLayer(state.status.mirror.embed_url);
      }
      syncButtonState();
      return;
    }

    // Re-inject if React replaced the toolbar, and keep the layer mounted.
    if (actionBar()) {
      const hasButton = actionBar().querySelector('.crz-mirror-slot');
      if (!hasButton) renderButton(state.status);
    }
  }

  function boot() {
    window.setInterval(() => {
      tick().catch((error) => log('tick failed', error));
    }, 1000);
    tick().catch((error) => log('tick failed', error));
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot, { once: true });
  } else {
    boot();
  }
})();
