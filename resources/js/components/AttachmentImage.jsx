import React, { useEffect, useState } from 'react';

export function attachmentCandidates(attachment = {}, src) {
  return [...new Set([src || attachment.url || attachment.preview_url, ...(Array.isArray(attachment.image_candidates) ? attachment.image_candidates : []), attachment.preview_url]
    .filter((url) => typeof url === 'string' && /^(https?:\/\/|\/(?!\/)|blob:|data:image\/(?:jpeg|png|webp|gif);)/i.test(url)))];
}

/** All fallbacks are already in the response; image errors never call our backend. */
export default function AttachmentImage({ attachment = {}, src, alt = '', onResolved, onError, onLoad, loading, decoding, fetchPriority, referrerPolicy, ...props }) {
  const candidates = attachmentCandidates(attachment, src);
  const identity = JSON.stringify(candidates);
  const [failure, setFailure] = useState({ identity, urls: [] });
  const failed = failure.identity === identity ? failure.urls : [];
  const current = candidates.find((url) => !failed.includes(url));
  useEffect(() => { setFailure({ identity, urls: [] }); }, [identity]);
  if (!current) return <span {...props} className={`image-attachment-unavailable ${props.className || ''}`.trim()} role="img" aria-label={alt || 'Image unavailable'} data-image-fallback="empty">
    <svg viewBox="0 0 48 36" aria-hidden="true"><rect x="4" y="4" width="40" height="28" rx="3"/><circle cx="16" cy="13" r="3"/><path d="m7 29 11-10 7 6 7-9 9 13"/></svg>
  </span>;
  return <img {...props} src={current} alt={alt} loading={loading} decoding={decoding} fetchPriority={fetchPriority} referrerPolicy={referrerPolicy} data-image-fallback="" onLoad={(event) => { onResolved?.(current); onLoad?.(event); }} onError={(event) => {
    setFailure({ identity, urls: [...failed, current] });
    onError?.(event);
  }} />;
}

const stateLabels = { queued: 'Waiting to upload', uploading: 'Uploading image', ready: 'Image ready', error: 'Upload failed' };
export function ImageUploadPreview({ images = [], onRemove, onRetry, disabled = false }) {
  return images.length ? <div className="comment-image-preview-list hosted-image-preview-list" aria-label="Selected images">
    {images.map((item) => <figure className="comment-image-preview hosted-image-preview" key={item.id} data-image-upload-id={item.id} data-upload-state={item.status}>
      <img src={item.url} alt={item.name || 'Selected image'} />
      <figcaption>
        <strong title={item.name}>{item.name || 'Image'}</strong>
        <span role="status" aria-live="polite">{stateLabels[item.status] || 'Selected image'}</span>
        {item.error && <p role="alert">{item.error}</p>}
        <div className="hosted-image-actions">
          {item.status === 'error' && <button type="button" data-testid="image-upload-retry" onClick={() => onRetry?.(item.id)} disabled={disabled}>Retry</button>}
          <button type="button" data-testid="image-upload-remove" onClick={() => onRemove?.(item.id)} disabled={disabled} aria-label={`Remove image ${item.name || ''}`.trim()}>Remove</button>
        </div>
      </figcaption>
    </figure>)}
  </div> : null;
}
