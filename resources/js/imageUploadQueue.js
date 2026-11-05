import { useEffect, useRef, useState } from 'react';

// Every composer shares this chain: selecting four images never starts four transfers.
let transferTail = Promise.resolve();
export function enqueueImageTransfer(operation) {
  const result = transferTail.then(operation);
  transferTail = result.catch(() => {});
  return result;
}

const acceptedTypes = new Set(['image/jpeg', 'image/png', 'image/webp', 'image/gif']);
const stopped = () => new DOMException('Image upload cancelled.', 'AbortError');
const wait = (milliseconds, signal) => new Promise((resolve, reject) => {
  if (signal.aborted) { reject(stopped()); return; }
  const cancel = () => { clearTimeout(timer); reject(stopped()); };
  const timer = setTimeout(() => { signal.removeEventListener('abort', cancel); resolve(); }, milliseconds);
  signal.addEventListener('abort', cancel, { once: true });
});

async function limitedRequest(request, path, options = {}, milliseconds = 20000) {
  const controller = new AbortController();
  const abort = () => controller.abort();
  options.signal?.addEventListener('abort', abort, { once: true });
  if (options.signal?.aborted) controller.abort();
  const timer = setTimeout(abort, milliseconds);
  try { return await request(path, { ...options, signal: controller.signal, trackPageLoading: false }); }
  finally { clearTimeout(timer); options.signal?.removeEventListener('abort', abort); }
}

const readyAttachment = (response) => ['ready', 'attached'].includes(response?.status)
  && response.attachment?.image_id && response.attachment?.url ? response.attachment : null;

export const hostedImagesReady = (images = []) => images.every((image) => image.status === 'ready' && image.image_id);
export const hostedImageIds = (images = []) => {
  if (!hostedImagesReady(images)) throw new Error('Wait until every image is ready, or retry or remove failed images.');
  return images.map((image) => image.image_id);
};
export function appendHostedImageIds(form, images = []) {
  hostedImageIds(images).forEach((id) => form.append('image_ids[]', id));
}

/** Files and object URLs live only in this mounted browser draft; only UUIDs are submitted. */
export function useHostedImages({ request, purpose, targetId, authEpoch, maxFiles = 4, maxBytes = 5 * 1024 * 1024, onError }) {
  const [items, setItems] = useState([]);
  const entries = useRef(new Map());
  const mounted = useRef(true);
  const scope = `${authEpoch ?? ''}:${purpose}:${targetId || ''}`;
  const latest = useRef({ request, purpose, targetId, authEpoch, maxFiles, maxBytes, onError, scope });
  latest.current = { request, purpose, targetId, authEpoch, maxFiles, maxBytes, onError, scope };
  const publish = () => { if (mounted.current) setItems([...entries.current.values()].map(({ controller, cancelled, generation, ...entry }) => ({ ...entry }))); };
  const notify = (message) => latest.current.onError?.(message);
  const abandon = (entry) => {
    if (entry.consumed) return;
    // The server creates a tombstone even if this races ahead of the first PUT.
    void limitedRequest(entry.request, `/image-uploads/${entry.id}`, {
      method: 'DELETE', expectedAuthEpoch: entry.authEpoch, keepalive: true,
    }, 6000).catch(() => {});
  };
  const dispose = (entry, consumed = false) => {
    entry.consumed ||= consumed;
    entry.cancelled = true;
    entry.controller?.abort();
    entries.current.delete(entry.id);
    if (entry.url?.startsWith('blob:')) URL.revokeObjectURL(entry.url);
    abandon(entry);
  };
  const remove = (id) => { const entry = entries.current.get(id); if (entry) { dispose(entry); publish(); } };
  const clear = (group, consumed = false) => {
    for (const entry of [...entries.current.values()]) if (group === undefined || entry.group === String(group)) dispose(entry, consumed);
    publish();
  };
  const consume = (submitted) => {
    const ids = new Set(submitted.map((entry) => typeof entry === 'string' ? entry : entry.id));
    for (const entry of [...entries.current.values()]) if (ids.has(entry.id)) dispose(entry, true);
    publish();
  };

  const start = (entry) => {
    entry.cancelled = false;
    entry.status = 'queued'; entry.error = '';
    const generation = ++entry.generation;
    const controller = new AbortController();
    entry.controller = controller;
    const alive = () => !entry.cancelled && entry.generation === generation && entries.current.get(entry.id) === entry;
    const options = { signal: controller.signal, expectedAuthEpoch: entry.authEpoch };
    const status = async () => {
      try { return await limitedRequest(entry.request, `/image-uploads/${entry.id}`, options, 6000); }
      catch (error) { if (controller.signal.aborted) throw stopped(); if (error.status === 404) return null; throw error; }
    };
    publish();
    void enqueueImageTransfer(async () => {
      if (!alive()) return;
      entry.status = 'uploading'; publish();
      try {
        let response;
        const finishPending = async (pending) => {
          for (let poll = 0; pending?.status === 'uploading' && poll < 3; poll++) {
            await wait(2000, controller.signal);
            pending = await status();
          }
          return pending;
        };
        // A manual retry recovers a PUT whose response was lost before sending bytes again.
        if (generation > 1) response = await status();
        if (response?.status === 'uploading') {
          response = await finishPending(response);
          if (!readyAttachment(response)) throw new Error('The previous image upload did not finish. Retry to check its status.');
        }
        if (['abandoned', 'detached'].includes(response?.status)) throw new Error('This image draft is no longer available. Remove it and select the image again.');
        for (let attempt = 0; !readyAttachment(response) && attempt < 3; attempt++) {
          if (!alive()) throw stopped();
          try {
            response = await limitedRequest(entry.request,
              `/image-uploads/${entry.id}?${new URLSearchParams({ purpose: entry.purpose, target_id: String(entry.targetId) })}`, {
                ...options, method: 'PUT', body: entry.file,
                headers: { 'Content-Type': entry.file.type, 'X-Image-Name': encodeURIComponent(entry.name) },
              });
            response = await finishPending(response);
            // Unexpected success shapes are never a reason to upload the same bytes again.
            break;
          } catch (error) {
            if (!alive() || controller.signal.aborted) throw stopped();
            let recovered = await status().catch(() => null);
            if (recovered?.status === 'uploading') {
              recovered = await finishPending(recovered);
              if (readyAttachment(recovered)) { response = recovered; break; }
              throw new Error('The previous image upload did not finish. Retry to check its status.');
            }
            if (readyAttachment(recovered)) { response = recovered; break; }
            // Only capacity contention is retried; hoster failures require an explicit Retry.
            if (error.status === 429 && attempt < 2) {
              const seconds = Math.max(1, Math.min(10, Number(error.retryAfter) || 2));
              await wait(seconds * 1000, controller.signal);
              continue;
            }
            throw error;
          }
        }
        const attachment = readyAttachment(response);
        if (!attachment) throw new Error(response?.status === 'uploading'
          ? 'The image is still being processed. Retry to check its status.' : 'The image could not be hosted. Retry or remove it.');
        if (!alive()) { abandon(entry); return; }
        entry.status = 'ready'; entry.attachment = attachment; entry.image_id = attachment.image_id;
        entry.image_candidates = attachment.image_candidates || [attachment.url];
        publish();
      } catch (error) {
        if (!alive()) return;
        entry.status = 'error';
        entry.error = error.name === 'AbortError' ? 'The upload timed out. Retry to recover its status.' : error.message || 'Image upload failed. Retry or remove it.';
        publish();
      }
    });
  };
  const retry = (id) => { const entry = entries.current.get(id); if (entry?.status === 'error') start(entry); };
  const add = (files, group = 'main') => {
    const settings = latest.current;
    if (!Number(settings.targetId)) { notify('Choose a recipient or open the content before attaching an image.'); return []; }
    const added = [];
    for (const file of Array.from(files || [])) {
      if ([...entries.current.values()].filter((entry) => entry.group === String(group)).length >= settings.maxFiles) { notify(`You can attach up to ${settings.maxFiles} images.`); break; }
      if (!acceptedTypes.has(file.type)) { notify('Only JPG, PNG, WEBP or GIF images are allowed.'); continue; }
      if (!file.size || file.size > settings.maxBytes) { notify(`Images must be ${settings.maxBytes / 1024 / 1024} MiB or smaller.`); continue; }
      const entry = {
        id: crypto.randomUUID(), group: String(group), scope: settings.scope,
        purpose: settings.purpose, targetId: settings.targetId, authEpoch: settings.authEpoch,
        request: settings.request, file, name: file.name, url: URL.createObjectURL(file),
        status: 'queued', error: '', generation: 0, consumed: false,
      };
      entries.current.set(entry.id, entry); added.push(entry); start(entry);
    }
    publish(); return added;
  };

  useEffect(() => { for (const entry of [...entries.current.values()]) if (entry.scope !== scope) dispose(entry); publish(); }, [scope]);
  useEffect(() => {
    mounted.current = true;
    return () => { mounted.current = false; for (const entry of [...entries.current.values()]) dispose(entry); };
  }, []);
  const visible = items.filter((entry) => entry.scope === scope);
  return { items: visible, add, remove, retry, clear, consume,
    byGroup: (group = 'main') => visible.filter((entry) => entry.group === String(group)),
    isReady: (group) => hostedImagesReady(group === undefined ? visible : visible.filter((entry) => entry.group === String(group))),
  };
}
