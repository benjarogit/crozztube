const GOOGLE_IMA_SDK_URL = 'https://imasdk.googleapis.com/js/sdkloader/ima3.js';

let sdkPromise = null;

function imaGlobal() {
  return typeof window !== 'undefined' ? window.google?.ima : null;
}

function debugMessage(category, error = null) {
  const details = {};
  const imaError = error?.getError?.() || error;
  const code = Number(imaError?.getErrorCode?.() || 0);
  const vastCode = Number(imaError?.getVastErrorCode?.() || 0);
  const message = String(imaError?.getMessage?.() || imaError?.message || '').trim();
  if (code) details.code = code;
  if (vastCode) details.vastCode = vastCode;
  if (message) details.message = message;
  console.warn(`[PlayHub IMA] ${category}`, details);
}

function classifiedError(error, fallback = 'ad request failure') {
  const imaError = error?.getError?.() || error;
  const code = Number(imaError?.getErrorCode?.() || 0);
  const vastCode = Number(imaError?.getVastErrorCode?.() || 0);
  if (code === 303) return 'no-fill';
  if (code >= 400 && code < 500) return 'media load error';
  if (vastCode) return 'VAST error';
  return fallback;
}

export function googleImaSdkUrl() {
  return GOOGLE_IMA_SDK_URL;
}

export function googleImaAdTagUrl(adTagUrl, pageUrl) {
  const value = String(adTagUrl || '').trim();
  if (!/^https?:\/\//i.test(value)) throw new Error('IMA ad tag must use HTTP or HTTPS.');
  const url = new URL(value, pageUrl);
  if (!/^https?:$/.test(url.protocol)) throw new Error('IMA ad tag must use HTTP or HTTPS.');
  url.searchParams.set('description_url', pageUrl);
  return url.toString();
}

export function loadGoogleImaSdk(timeoutMs = 10000) {
  const existingIma = imaGlobal();
  if (existingIma) return Promise.resolve(existingIma);
  if (sdkPromise) return sdkPromise;
  if (typeof document === 'undefined') return Promise.reject(new Error('IMA requires a browser document.'));

  sdkPromise = new Promise((resolve, reject) => {
    let script = document.querySelector(`script[src="${GOOGLE_IMA_SDK_URL}"]`);
    const timeout = window.setTimeout(() => {
      sdkPromise = null;
      reject(new Error('Google IMA SDK load timed out.'));
    }, Math.max(3000, Number(timeoutMs) || 10000));
    const cleanup = () => {
      window.clearTimeout(timeout);
      script?.removeEventListener('load', loaded);
      script?.removeEventListener('error', failed);
    };
    const loaded = () => {
      cleanup();
      const ima = imaGlobal();
      if (ima) resolve(ima);
      else {
        sdkPromise = null;
        reject(new Error('Google IMA SDK did not initialize.'));
      }
    };
    const failed = () => {
      cleanup();
      sdkPromise = null;
      reject(new Error('Google IMA SDK could not be loaded.'));
    };

    if (!script) {
      script = document.createElement('script');
      script.src = GOOGLE_IMA_SDK_URL;
      script.async = true;
      script.dataset.playhubGoogleIma = 'true';
      document.head.appendChild(script);
    }
    script.addEventListener('load', loaded, { once: true });
    script.addEventListener('error', failed, { once: true });
    window.setTimeout(() => { if (imaGlobal()) loaded(); }, 0);
  });

  return sdkPromise;
}

export class GoogleImaClient {
  constructor({ container, getContentElement }) {
    if (!container) throw new Error('IMA ad container is not mounted.');
    this.container = container;
    this.getContentElement = getContentElement;
    this.ima = null;
    this.displayContainer = null;
    this.adsLoader = null;
    this.adsManager = null;
    this.initialized = false;
    this.destroyed = false;
    this.requestNumber = 0;
    this.requestState = null;
    this.managerListeners = [];
    this.resizeObserver = null;
    this.onFullscreenChange = () => this.resize();
    this.onVisibilityChange = () => {
      if (document.visibilityState !== 'hidden') this.resumeAfterClick();
    };
    this.onWindowFocus = () => this.resumeAfterClick();
    this.onManagerLoaded = (event) => this.handleManagerLoaded(event);
    this.onLoaderError = (event) => this.handleAdError(event, 'ad request failure');
  }

  prepare(ima = imaGlobal()) {
    if (this.destroyed) throw new Error('IMA client has been destroyed.');
    if (this.displayContainer) return this;
    if (!ima) throw new Error('Google IMA SDK is not available.');
    this.ima = ima;
    const content = this.getContentElement?.() || undefined;
    this.displayContainer = new ima.AdDisplayContainer(this.container, content);
    this.adsLoader = new ima.AdsLoader(this.displayContainer);
    this.adsLoader.addEventListener(ima.AdsManagerLoadedEvent.Type.ADS_MANAGER_LOADED, this.onManagerLoaded, false);
    this.adsLoader.addEventListener(ima.AdErrorEvent.Type.AD_ERROR, this.onLoaderError, false);
    if (typeof ResizeObserver === 'function') {
      this.resizeObserver = new ResizeObserver(() => this.resize());
      this.resizeObserver.observe(this.container);
    }
    document.addEventListener('fullscreenchange', this.onFullscreenChange);
    document.addEventListener('webkitfullscreenchange', this.onFullscreenChange);
    document.addEventListener('visibilitychange', this.onVisibilityChange);
    window.addEventListener?.('focus', this.onWindowFocus);
    return this;
  }

  initializeFromUserGesture() {
    try {
      this.prepare();
      if (!this.initialized) {
        this.displayContainer.initialize();
        this.initialized = true;
      }
      return true;
    } catch (error) {
      debugMessage('IMA initialization failure', error);
      return false;
    }
  }

  request(options) {
    this.prepare();
    this.cancelCurrentRequest(true);
    const requestNumber = ++this.requestNumber;
    const timeoutMs = Math.max(3000, Number(options.timeoutMs) || 10000);
    const pageUrl = String(options.pageUrl || window.location.href);
    const dimensions = this.dimensions();
    const request = new this.ima.AdsRequest();
    request.adTagUrl = googleImaAdTagUrl(options.adTagUrl, pageUrl);
    request.linearAdSlotWidth = dimensions.width;
    request.linearAdSlotHeight = dimensions.height;
    request.nonLinearAdSlotWidth = dimensions.width;
    request.nonLinearAdSlotHeight = Math.max(1, Math.round(dimensions.height / 3));
    request.pageUrl = pageUrl;
    request.vastLoadTimeout = timeoutMs;
    if (Number.isFinite(Number(options.contentDuration)) && Number(options.contentDuration) > 0) request.contentDuration = Number(options.contentDuration);
    if (options.contentTitle) request.contentTitle = String(options.contentTitle);
    if (Array.isArray(options.contentKeywords) && options.contentKeywords.length) request.contentKeywords = options.contentKeywords.map(String);
    request.setAdWillAutoPlay?.(Boolean(options.autoPlay));
    request.setAdWillPlayMuted?.(Boolean(options.muted));
    request.setContinuousPlayback?.(Boolean(options.continuousPlayback));

    return new Promise((resolve) => {
      this.requestState = {
        requestNumber,
        resolve,
        callbacks: options,
        started: false,
        completed: false,
        skipped: false,
        settled: false,
        resumeAfterClick: false,
        clickResumeTimer: null,
        timeout: window.setTimeout(() => {
          debugMessage('ad timeout');
          this.settle('timeout');
        }, timeoutMs),
      };
      try {
        this.adsLoader.requestAds(request, { requestNumber });
      } catch (error) {
        debugMessage('ad request failure', error);
        this.settle('error', error);
      }
    });
  }

  handleManagerLoaded(event) {
    const context = event.getUserRequestContext?.();
    if (!this.requestState || (context?.requestNumber && context.requestNumber !== this.requestState.requestNumber)) return;
    try {
      const settings = new this.ima.AdsRenderingSettings();
      settings.restoreCustomPlaybackStateOnAdBreakComplete = true;
      const contentPlayback = this.getContentElement?.() || { currentTime: 0 };
      this.adsManager = event.getAdsManager(contentPlayback, settings);
      const types = this.ima.AdEvent.Type;
      this.addManagerListener(this.ima.AdErrorEvent.Type.AD_ERROR, (adError) => this.handleAdError(adError, 'AdsManager error'));
      this.addManagerListener(types.CONTENT_PAUSE_REQUESTED, (adEvent) => this.requestState?.callbacks.onContentPause?.(adEvent));
      this.addManagerListener(types.CONTENT_RESUME_REQUESTED, (adEvent) => {
        this.requestState?.callbacks.onContentResume?.(adEvent);
        this.settle(this.requestState?.completed ? 'complete' : this.requestState?.skipped ? 'skipped' : 'resumed');
      });
      this.addManagerListener(types.STARTED, (adEvent) => {
        if (!this.requestState) return;
        this.requestState.started = true;
        window.clearTimeout(this.requestState.timeout);
        const duration = Math.max(0, Number(adEvent.getAd?.()?.getDuration?.() || 0));
        this.requestState.timeout = window.setTimeout(() => {
          debugMessage('media load error', new Error('Advertisement playback did not finish.'));
          this.settle('timeout');
        }, Math.min(7200000, Math.max(30000, (duration + 30) * 1000)));
        this.requestState.callbacks.onStarted?.(adEvent);
      });
      this.addManagerListener(types.CLICK, () => {
        if (!this.requestState) return;
        this.requestState.resumeAfterClick = true;
        window.clearTimeout(this.requestState.clickResumeTimer);
        this.requestState.clickResumeTimer = window.setTimeout(() => this.resumeAfterClick(), 250);
      });
      this.addManagerListener(types.COMPLETE, (adEvent) => {
        if (!this.requestState) return;
        this.requestState.completed = true;
        this.requestState.callbacks.onComplete?.(adEvent);
      });
      this.addManagerListener(types.SKIPPED, (adEvent) => {
        if (!this.requestState) return;
        this.requestState.skipped = true;
        this.requestState.callbacks.onSkipped?.(adEvent);
      });
      this.addManagerListener(types.ALL_ADS_COMPLETED, () => {
        const status = this.requestState?.completed ? 'complete' : this.requestState?.skipped ? 'skipped' : 'no-fill';
        if (status === 'no-fill') debugMessage('no-fill');
        this.settle(status);
      });

      if (!this.initialized) this.initializeFromUserGesture();
      const dimensions = this.dimensions();
      this.adsManager.setVolume?.(this.requestState?.callbacks.muted ? 0 : Math.max(0, Math.min(1, Number(this.requestState?.callbacks.volume ?? 1))));
      this.adsManager.init(dimensions.width, dimensions.height, this.viewMode());
      this.adsManager.start();
    } catch (error) {
      debugMessage('AdsManager error', error);
      this.settle('error', error);
    }
  }

  handleAdError(error, fallback) {
    const context = error?.getUserRequestContext?.();
    if (context?.requestNumber && context.requestNumber !== this.requestState?.requestNumber) return;
    debugMessage(classifiedError(error, fallback), error);
    this.settle(classifiedError(error, fallback) === 'no-fill' ? 'no-fill' : 'error', error);
  }

  addManagerListener(type, listener) {
    this.adsManager.addEventListener(type, listener);
    this.managerListeners.push([type, listener]);
  }

  dimensions() {
    const rect = this.container.getBoundingClientRect?.() || {};
    const parentRect = this.container.parentElement?.getBoundingClientRect?.() || {};
    return {
      width: Math.max(1, Math.round(rect.width || parentRect.width || 640)),
      height: Math.max(1, Math.round(rect.height || parentRect.height || 360)),
    };
  }

  viewMode() {
    const fullscreen = document.fullscreenElement || document.webkitFullscreenElement;
    return fullscreen ? this.ima.ViewMode.FULLSCREEN : this.ima.ViewMode.NORMAL;
  }

  resize() {
    if (!this.adsManager || !this.requestState || this.requestState.settled) return;
    try {
      const dimensions = this.dimensions();
      this.adsManager.resize(dimensions.width, dimensions.height, this.viewMode());
    } catch (error) {
      debugMessage('AdsManager resize failure', error);
    }
  }

  resumeAfterClick() {
    const state = this.requestState;
    if (!state || state.settled || !state.started || !state.resumeAfterClick || document.visibilityState === 'hidden') return;
    window.clearTimeout(state.clickResumeTimer);
    state.clickResumeTimer = null;
    try {
      this.adsManager?.resume?.();
      state.resumeAfterClick = false;
    } catch (error) {
      debugMessage('AdsManager resume failure', error);
    }
  }

  settle(status, error = null) {
    const state = this.requestState;
    if (!state || state.settled) return;
    state.settled = true;
    window.clearTimeout(state.timeout);
    window.clearTimeout(state.clickResumeTimer);
    const result = { status, completed: state.completed, skipped: state.skipped, started: state.started, error };
    this.destroyAdsManager();
    this.requestState = null;
    state.resolve(result);
  }

  destroyAdsManager() {
    this.managerListeners.forEach(([type, listener]) => {
      try { this.adsManager?.removeEventListener(type, listener); } catch (_) {}
    });
    this.managerListeners = [];
    try { this.adsManager?.destroy(); } catch (_) {}
    this.adsManager = null;
  }

  cancelCurrentRequest(resolve = true) {
    const state = this.requestState;
    if (!state) return;
    window.clearTimeout(state.timeout);
    window.clearTimeout(state.clickResumeTimer);
    state.settled = true;
    this.destroyAdsManager();
    this.requestState = null;
    if (resolve) state.resolve({ status: 'cancelled', completed: false, skipped: false, started: state.started });
  }

  destroy() {
    if (this.destroyed) return;
    this.destroyed = true;
    this.cancelCurrentRequest(true);
    this.resizeObserver?.disconnect();
    document.removeEventListener('fullscreenchange', this.onFullscreenChange);
    document.removeEventListener('webkitfullscreenchange', this.onFullscreenChange);
    document.removeEventListener('visibilitychange', this.onVisibilityChange);
    window.removeEventListener?.('focus', this.onWindowFocus);
    try {
      this.adsLoader?.removeEventListener(this.ima?.AdsManagerLoadedEvent.Type.ADS_MANAGER_LOADED, this.onManagerLoaded, false);
      this.adsLoader?.removeEventListener(this.ima?.AdErrorEvent.Type.AD_ERROR, this.onLoaderError, false);
      this.adsLoader?.contentComplete?.();
    } catch (_) {}
    try { this.displayContainer?.destroy(); } catch (_) {}
    this.adsLoader = null;
    this.displayContainer = null;
    this.container = null;
  }
}
