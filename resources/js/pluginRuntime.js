const pluginMetadata = Array.isArray(window.__PLAYHUB_PLUGINS__)
  ? window.__PLAYHUB_PLUGINS__.map((plugin) => Object.freeze({
    id: String(plugin?.id || ''),
    name: String(plugin?.name || ''),
    version: String(plugin?.version || ''),
    settings: Object.freeze({ ...(plugin?.settings || {}) }),
  })).filter((plugin) => plugin.id)
  : [];

const actions = new Map();
const filters = new Map();
const adminPages = new Map();
const pages = new Map();
const registeredPlugins = new Set();
let sequence = 0;
let ready = false;
let readyContext = Object.freeze({});

function hookName(value) {
  const hook = String(value || '').trim();
  if (!/^[a-z][a-z0-9_.:-]{1,119}$/.test(hook)) throw new TypeError('PlayHub hook names must use lowercase letters, numbers, dots, colons, dashes, or underscores.');
  return hook;
}

function pluginId(value) {
  const id = String(value || '').trim();
  if (!/^[a-z][a-z0-9]*(?:[._-][a-z0-9]+)*$/.test(id) && id !== 'application') throw new TypeError('Invalid PlayHub plugin id.');
  return id;
}

function priorityValue(value) {
  const priority = Number.isFinite(Number(value)) ? Math.trunc(Number(value)) : 10;
  return Math.max(-1000, Math.min(1000, priority));
}

function reportPluginError(id, hook, error) {
  const message = error instanceof Error ? error.message : String(error || 'Unknown plugin error');
  console.error(`[PlayHub plugin:${id}] ${hook}`, error);
  window.dispatchEvent(new CustomEvent('playhub:plugin-error', { detail: { plugin: id, hook, message } }));
}

function addListener(registry, hook, callback, priority = 10, id = 'application') {
  const name = hookName(hook);
  const owner = pluginId(id);
  if (typeof callback !== 'function') throw new TypeError('PlayHub hook listeners must be functions.');
  const listeners = registry.get(name) || [];
  const listener = { callback, plugin: owner, priority: priorityValue(priority), sequence: sequence++ };
  listeners.push(listener);
  registry.set(name, listeners);

  return () => {
    const current = registry.get(name) || [];
    registry.set(name, current.filter((item) => item !== listener));
  };
}

function removePluginListeners(id) {
  for (const registry of [actions, filters]) {
    for (const [hook, listeners] of registry) {
      registry.set(hook, listeners.filter((listener) => listener.plugin !== id));
    }
  }
  let pagesChanged = false;
  for (const [key, page] of adminPages) {
    if (page.plugin === id) pagesChanged = adminPages.delete(key) || pagesChanged;
  }
  if (pagesChanged) window.dispatchEvent(new CustomEvent('playhub:admin-pages-changed'));
  let websitePagesChanged = false;
  for (const [key, page] of pages) {
    if (page.plugin === id) websitePagesChanged = pages.delete(key) || websitePagesChanged;
  }
  if (websitePagesChanged) window.dispatchEvent(new CustomEvent('playhub:pages-changed'));
}

function adminPageText(value, field, maxLength) {
  const text = String(value || '').trim();
  if (!text || text.length > maxLength) throw new TypeError(`Plugin admin page ${field} must contain between 1 and ${maxLength} characters.`);
  return text;
}

function registerAdminPage(id, definition) {
  const owner = pluginId(id);
  if (!definition || typeof definition !== 'object') throw new TypeError('Plugin admin pages require a definition object.');
  const path = String(definition.path || '').replace(/\/+$/, '');
  const namespace = `/admin/extensions/${owner}`;
  if (path !== namespace && !path.startsWith(`${namespace}/`)) {
    throw new TypeError(`Plugin admin page paths must remain under ${namespace}.`);
  }
  if (typeof definition.mount !== 'function') throw new TypeError('Plugin admin pages require a mount function.');
  const key = `${owner}:${path}`;
  if ([...adminPages.values()].some((page) => page.path === path)) throw new Error(`An admin page is already registered for ${path}.`);
  const page = Object.freeze({
    plugin: owner,
    path,
    label: adminPageText(definition.label, 'label', 40),
    title: adminPageText(definition.title || definition.label, 'title', 80),
    subtitle: adminPageText(definition.subtitle, 'subtitle', 180),
    icon: /^[a-z][a-zA-Z0-9]{1,39}$/.test(String(definition.icon || '')) ? String(definition.icon) : 'plugin',
    mount: definition.mount,
  });
  adminPages.set(key, page);
  window.dispatchEvent(new CustomEvent('playhub:admin-pages-changed'));

  return () => {
    if (adminPages.delete(key)) window.dispatchEvent(new CustomEvent('playhub:admin-pages-changed'));
  };
}

function getAdminPages() {
  return Object.freeze([...adminPages.values()]);
}

function registerPage(id, definition) {
  const owner = pluginId(id);
  if (!definition || typeof definition !== 'object') throw new TypeError('Plugin pages require a definition object.');
  const path = String(definition.path || '').replace(/\/+$/, '');
  if (!/^\/[a-zA-Z0-9][a-zA-Z0-9/_-]*$/.test(path) || path.includes('//') || /^\/(?:admin|login|register|forgot-password)(?:\/|$)/.test(path)) {
    throw new TypeError('Plugin page paths must be valid non-administration application paths.');
  }
  if (typeof definition.mount !== 'function') throw new TypeError('Plugin pages require a mount function.');
  const key = owner + ':' + path;
  if ([...pages.values()].some((page) => page.path === path)) throw new Error('A plugin page is already registered for ' + path + '.');
  const page = Object.freeze({
    plugin: owner,
    path,
    exact: definition.exact !== false,
    authRequired: definition.authRequired !== false,
    mount: definition.mount,
  });
  pages.set(key, page);
  window.dispatchEvent(new CustomEvent('playhub:pages-changed'));

  return () => {
    if (pages.delete(key)) window.dispatchEvent(new CustomEvent('playhub:pages-changed'));
  };
}

function getPages() {
  return Object.freeze([...pages.values()]);
}

function orderedListeners(registry, hook) {
  return [...(registry.get(hookName(hook)) || [])].sort((left, right) => left.priority - right.priority || left.sequence - right.sequence);
}

function doAction(hook, ...args) {
  for (const listener of orderedListeners(actions, hook)) {
    try {
      const result = listener.callback(...args);
      if (result && typeof result.catch === 'function') result.catch((error) => reportPluginError(listener.plugin, hook, error));
    } catch (error) {
      reportPluginError(listener.plugin, hook, error);
    }
  }
}

function applyFilters(hook, initialValue, ...args) {
  let value = initialValue;
  for (const listener of orderedListeners(filters, hook)) {
    try {
      const filtered = listener.callback(value, ...args);
      if (filtered && typeof filtered.then === 'function') {
        reportPluginError(listener.plugin, hook, new TypeError('PlayHub browser filters must return synchronously.'));
      } else {
        value = filtered;
      }
    } catch (error) {
      reportPluginError(listener.plugin, hook, error);
    }
  }
  return value;
}

function contextFor(plugin) {
  return Object.freeze({
    ...plugin,
    addAction: (hook, callback, priority = 10) => addListener(actions, hook, callback, priority, plugin.id),
    addFilter: (hook, callback, priority = 10) => addListener(filters, hook, callback, priority, plugin.id),
    doAction,
    applyFilters,
    onReady: (callback, priority = 10) => onReady(callback, priority, plugin.id),
    registerPage: (definition) => registerPage(plugin.id, definition),
    registerAdminPage: (definition) => registerAdminPage(plugin.id, definition),
    navigate,
  });
}

function register(id, setup) {
  const normalizedId = pluginId(id);
  const plugin = pluginMetadata.find((item) => item.id === normalizedId);
  if (!plugin) throw new Error(`Plugin ${normalizedId} is not enabled for this page.`);
  if (registeredPlugins.has(normalizedId)) throw new Error(`Plugin ${normalizedId} is already registered.`);
  if (typeof setup !== 'function') throw new TypeError('PlayHub plugins must register with a setup function.');
  registeredPlugins.add(normalizedId);
  try {
    setup(contextFor(plugin));
    doAction('playhub.plugin.registered', plugin);
    window.dispatchEvent(new CustomEvent('playhub:plugin-registered', { detail: { plugin } }));
  } catch (error) {
    registeredPlugins.delete(normalizedId);
    removePluginListeners(normalizedId);
    reportPluginError(normalizedId, 'playhub.plugin.register', error);
  }
}

function navigate(to, options = {}) {
  const url = new URL(String(to || ''), window.location.origin);
  if (url.origin !== window.location.origin) throw new TypeError('PlayHub navigation must remain on the current origin.');
  window.dispatchEvent(new CustomEvent('playhub:navigate-request', {
    detail: { to: `${url.pathname}${url.search}${url.hash}`, replace: Boolean(options?.replace) },
  }));
}

function onReady(callback, priority = 10, id = 'application') {
  if (typeof callback !== 'function') throw new TypeError('PlayHub ready listeners must be functions.');
  const owner = pluginId(id);
  if (ready) {
    queueMicrotask(() => {
      try {
        const result = callback(readyContext);
        if (result && typeof result.catch === 'function') result.catch((error) => reportPluginError(owner, 'playhub.ready', error));
      } catch (error) {
        reportPluginError(owner, 'playhub.ready', error);
      }
    });
    return () => {};
  }
  return addListener(actions, 'playhub.ready', callback, priority, owner);
}

const publicRuntime = Object.freeze({
  apiVersion: 1,
  plugins: Object.freeze(pluginMetadata),
  register,
  addAction: (hook, callback, priority = 10) => addListener(actions, hook, callback, priority),
  addFilter: (hook, callback, priority = 10) => addListener(filters, hook, callback, priority),
  doAction,
  applyFilters,
  onReady,
  getPages,
  getAdminPages,
  navigate,
});

Object.defineProperty(window, 'PlayHub', {
  value: publicRuntime,
  configurable: false,
  enumerable: true,
  writable: false,
});

export function markPlayHubReady(context = {}) {
  if (ready) return;
  ready = true;
  readyContext = Object.freeze({ ...context });
  doAction('playhub.ready', readyContext);
}
