export const emptyKlipyCatalog = () => ({
  stickers: [], query: '', loading: false, error: '', message: '',
  configured: true, enabled: true,
  meta: { current_page: 0, last_page: 1, has_more: false },
});

class KlipyCatalogError extends Error {}
// Weak metadata does not enter selected-sticker JSON, comment payloads or storage.
const klipyShareMetadata = new WeakMap();

function klipyImage(value) {
  const candidate = typeof value === 'string' ? value : value?.url;
  try {
    if (typeof candidate !== 'string' || candidate.length > 2048 || !candidate.startsWith('https://') || /[\u0000-\u0020\\#]/.test(candidate)) throw new Error();
    const url = new URL(candidate);
    if (url.protocol !== 'https:' || url.username || url.password || url.port || url.hash || !/\.(gif|webp|png)$/i.test(url.pathname) || !['static.klipy.com', 'static1.klipy.com', 'static2.klipy.com'].includes(url.hostname)) throw new Error();
    return candidate;
  } catch (_) {
    throw new KlipyCatalogError('KLIPY returned an unsupported sticker image. Please try again.');
  }
}

// KLIPY requires end-user requests: no server proxy and no app key in errors.
async function requestKlipyStickerPage(client, { query = '', page = 1, signal, fetchImpl = fetch } = {}) {
  if (!client?.app_key || client.base_url !== 'https://api.klipy.com/api/v1') throw new KlipyCatalogError('KLIPY is not configured correctly. Please contact the administrator.');
  const params = new URLSearchParams({ page: String(page), per_page: String(client.per_page || 24), locale: client.locale || 'us', customer_id: client.customer_id || '', content_filter: client.content_filter || 'high', format_filter: 'gif,webp' });
  if (query) params.set('q', query);
  const response = await fetchImpl(`${client.base_url}/${encodeURIComponent(client.app_key)}/stickers/${query ? 'search' : 'trending'}?${params}`, { signal, credentials: 'omit', referrerPolicy: 'no-referrer' });
  if (!response.ok) throw new KlipyCatalogError('KLIPY is currently unavailable. Please try again.');
  const payload = await response.json();
  const data = payload?.data;
  if (payload?.result !== true || !data || !Array.isArray(data.data)) throw new KlipyCatalogError('KLIPY returned an invalid response. Please try again.');
  if (data.data.some((item) => String(item.type || '').toLowerCase() === 'ad')) throw new KlipyCatalogError('This KLIPY key requires ads that this integration does not support. Ask the administrator to configure a non-monetized KLIPY key.');
  // Preserve provider order and duplicates; never silently filter provider results.
  const stickers = data.data.map((item) => {
    const slug = String(item.slug || item.id || '');
    if (!slug) throw new KlipyCatalogError('KLIPY returned an invalid sticker. Please try again.');
    const image_url = klipyImage(item.file?.md?.gif || item.file?.md?.webp || item.file?.hd?.gif || item.file?.hd?.webp || item.file?.sm?.gif || item.file?.sm?.webp);
    const preview_url = klipyImage(item.file?.sm?.gif || item.file?.sm?.webp || image_url);
    const price_cents = Number(client.live_price_cents || 100);
    const sticker = { id: slug, slug, name: String(item.title || item.name || 'Sticker'), provider: 'klipy', category: 'KLIPY', image_url, preview_url, price_cents, price: (price_cents / 100).toFixed(2), currency: client.currency || 'USD' };
    klipyShareMetadata.set(sticker, { client, query });
    return sticker;
  });
  const current_page = Number(data.current_page || page);
  const has_more = Boolean(data.has_next);
  return { stickers, meta: { current_page, last_page: has_more ? current_page + 1 : current_page, has_more, per_page: Number(client.per_page || 24), total: Number(data.total || stickers.length) } };
}

export async function fetchKlipyStickerPage(client, options = {}) {
  const controller = new AbortController();
  const abort = () => controller.abort();
  if (options.signal?.aborted) abort();
  options.signal?.addEventListener('abort', abort, { once: true });
  const timeout = setTimeout(abort, 20000);
  try {
    return await requestKlipyStickerPage(client, { ...options, signal: controller.signal });
  } catch (error) {
    if (error instanceof KlipyCatalogError) throw error;
    throw new KlipyCatalogError('Unable to load KLIPY stickers. Please try again.');
  } finally {
    clearTimeout(timeout);
    options.signal?.removeEventListener('abort', abort);
  }
}

export async function registerKlipySticker(request, sticker, query = '') {
  const data = await request('/stickers/selection', { method: 'POST', body: JSON.stringify({ slug: sticker.slug, name: Array.from(String(sticker.name || 'Sticker')).slice(0, 80).join(''), image_url: sticker.image_url, preview_url: sticker.preview_url, query: query.trim().slice(0, 100) }), trackPageLoading: false });
  if (!data.sticker?.id) throw new Error('Unable to select this sticker. Please try again.');
  const metadata = klipyShareMetadata.get(sticker);
  if (metadata) klipyShareMetadata.set(data.sticker, metadata);
  return data.sticker;
}

export async function trackKlipyStickerShare(sticker, { fetchImpl = fetch, timeoutMs = 2000 } = {}) {
  const metadata = sticker && klipyShareMetadata.get(sticker);
  if (!metadata) return;
  const { client, query } = metadata;
  const controller = new AbortController();
  const timeout = setTimeout(() => controller.abort(), timeoutMs);
  try {
    await fetchImpl(`${client.base_url}/${encodeURIComponent(client.app_key)}/stickers/share/${encodeURIComponent(sticker.slug)}`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ customer_id: client.customer_id || '', q: query || '' }), credentials: 'omit', referrerPolicy: 'no-referrer', signal: controller.signal });
  } catch (_) {
    // Analytics never blocks or rolls back a successfully published comment/payment.
  } finally {
    clearTimeout(timeout);
  }
}

// Each query owns its pages. Abort plus the generation check also protects against
// HTTP clients which cannot cancel a response that has already completed.
export function createKlipyCatalog({ request, onChange, endpoint = '/stickers', fetchPage = fetchKlipyStickerPage, schedule = setTimeout, cancel = clearTimeout }) {
  let state = emptyKlipyCatalog();
  let generation = 0;
  let controller = null;
  let timer = null;
  let client = null;
  const emit = (patch) => { state = { ...state, ...patch }; onChange(state); };
  const hasMore = () => state.meta.has_more ?? Number(state.meta.current_page || 0) < Number(state.meta.last_page || 1);
  const invalidate = () => {
    generation += 1;
    if (timer !== null) cancel(timer);
    timer = null;
    controller?.abort();
    controller = null;
  };
  const load = async (page, expectedGeneration) => {
    if (expectedGeneration !== generation || controller) return;
    const active = new AbortController();
    controller = active;
    emit({ loading: true, error: '' });
    try {
      if (!client) {
        const config = await request(endpoint, { signal: active.signal, trackPageLoading: false });
        if (expectedGeneration !== generation || active.signal.aborted) return;
        emit({ configured: config.configured !== false, enabled: config.enabled !== false, message: config.message || '' });
        client = config.client;
        if (!state.enabled || !state.configured || !client) { emit({ loading: false, configured: Boolean(client) && state.configured }); return; }
      }
      const data = await fetchPage(client, { query: state.query, page, signal: active.signal });
      if (expectedGeneration !== generation || active.signal.aborted) return;
      const incoming = Array.isArray(data.stickers) ? data.stickers : [];
      emit({ stickers: page === 1 ? incoming : [...state.stickers, ...incoming],
        meta: data.meta || { current_page: page, last_page: page, has_more: false },
        loading: false });
    } catch (error) {
      if (expectedGeneration !== generation || active.signal.aborted) return;
      emit({ loading: false, error: error instanceof KlipyCatalogError ? error.message : 'Unable to load KLIPY stickers. Please try again.' });
    } finally {
      if (controller === active) controller = null;
    }
  };
  const search = (query, delay = 300) => {
    invalidate();
    client = null;
    const expectedGeneration = generation;
    emit({ ...emptyKlipyCatalog(), query: String(query || '').trim().slice(0, 100), loading: true });
    if (!delay) return load(1, expectedGeneration);
    timer = schedule(() => { timer = null; void load(1, expectedGeneration); }, delay);
  };
  return {
    search,
    retry: () => load(Math.max(1, Number(state.meta.current_page || 0) + (state.stickers.length ? 1 : 0)), generation),
    loadMore: () => !state.loading && !state.error && hasMore() && load(Number(state.meta.current_page || 0) + 1, generation),
    dispose: invalidate,
  };
}
