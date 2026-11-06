export function detectImportProvider(value, providers = []) {
  let parsed;
  try { parsed = new URL(String(value || '').trim()); } catch { return null; }
  if (!['http:', 'https:'].includes(parsed.protocol) || parsed.username || parsed.password) return null;
  const host = parsed.hostname.toLowerCase().replace(/\.$/, '');
  return providers.find((provider) => provider.key !== 'direct' && (provider.domains || []).some((domain) => {
    const normalized = String(domain || '').toLowerCase();
    return normalized && (host === normalized || host.endsWith(`.${normalized}`));
  })) || providers.find((provider) => provider.key === 'direct') || null;
}

export function resolveImportProvider(form, providers = []) {
  return !form.provider || form.provider === 'auto'
    ? detectImportProvider(form.url, providers)
    : providers.find((provider) => provider.key === form.provider) || null;
}
