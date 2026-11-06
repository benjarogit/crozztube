// Permissions Policy support varies independently of media playback support.
// Keep fullscreen's separate allowFullScreen attribute on the iframe as well.
export function embedPermissions(host = globalThis.document) {
  try {
    const policy = host?.permissionsPolicy || host?.featurePolicy;
    const supported = typeof policy?.features === 'function' ? policy.features() : [];
    if (!Array.isArray(supported)) return '';
    return ['autoplay', 'clipboard-write', 'encrypted-media', 'picture-in-picture', 'fullscreen', 'web-share']
      .filter(feature => supported.includes(feature)).join('; ');
  } catch { return ''; }
}

export function httpEmbedUrl(value) {
  try {
    const url = new URL(value);
    return ['https:', 'http:'].includes(url.protocol) ? url.href : '';
  } catch { return ''; }
}
