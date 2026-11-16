/*
 * CrozzTube watch enhancer (plugin asset).
 *
 * Three jobs, all provider-neutral:
 *   1. Mirror toggle: swap the direct player for the original provider embed.
 *   2. Source banner: surface refresh state ("renewing", "failed").
 *   3. Auto-refresh: when the direct URL expired, trigger ONE background
 *      resolve (single-flight server-side), poll the status endpoint, and
 *      reload the player once a fresh URL is stored.
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
  const RESUME_KEY = 'crz.resume';
  const POLL_FALLBACK = 3000;
  const MAX_AUTO_RELOADS = 2;

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
    requestedAt: 0,
    pollAt: 0,
    mediaFailedAt: 0,
    sawRefreshing: false,
    banner: null,
    bannerTone: '',
  };

  // ---------------------------------------------------------------- utilities

  const log = (...args) => {
    if (window.__CRZ_DEBUG__) console.debug('[crz]', ...args);
  };

  function httpUrl(value) {
    try {
      const url = new URL(value);
      return ['https:', 'http:'].includes(url.protocol) ? url.href : '';
    } catch { return ''; }
  }

  function embedPermissions() {
    try {
      const policy = document.permissionsPolicy || document.featurePolicy;
      const supported = typeof policy?.features === 'function' ? policy.features() : [];
      if (!Array.isArray(supported)) return '';
      return ['autoplay', 'clipboard-write', 'encrypted-media', 'picture-in-picture', 'fullscreen', 'web-share']
        .filter(feature => supported.includes(feature)).join('; ');
    } catch { return ''; }
  }

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

  function svgIcon(paths) {
    return (
      '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" ' +
      'stroke="currentColor" stroke-width="1.8" stroke-linecap="round" ' +
      'stroke-linejoin="round" aria-hidden="true">' + paths + '</svg>'
    );
  }

  function mirrorIcon() {
    return svgIcon(
      '<rect x="2.5" y="4.5" width="19" height="13" rx="2"></rect>' +
      '<path d="M9 21h6"></path><path d="M12 17.5V21"></path>' +
      '<path d="M7 8.5h6"></path><path d="M7 12h4"></path>'
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

    const promise = fetchStatus(slug);

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
        if (result.status && !result.status.fresh && result.status.state !== 'error') state.sawRefreshing = true;
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

  async function requestRefresh({ force = false, manual = false } = {}) {
    if (!state.videoId || (!manual && state.status?.auto_refresh_allowed === false)) return false;
    if (Date.now() - state.requestedAt < 8000) return false;

    state.requestedAt = Date.now();
    state.pollAt = Date.now() + 1500;
    state.sawRefreshing = true;

    try {
      const response = await fetch(`${API}/source-resolver/refresh/${state.videoId}`, {
        method: 'POST',
        headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
        body: JSON.stringify({manual}),
        credentials: 'same-origin',
      });
      const payload = await response.json().catch(() => ({}));
      if (payload?.source) {
        state.status = payload.source;
        state.statusAt = Date.now();
      }
      log('refresh requested', response.status);
      return response.ok;
    } catch (error) {
      log('refresh request failed', error);
      return false;
    }
  }

  // -------------------------------------------------------------------- UI

  function actionBar() {
    return document.querySelector('[data-watch-mirror]')
      || document.querySelector('.watch-source-actions')
      || (document.querySelector('.watch-toolbar[data-source-menu]') ? null : document.querySelector('.watch-toolbar .action-bar'));
  }

  function playerStage() {
    return document.querySelector('.watch-player-stage');
  }

  function infoHost() {
    return document.querySelector('.watch-info') || document.querySelector('.watch-primary');
  }

  function removeButton() {
    document.querySelectorAll('.crz-mirror-slot').forEach((node) => node.remove());
  }

  function renderButton(status) {
    const mirror = status?.mirror;
    const bar = actionBar();
    if (!bar) { removeButton(); return; }

    if (!mirror || !mirror.mode || !httpUrl(mirror.mode === 'link' ? mirror.external_url : mirror.embed_url)) {
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
    const inMenu = bar.classList.contains('watch-source-actions');

    if (mirror.mode === 'link') {
      const link = document.createElement('a');
      link.className = `${inMenu ? 'video-detail-action-item' : 'action-pill'} crz-mirror-link`;
      if (inMenu) link.setAttribute('role', 'menuitem');
      link.href = httpUrl(mirror.external_url);
      link.target = '_blank';
      link.rel = 'noopener noreferrer';
      link.title = `Open the original on ${mirror.label || mirror.provider}`;
      link.innerHTML = `${mirrorIcon()}<span>Original</span>`;
      slot.appendChild(link);
      return;
    }

    const button = document.createElement('button');
    button.type = 'button';
    button.className = `${inMenu ? 'video-detail-action-item' : 'action-pill'} crz-mirror-toggle`;
    if (inMenu) button.setAttribute('role', 'menuitemcheckbox');
    button.setAttribute(button.getAttribute('role') === 'menuitemcheckbox' ? 'aria-checked' : 'aria-pressed', state.wantMirror ? 'true' : 'false');
    button.classList.toggle('is-active', state.wantMirror);
    button.title = `Show the original source (${mirror.label || mirror.provider})`;
    button.innerHTML = `${mirrorIcon()}<span>Mirror</span>`;
    button.addEventListener('click', () => toggleMirror(!state.wantMirror));
    slot.appendChild(button);
  }

  window.addEventListener('crozztube:watch-actions', () => renderButton(state.status));

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
    embedUrl = httpUrl(embedUrl);
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
      frame.title = 'Original source';
      const permissions = embedPermissions();
      if (permissions) frame.allow = permissions;
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

  // ------------------------------------------------------------ source banner

  function banner() {
    let node = document.querySelector('.crz-source-banner');
    if (node) {
      state.banner = node;
      return node;
    }
    const host = infoHost();
    if (!host) return null;

    node = document.createElement('div');
    node.className = 'crz-source-banner';
    node.setAttribute('role', 'status');
    node.setAttribute('aria-live', 'polite');

    const anchor = host.querySelector('.watch-toolbar');
    host.insertBefore(node, anchor || host.firstChild);
    state.banner = node;
    // A re-created node must be filled again even if the message is unchanged.
    state.bannerTone = '';
    return node;
  }

  function hideBanner() {
    if (state.banner) {
      state.banner.remove();
      state.banner = null;
    }
    state.bannerTone = '';
  }

  /**
   * @param {object} view {tone, text, actions: Array<{label, onClick}>}
   */
  function showBanner(view) {
    const node = banner();
    if (!node) return;

    const signature = `${view.tone}|${view.text}`;
    if (state.bannerTone === signature) return;
    state.bannerTone = signature;

    node.dataset.state = view.tone;
    node.innerHTML = '';

    if (view.tone === 'refreshing') {
      const spinner = document.createElement('span');
      spinner.className = 'crz-source-spinner';
      spinner.setAttribute('aria-hidden', 'true');
      node.appendChild(spinner);
    }

    const text = document.createElement('span');
    text.textContent = view.text;
    node.appendChild(text);

    (view.actions || []).forEach((action) => {
      const button = document.createElement('button');
      button.type = 'button';
      button.textContent = action.label;
      button.addEventListener('click', action.onClick);
      node.appendChild(button);
    });
  }

  function mirrorAction() {
    const mirror = state.status?.mirror;
    if (!mirror) return null;

    if (mirror.mode === 'link' && mirror.external_url) {
      return {
        label: 'Open original',
        onClick: () => window.open(mirror.external_url, '_blank', 'noopener'),
      };
    }
    if (mirror.mode === 'iframe' && mirror.embed_url) {
      return { label: 'Open mirror', onClick: () => toggleMirror(true) };
    }
    return null;
  }

  function syncBanner(status) {
    if (!status || status.fresh || status.refreshing) {
      if (status?.fresh) hideBanner();
      if (status?.refreshing && status.has_direct) {
        showBanner({ tone: 'refreshing', text: 'Renewing the direct source…' });
      } else if (status?.refreshing) {
        showBanner({ tone: 'refreshing', text: 'Resolving the direct source…' });
      }
      return;
    }

    if (status.state === 'stale' && status.has_direct) {
      showBanner({ tone: 'refreshing', text: 'Renewing the direct source…' });
      return;
    }

    // The stored deadline may be an internal recheck interval rather than a
    // confirmed CDN expiry. Request renewal without claiming the URL expired.
    if (status.has_direct && status.state !== 'error') {
      showBanner({ tone: 'refreshing', text: 'Renewing the direct source…' });
      return;
    }

    const actions = [];
    const mirror = mirrorAction();
    if (mirror) actions.push(mirror);

    if (status.state === 'error') {
      actions.unshift({
        label: 'Retry',
        onClick: () => {
          state.requestedAt = 0;
          requestRefresh({ force: true, manual: true });
        },
      });
      showBanner({
        tone: 'error',
        text: status.error_message
          ? `The direct source could not be renewed: ${status.error_message}`
          : 'The direct source could not be renewed.',
        actions,
      });
      return;
    }

    if (status.state === 'unknown' || status.state === 'pending') {
      showBanner({ tone: 'refreshing', text: 'Preparing the direct source…', actions });
      return;
    }

    actions.unshift({
      label: 'Renew',
      onClick: () => {
        state.requestedAt = 0;
        requestRefresh({ force: true, manual: true });
      },
    });
    showBanner({ tone: 'error', text: 'The direct source is not playable.', actions });
  }

  // -------------------------------------------------------------- auto reload

  function saveResume() {
    const video = document.querySelector('.watch-player-stage video');
    if (!video || !state.slug) return;
    if (!Number.isFinite(video.currentTime) || video.currentTime < 5) return;

    try {
      window.sessionStorage.setItem(RESUME_KEY, JSON.stringify({
        slug: state.slug,
        time: Math.floor(video.currentTime),
        at: Date.now(),
      }));
    } catch {
      /* ignore */
    }
  }

  function restoreResume(slug) {
    try {
      const raw = JSON.parse(window.sessionStorage.getItem(RESUME_KEY) || 'null');
      if (!raw || raw.slug !== slug || Date.now() - raw.at > 120_000) return;
      window.sessionStorage.removeItem(RESUME_KEY);

      const seek = () => {
        const video = document.querySelector('.watch-player-stage video');
        if (!video) return false;
        video.currentTime = raw.time;
        return true;
      };
      if (!seek()) {
        let tries = 0;
        const timer = window.setInterval(() => {
          tries += 1;
          if (seek() || tries > 30) window.clearInterval(timer);
        }, 500);
      }
    } catch {
      /* ignore */
    }
  }

  function reloadsFor(slug) {
    try {
      return Number(window.sessionStorage.getItem(`crz.reloads.${slug}`) || 0);
    } catch {
      return 0;
    }
  }

  function bumpReloads(slug) {
    try {
      window.sessionStorage.setItem(`crz.reloads.${slug}`, String(reloadsFor(slug) + 1));
    } catch {
      /* ignore */
    }
  }

  function clearReloads(slug) {
    try {
      window.sessionStorage.removeItem(`crz.reloads.${slug}`);
    } catch {
      /* ignore */
    }
  }

  /**
   * A fresh URL is stored: reload so the player picks it up. If that already
   * happened twice for this video, stop looping and offer a manual action.
   */
  function reloadForFreshSource() {
    if (!state.sawRefreshing) return;

    const slug = state.slug;
    if (!slug) return;

    if (reloadsFor(slug) >= MAX_AUTO_RELOADS) {
      showBanner({
        tone: 'error',
        text: 'The player did not recover automatically.',
        actions: [
          { label: 'Reload', onClick: () => { clearReloads(slug); saveResume(); window.location.reload(); } },
          mirrorAction(),
        ].filter(Boolean),
      });
      state.sawRefreshing = false;
      return;
    }

    bumpReloads(slug);
    saveResume();
    window.location.reload();
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
      restoreResume(slug);
    }

    // Wait for the video response to mount its player/placeholder before testing
    // whether a fresh source needs to replace an earlier pending response.
    if (!playerStage() || !document.querySelector('.watch-toolbar .action-bar')) return;

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
      syncBanner(state.status);
      if (state.wantMirror && state.status?.mirror?.mode === 'iframe') {
        openLayer(state.status.mirror.embed_url);
      }
      syncButtonState();

      if (state.status?.fresh) {
        // Resolution may finish between the video response and the first status
        // request. There is then no observed "refreshing" transition to reload.
        const stage = playerStage();
        if (stage?.querySelector('.demo-player, .watch-embed-player')) {
          state.sawRefreshing = true;
          reloadForFreshSource();
        } else {
          clearReloads(slug);
        }
      }
      if (state.status?.has_direct && !state.status.fresh && !state.status.refreshing) {
        requestRefresh();
      }
      return;
    }

    // Poll while a refresh is running or a banner is visible.
    const staleLike = state.status && !state.status.fresh && !state.status.refreshing && state.status.has_direct;
    if (staleLike && Date.now() - state.requestedAt > 30_000) {
      // The server debounces requests; retry so a single dropped job cannot
      // leave the visitor stuck on "renewing" forever.
      requestRefresh({ force: true });
    }

    const polling = state.sawRefreshing || document.querySelector('.crz-source-banner');
    if (polling && Date.now() >= state.pollAt) {
      const wait = (state.status?.retry_after ?? 3) * 1000 || POLL_FALLBACK;
      state.pollAt = Date.now() + Math.max(1500, wait);
      try {
        const before = state.status;
        const result = await loadStatus(slug, { force: true });
        const after = result.status;
        if (after && !after.refreshing && after.fresh && (before?.refreshing || state.sawRefreshing)) {
          reloadForFreshSource();
          return;
        }
      } catch (error) {
        log('poll failed', error);
      }
    }

    // Re-inject if React replaced the toolbar/banner host, keep states in sync.
    if (actionBar() && !actionBar().querySelector('.crz-mirror-slot')) {
      renderButton(state.status);
    }
    syncBanner(state.status);
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
    state.requestedAt = 0;
    state.pollAt = 0;
    state.mediaFailedAt = 0;
    state.sawRefreshing = false;
    hideBanner();
    closeLayer();
    removeButton();
  }

  /**
   * A media element error usually means the signed URL expired between
   * resolution and playback: ask for one refresh (server dedupes).
   */
  function onMediaError(event) {
    const target = event.target;
    const isMedia = target instanceof HTMLVideoElement || target instanceof HTMLSourceElement;
    if (!isMedia || !currentSlug()) return;
    if (state.mediaFailedAt && Date.now() - state.mediaFailedAt < 15_000) return;

    state.mediaFailedAt = Date.now();
    log('media error, requesting refresh');
    requestRefresh({ force: true });
  }

  function boot() {
    document.addEventListener('error', onMediaError, true);
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
