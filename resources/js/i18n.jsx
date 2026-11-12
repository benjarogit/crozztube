import React, { createContext, useContext, useEffect, useMemo, useState } from 'react';
import { createPortal } from 'react-dom';
import { formatMessage as formatPattern, pluralForm as pluralize, relativeAge, durationUnit, countMessage, countLabel, languageTag, catalogLocale } from './localizationFormat.js';

const STORAGE_KEY = 'playhub_language';
// Vidstack owns live labels and carries literal chapter, caption and track content.
// Its portalled menus keep these layout classes outside the React parent tree.
const directLocalizationSelector = '[data-i18n-skip], .notranslate, .vds-video-layout, .vds-audio-layout, .vds-tooltip-content';
// Vidstack 1.15 translates the font-menu hint, but renders built-in radio labels raw.
// Only these two application-owned static option lists participate in the bridge.
const mediaFontLabelSelector = '.vds-font-family-menu .vds-radio-label, .vds-text-shadow-menu .vds-radio-label';
const translatableAttributes = ['placeholder', 'title', 'aria-label', 'alt'];
const textNodeState = new WeakMap();
const attributeState = new WeakMap();
const brandedProjectMessages = new Map();

let availableLanguageCodes = [];
let languageAliases = typeof window !== 'undefined' ? window.__PLAYHUB_WEBSITE__?.languageAliases || {} : {};

export const languagePacks = {};

let applyingTranslations = false;
let domBridgePaused = false;
const priorityLanguageCodes = ['en', 'zh_cn', 'hi_in', 'es_es', 'ar', 'fr_fr'];

const rtlLanguageCodes = new Set([
  'ar', 'ary', 'azb', 'ckb', 'dv', 'fa', 'fa_af', 'fa_ir', 'haz', 'he', 'he_il',
  'ku', 'ps', 'rhg', 'sd', 'snd', 'skr', 'syr', 'ug', 'ug_cn', 'ur', 'yi',
]);

const I18nContext = createContext({
  languages: [],
  language: 'en',
  direction: 'ltr',
  setLanguage: () => {},
  t: (value, values = {}) => formatPattern(value, values),
  count: (value, unit) => countLabel(value, unit),
});

function normalizeLanguage(value) {
  const raw = catalogLocale(value);
  const next = languageAliases[raw] || raw;
  if (!availableLanguageCodes.length) return next;
  if (availableLanguageCodes.includes(next)) return next;
  const shortCode = next.split('_')[0];
  if (availableLanguageCodes.includes(shortCode)) return shortCode;
  return availableLanguageCodes.find((code) => code.split('_')[0] === shortCode) || availableLanguageCodes[0];
}

function storedLanguage() {
  try {
    return localStorage.getItem(STORAGE_KEY) || '';
  } catch (_) {
    return '';
  }
}

function websiteDefaultLanguage() {
  return typeof window !== 'undefined' ? window.__PLAYHUB_WEBSITE__?.defaultLanguage || '' : '';
}

function detectInitialLanguage() {
  return normalizeLanguage(storedLanguage() || websiteDefaultLanguage() || (typeof navigator !== 'undefined' ? navigator.language : '') || 'en');
}

export function getLanguageDirection(value) {
  const code = normalizeLanguage(value);
  const shortCode = code.split('_')[0];
  return rtlLanguageCodes.has(code) || rtlLanguageCodes.has(shortCode) ? 'rtl' : 'ltr';
}

function normalizeText(value) {
  return String(value ?? '').replace(/\u00a0/g, ' ').replace(/\s+/g, ' ').trim();
}

export function brandedProjectText(value, brand = 'PlayHub') {
  const source = String(value ?? '');
  if (!source.includes('PlayHub')) return source;
  const rendered = source.replace(/PlayHub/g, () => brand);
  brandedProjectMessages.set(normalizeText(rendered), { source: normalizeText(source), brand });
  return rendered;
}

function withOriginalSpacing(original, translated) {
  const source = String(original ?? '');
  const leading = source.match(/^\s*/)?.[0] || '';
  const trailing = source.match(/\s*$/)?.[0] || '';
  return `${leading}${translated}${trailing}`;
}

function translatePattern(text, language) {
  const pack = languagePacks[language] || {};
  const translations = pack.translations || {};
  const plurals = pack.plurals || {};
  const patterns = pack.patterns || {};
  const lower = text.toLowerCase();
  const countMatch = lower.match(/^([\d.,kmb]+)\s+([a-z]+)$/i);
  if (countMatch) {
    const complete = countMessage(countMatch[1], countMatch[2], patterns, language);
    if (complete) return complete;
    const formatted = durationUnit(countMatch[1], countMatch[2], language, patterns);
    if (formatted) return formatted;
  }
  if (countMatch && plurals[countMatch[2]]) {
    return `${countMatch[1]} ${pluralize(countMatch[1], plurals[countMatch[2]], language)}`;
  }
  if (countMatch && translations[countMatch[2]]) {
    return `${countMatch[1]} ${translations[countMatch[2]]}`;
  }

  const agoMatch = lower.match(/^([\d.,]+)\s+(year|years|month|months|week|weeks|day|days|hour|hours|minute|minutes|second|seconds)\s+ago$/);
  if (agoMatch) {
    const formatted = relativeAge(agoMatch[1], agoMatch[2], language, patterns);
    if (formatted) return formatted;
    if (plurals[agoMatch[2]]) return formatPattern(patterns.ago, { count: agoMatch[1], unit: pluralize(agoMatch[1], plurals[agoMatch[2]], language) });
  }

  const selectedMatch = lower.match(/^([\d.,]+)\s+user(?:s)?\s+selected$/);
  if (selectedMatch) {
    return formatPattern(patterns.selected_users, { count: selectedMatch[1], users: pluralize(selectedMatch[1], plurals.users || plurals.user || [], language) });
  }

  const showingMatch = text.match(/^Showing\s+([\d,]+)\s+to\s+([\d,]+)\s+of\s+([\d,]+)\s+(.+)$/i);
  if (showingMatch) {
    return formatPattern(patterns.showing, { from: showingMatch[1], to: showingMatch[2], total: showingMatch[3], items: translateText(showingMatch[4], language).toLowerCase() });
  }

  const unreadMatch = lower.match(/^([\d.,]+)\s+unread$/);
  if (unreadMatch) return formatPattern(patterns.unread, { count: unreadMatch[1] });

  const savedMatch = lower.match(/^([\d.,]+)\s+saved\s+videos$/);
  if (savedMatch) return formatPattern(patterns.saved_videos, { count: savedMatch[1] });

  const publishedMatch = lower.match(/^([\d.,]+)\s+published$/);
  if (publishedMatch) return formatPattern(patterns.published, { count: publishedMatch[1] });

  return null;
}

function translateCore(text, language) {
  const normalized = normalizeText(text);
  if (!normalized) return normalized;
  if (/^(?:https?|rtmps?|wss?):\/\//i.test(normalized)) return normalized;

  const count = normalized.match(/^([\d.,kmb]+)\s+(users?|videos?|comments?|subscribers?|views?|likes?|repl(?:y|ies)|playlists?)$/i);
  if (count) {
    const complete = countMessage(count[1], count[2].toLowerCase(), languagePacks[language]?.patterns || {}, language);
    if (complete) return complete;
  }
  const time = normalized.match(/^([\d.,]+)\s+(years?|months?|weeks?|days?|hours?|minutes?|seconds?)(\s+ago)?$/i);
  if (time) {
    const patterns = languagePacks[language]?.patterns || {};
    const formatted = time[3] ? relativeAge(time[1], time[2].toLowerCase(), language, patterns) : durationUnit(time[1], time[2].toLowerCase(), language, patterns);
    if (formatted) return formatted;
  }
  const dictionary = languagePacks[language]?.translations || {};
  const reference = languagePacks.en?.translations || {};
  const lookup = key => dictionary[key] && dictionary[key] !== key ? dictionary[key] : reference[key] || dictionary[key];
  const direct = lookup(normalized);
  if (direct) return direct;
  const project = brandedProjectMessages.get(normalized);
  if (project) {
    const translation = lookup(project.source);
    if (translation) return translation.replace(/PlayHub/g, () => project.brand);
  }

  const contributionAlertMatch = normalized.match(/^(.+) sent (tip|gift|super chat|super sticker)$/i);
  if (contributionAlertMatch) {
    const type = contributionAlertMatch[2].toLowerCase();
    const actionKey = { tip: 'sent a tip', gift: 'sent a gift', 'super chat': 'sent a Super Chat', 'super sticker': 'sent a Super Sticker' }[type];
    const actor = contributionAlertMatch[1] === 'A viewer' ? (dictionary['A viewer'] || contributionAlertMatch[1]) : contributionAlertMatch[1];
    return `${actor} ${dictionary[actionKey] || actionKey}`;
  }

  const dynamicPatterns = [
    [/^This stream can start in (.+)\.$/, 'This stream can start in {time}.', ['time']],
    [/^(.+) live preview$/, '{name} live preview', ['name']],
    [/^Spotlight (.+)$/, 'Spotlight {name}', ['name']],
    [/^Remove (.+) from this live stream\?$/, 'Remove {name} from this live stream?', ['name']],
    [/^Remove (.+)$/, 'Remove {name}', ['name']],
    [/^(.+) connection quality$/, '{quality} connection quality', ['quality']],
    [/^(.+) joined the stage\.$/, '{name} joined the stage.', ['name']],
    [/^(.+) left the stage\.$/, '{name} left the stage.', ['name']],
    [/^(.+) was removed from the stage\.$/, '{name} was removed from the stage.', ['name']],
    [/^(.+) was invited as (.+)\.$/, '{name} was invited as {role}.', ['name', 'role']],
    [/^(.+) can now join as (.+)\.$/, '{name} can now join as {role}.', ['name', 'role']],
    [/^(.+) copied to clipboard\.$/, '{label} copied to clipboard.', ['label']],
    [/^The selected (.+) is unavailable\.$/, 'The selected {item} is unavailable.', ['item']],
    [/^Slow mode is active\. Wait (\d+) seconds before sending another message\.$/, 'Slow mode is active. Wait {seconds} seconds before sending another message.', ['seconds']],
    [/^Add (.+)$/, 'Add {item}', ['item']],
    [/^Use (.+) accent$/, 'Use {color} accent', ['color']],
    [/^Camera (.+)$/, 'Camera {status}', ['status']],
    [/^Microphone (.+)$/, 'Microphone {status}', ['status']],
    [/^Open (.+) channel$/, 'Open {name} channel', ['name']],
    [/^(.+) is live on PlayHub\.$/, '{name} is live on PlayHub.', ['name']],
    [/^(.+) preview$/, '{name} preview', ['name']],
    [/^Watch (.+)$/, 'Watch {title}', ['title']],
  ];
  for (const [pattern, key, names] of dynamicPatterns) {
    const match = normalized.match(pattern);
    const template = dictionary[key];
    if (!match || !template) continue;
    return formatPattern(template, Object.fromEntries(names.map((name, index) => {
      const value = match[index + 1];
      return [name, ['name', 'title'].includes(name) ? value : translateCore(value, language)];
    })));
  }

  if (/^\((.+)\)$/.test(normalized)) {
    return `(${translateCore(normalized.slice(1, -1), language)})`;
  }

  const suffixMatch = normalized.match(/^(.+?)(\s*[→›↗⌄]+)$/);
  if (suffixMatch) return `${translateCore(suffixMatch[1], language)}${suffixMatch[2]}`;

  const parentheticalMatch = normalized.match(/^(.+?)\s+\(([^)]+)\)$/);
  if (parentheticalMatch) return `${translateCore(parentheticalMatch[1], language)} (${parentheticalMatch[2]})`;

  const prefixMatch = normalized.match(/^([✓●∞$⊘#•\s]+)(.+)$/);
  if (prefixMatch) return `${prefixMatch[1]}${translateCore(prefixMatch[2], language)}`;

  const colonMatch = normalized.match(/^([^:]{2,40})(:\s*)(.+)$/);
  if (colonMatch) {
    const left = translateCore(colonMatch[1], language), right = translateCore(colonMatch[3], language);
    if (left !== colonMatch[1] || right !== colonMatch[3]) return `${left}${colonMatch[2]}${right}`;
  }

  for (const separator of [' · ', ' • ']) {
    if (normalized.includes(separator)) {
      return normalized.split(separator).map((part) => translateCore(part, language)).join(separator);
    }
  }

  const pattern = translatePattern(normalized, language);
  if (pattern) return pattern;

  const percentPhraseMatch = normalized.match(/^([\d.,]+%)\s+(.+)$/);
  if (percentPhraseMatch) {
    const translatedPhrase = translateCore(percentPhraseMatch[2], language);
    if (translatedPhrase !== percentPhraseMatch[2]) return `${percentPhraseMatch[1]} ${translatedPhrase}`;
  }

  const countPhraseMatch = normalized.match(/^([\d.,kmb]+)\s+(.+)$/i);
  if (countPhraseMatch) {
    const translatedPhrase = translateCore(countPhraseMatch[2], language);
    if (translatedPhrase !== countPhraseMatch[2]) return `${countPhraseMatch[1]} ${translatedPhrase}`;
  }

  return normalized;
}

export function translateText(value, language = 'en') {
  const source = String(value ?? '');
  if (!source.trim()) return source;
  const translated = translateCore(source, normalizeLanguage(language));
  return withOriginalSpacing(source, translated);
}

function shouldSkipElement(element) {
  if (!element || element.nodeType !== 1) return true;
  const tag = element.tagName;
  if (['SCRIPT', 'STYLE', 'TEXTAREA'].includes(tag)) return true;
  if (element.matches?.(mediaFontLabelSelector)) return false;
  return Boolean(element.closest?.(directLocalizationSelector));
}

function applyTextNode(node, language) {
  if (!node?.nodeValue?.trim() || shouldSkipElement(node.parentElement)) return;

  let state = textNodeState.get(node);
  const current = node.nodeValue;
  if (!state || (current !== state.original && current !== state.translated)) {
    state = { original: current, translated: current };
  }

  state.translated = translateText(state.original, language);
  textNodeState.set(node, state);
  if (node.nodeValue !== state.translated) node.nodeValue = state.translated;
}

function applyElementAttributes(element, language) {
  if (!element || element.nodeType !== 1 || element.hasAttribute('data-i18n-skip-attributes') || ['SCRIPT', 'STYLE'].includes(element.tagName) || element.closest?.(directLocalizationSelector)) return;
  let states = attributeState.get(element);
  if (!states) {
    states = {};
    attributeState.set(element, states);
  }

  translatableAttributes.forEach((attribute) => {
    if (!element.hasAttribute(attribute)) return;
    const current = element.getAttribute(attribute);
    if (!current?.trim()) return;
    let state = states[attribute];
    if (!state || (current !== state.original && current !== state.translated)) {
      state = { original: current, translated: current };
    }
    state.translated = translateText(state.original, language);
    states[attribute] = state;
    if (current !== state.translated) element.setAttribute(attribute, state.translated);
  });
}

function restoreTextNode(node) {
  const state = textNodeState.get(node);
  if (!state) return;
  if (node.nodeValue === state.translated && state.original !== state.translated) {
    node.nodeValue = state.original;
  }
  state.translated = state.original;
  textNodeState.set(node, state);
}

function restoreElementAttributes(element) {
  const states = attributeState.get(element);
  if (!states) return;

  translatableAttributes.forEach((attribute) => {
    const state = states[attribute];
    if (!state || !element.hasAttribute(attribute)) return;
    if (element.getAttribute(attribute) === state.translated && state.original !== state.translated) {
      element.setAttribute(attribute, state.original);
    }
    state.translated = state.original;
    states[attribute] = state;
  });
}

function restoreElementTree(root) {
  if (!root || typeof document === 'undefined') return;

  domBridgePaused = true;
  applyingTranslations = true;
  try {
    if (root.nodeType === Node.TEXT_NODE) {
      restoreTextNode(root);
      return;
    }

    if (root.nodeType !== Node.ELEMENT_NODE) return;
    restoreElementAttributes(root);
    root.querySelectorAll?.('*').forEach((element) => restoreElementAttributes(element));

    const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
    while (walker.nextNode()) restoreTextNode(walker.currentNode);
  } finally {
    applyingTranslations = false;
  }
}

function restoreTranslatedDom() {
  if (typeof document === 'undefined' || !document.body) return;
  restoreElementTree(document.body);
}

function applyElementTree(root, language) {
  if (!root || typeof document === 'undefined') return;

  if (root.nodeType === Node.TEXT_NODE) {
    applyTextNode(root, language);
    return;
  }

  if (root.nodeType !== Node.ELEMENT_NODE) return;
  root.querySelectorAll?.(mediaFontLabelSelector).forEach(label => {
    for (const node of label.childNodes) if (node.nodeType === Node.TEXT_NODE) applyTextNode(node, language);
  });
  if (shouldSkipElement(root)) return;
  applyElementAttributes(root, language);
  root.querySelectorAll?.('*').forEach((element) => applyElementAttributes(element, language));

  const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, {
    acceptNode: (node) => shouldSkipElement(node.parentElement) ? NodeFilter.FILTER_REJECT : NodeFilter.FILTER_ACCEPT,
  });

  while (walker.nextNode()) applyTextNode(walker.currentNode, language);
}

function I18nDomBridge({ language, version }) {
  useEffect(() => {
    if (typeof document === 'undefined' || !document.body) return undefined;

    domBridgePaused = false;
    applyingTranslations = true;
    applyElementTree(document.body, language);
    applyingTranslations = false;

    const observer = new MutationObserver((mutations) => {
      if (applyingTranslations || domBridgePaused) return;
      applyingTranslations = true;
      mutations.forEach((mutation) => {
        if (mutation.type === 'characterData') applyTextNode(mutation.target, language);
        if (mutation.type === 'attributes') applyElementAttributes(mutation.target, language);
        mutation.addedNodes?.forEach((node) => applyElementTree(node, language));
      });
      applyingTranslations = false;
    });

    observer.observe(document.body, {
      childList: true,
      subtree: true,
      characterData: true,
      attributes: true,
      attributeFilter: translatableAttributes,
    });

    return () => observer.disconnect();
  }, [language, version]);

  return null;
}

export function I18nProvider({ children }) {
  const [language, setLanguageState] = useState(detectInitialLanguage);
  const [languages, setLanguages] = useState([]);
  const [catalogVersion, setCatalogVersion] = useState(0);

  useEffect(() => {
    if (language === 'en' || languagePacks.en?.loaded) return undefined;
    let cancelled = false;
    fetch('/api/i18n/en', { headers: { Accept: 'application/json' } })
      .then(response => response.ok ? response.json() : null)
      .then(pack => {
        if (cancelled || !pack?.translations) return;
        languagePacks.en = { ...languagePacks.en, ...pack, loaded: true };
        restoreTranslatedDom();
        setCatalogVersion(version => version + 1);
      }).catch(() => {});
    return () => { cancelled = true; };
  }, [language]);

  useEffect(() => {
    if (typeof document !== 'undefined') {
      const direction = getLanguageDirection(language);
      if (language) document.documentElement.lang = languageTag(language);
      document.documentElement.dir = direction;
      document.documentElement.dataset.direction = direction;
      document.body?.setAttribute('dir', direction);
      document.body?.classList.toggle('is-rtl', direction === 'rtl');
      document.body?.classList.toggle('is-ltr', direction !== 'rtl');
    }
  }, [language]);

  useEffect(() => {
    const locale = normalizeLanguage(language);
    if (!locale) return undefined;
    if (languagePacks[locale]?.loaded) return undefined;

    let cancelled = false;
    fetch(`/api/i18n/${locale}`, { headers: { Accept: 'application/json' } })
      .then((response) => response.ok ? response.json() : null)
      .then((pack) => {
        if (cancelled || !pack?.translations) return;
        const canonical = String(pack.code || locale).toLowerCase().replace(/-/g, '_');
        languagePacks[canonical] = {
          code: pack.code || locale,
          label: pack.label || languagePacks[locale]?.label || locale.toUpperCase(),
          nativeLabel: pack.nativeLabel || pack.native_label || languagePacks[locale]?.nativeLabel || locale.toUpperCase(),
          direction: pack.direction || getLanguageDirection(pack.code || locale),
          translations: pack.translations || {},
          plurals: pack.plurals || {},
          patterns: pack.patterns || {},
          loaded: true,
        };
        if (canonical !== locale) {
          languageAliases = { ...languageAliases, [locale]: canonical };
          try { localStorage.setItem(STORAGE_KEY, canonical); } catch (_) {}
          setLanguageState(canonical);
        }
        restoreTranslatedDom();
        setCatalogVersion((version) => version + 1);
      })
      .catch(() => {});

    return () => {
      cancelled = true;
    };
  }, [language, catalogVersion]);

  useEffect(() => {
    let cancelled = false;
    fetch('/api/i18n', { headers: { Accept: 'application/json' } })
      .then((response) => response.ok ? response.json() : null)
      .then((payload) => {
        if (cancelled) return;
        const items = Array.isArray(payload?.languages) ? payload.languages.filter((item) => item?.code) : [];
        if (!items.length) return;

        languageAliases = { ...languageAliases, ...(payload.aliases || {}) };
        availableLanguageCodes = items.map((item) => normalizeLanguage(item.code)).filter(Boolean);
        items.forEach((item) => {
          const code = normalizeLanguage(item.code);
          languagePacks[code] = {
            code,
            label: item.label || code.toUpperCase(),
            nativeLabel: item.nativeLabel || item.native_label || item.label || code.toUpperCase(),
            direction: item.direction || getLanguageDirection(code),
            translations: {},
            plurals: {},
            patterns: {},
            loaded: false,
            ...languagePacks[code],
          };
        });
        setLanguages(items.map((item) => ({
          code: normalizeLanguage(item.code),
          label: item.label || item.code,
          nativeLabel: item.nativeLabel || item.native_label || item.label || item.code,
          direction: item.direction || getLanguageDirection(item.code),
        })));
        restoreTranslatedDom();
        setLanguageState((current) => {
          const canonical = normalizeLanguage(current || storedLanguage() || websiteDefaultLanguage() || navigator.language || 'en');
          try { localStorage.setItem(STORAGE_KEY, canonical); } catch (_) {}
          return canonical;
        });
        setCatalogVersion((version) => version + 1);
      })
      .catch(() => {});

    return () => {
      cancelled = true;
    };
  }, []);

  const value = useMemo(() => ({
    languages,
    language,
    direction: getLanguageDirection(language),
    setLanguage: (nextLanguage) => {
      restoreTranslatedDom();
      const normalized = normalizeLanguage(nextLanguage);
      try { localStorage.setItem(STORAGE_KEY, normalized); } catch (_) {}
      setLanguageState(normalized);
    },
    t: (text, values = {}) => formatPattern(translateText(text, language), values),
    count: (value, unit) => countLabel(value, unit, languagePacks[language] || {}, language),
  }), [languages, language, catalogVersion]);

  return (
    <I18nContext.Provider value={value}>
      {children}
      <I18nDomBridge language={language} version={catalogVersion} />
    </I18nContext.Provider>
  );
}

export function useI18n() {
  return useContext(I18nContext);
}

export function LanguageDialog({ open, onClose }) {
  const { languages, language, setLanguage, t } = useI18n();
  const [query, setQuery] = useState('');
  const options = languages.length ? languages : (language ? [{ code: language, label: language.toUpperCase(), nativeLabel: language.toUpperCase() }] : []);
  const groupedOptions = useMemo(() => {
    const term = query.trim().toLowerCase();
    const matches = (item) => !term || [item.code, item.label, item.nativeLabel].filter(Boolean).some((value) => String(value).toLowerCase().includes(term));
    const sortByName = (items) => [...items].sort((a, b) => String(a.nativeLabel || a.label || a.code).localeCompare(String(b.nativeLabel || b.label || b.code), undefined, { sensitivity: 'base' }));
    const priority = priorityLanguageCodes
      .map((code) => options.find((item) => item.code === code) || options.find((item) => item.code.split('_')[0] === code.split('_')[0]))
      .filter(Boolean)
      .filter((item, index, list) => list.findIndex((candidate) => candidate.code === item.code) === index)
      .filter(matches);
    return { priority, all: sortByName(options).filter(matches) };
  }, [options, query]);

  useEffect(() => {
    if (!open) return undefined;
    const close = (event) => {
      if (event.key === 'Escape') onClose?.();
    };
    document.addEventListener('keydown', close);
    return () => document.removeEventListener('keydown', close);
  }, [open, onClose]);

  const chooseLanguage = (code) => {
    onClose?.();
    setQuery('');
    setLanguage(code);
  };

  if (!open) return null;

  const dialog = <div className="language-dialog-backdrop" role="presentation" onMouseDown={(event) => event.target === event.currentTarget && onClose?.()}>
    <section className="language-dialog" role="dialog" aria-modal="true" aria-labelledby="language-dialog-title">
      <div className="language-dialog-head">
        <div>
          <h2 id="language-dialog-title">{t('Language')}</h2>
          <p>{t('Choose your preferred language.')}</p>
        </div>
        <button type="button" className="language-dialog-close" onClick={onClose} aria-label={t('Close')}>×</button>
      </div>

      <label className="language-dialog-search">
        <span className="sr-only">{t('Search languages')}</span>
        <input value={query} onChange={(event) => setQuery(event.target.value)} placeholder={t('Search languages...')} autoFocus />
        {query && <button type="button" className="search-clear-button language-search-clear" onClick={() => setQuery('')} aria-label="Clear search"><svg viewBox="0 0 24 24" width="14" height="14" aria-hidden="true" fill="none" stroke="currentColor" strokeWidth="2.2" strokeLinecap="round" strokeLinejoin="round"><path d="m6 6 12 12M18 6 6 18" /></svg></button>}
      </label>

      <div className="language-dialog-list" data-i18n-skip>
        {[...groupedOptions.priority, { code: '__divider' }, ...groupedOptions.all].map((item) => {
          if (item.code === '__divider') return groupedOptions.priority.length && groupedOptions.all.length ? <div key="all-languages" className="language-dialog-divider">{t('All languages A-Z')}</div> : null;
          const selected = item.code === language;
          return <button type="button" key={item.code} className={selected ? 'active' : ''} onClick={() => chooseLanguage(item.code)} aria-current={selected ? 'true' : undefined}>
            <span>{item.nativeLabel || item.label || String(item.code).toUpperCase()}</span>
            <small>{String(item.code).replace(/_/g, '-')}</small>
          </button>;
        })}
      </div>
    </section>
  </div>;
  return typeof document === 'undefined' ? dialog : createPortal(dialog, document.body);
}

export function LanguageSwitcher({ compact = false, label = null }) {
  const { languages, language, t } = useI18n();
  const [open, setOpen] = useState(false);
  const options = languages.length ? languages : (language ? [{ code: language, label: language.toUpperCase(), nativeLabel: language.toUpperCase() }] : []);
  const current = options.find((item) => item.code === language) || options[0];

  return (
    <div className={`language-switcher ${compact ? 'compact' : ''}`}>
      <button type="button" className="language-switcher-trigger" onClick={() => options.length && setOpen(true)} aria-label={t('Language')} aria-haspopup="dialog" aria-expanded={open} disabled={!options.length}>
        <span data-i18n-skip={label ? undefined : true}>{label ? t(label) : (current?.nativeLabel || current?.label || String(language).toUpperCase())}</span>
        <b aria-hidden="true">⌄</b>
      </button>
      <LanguageDialog open={open} onClose={() => setOpen(false)} />
    </div>
  );
}
