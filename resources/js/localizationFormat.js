import localeTags from '../localization/locale-tags.json' with { type: 'json' };

const relativeFormatters = new Map();
const unitFormatters = new Map();
const pluralRules = new Map();
const catalogCodes = new Map(Object.entries(localeTags).map(([code, tag]) => [tag.toLowerCase().replace(/-/g, '_'), code]));

export function catalogLocale(locale = '') {
  const code = String(locale || '').toLowerCase().replace(/-/g, '_');
  return catalogCodes.get(code) || code;
}

export function formatMessage(template, values = {}) {
  if (template == null || template === '') return template == null ? null : '';
  return String(template).replace(/\{([a-zA-Z0-9_]+)\}/g, (token, key) => values[key] == null ? token : String(values[key]));
}

// Catalog codes stay stable; Intl and HTML use registered BCP47 tags.
export function languageTag(locale = 'en') {
  const code = catalogLocale(locale || 'en');
  return localeTags[code] || code.replace(/_/g, '-');
}

function supportedLocale(locale, constructor) {
  try {
    const code = languageTag(locale);
    return constructor.supportedLocalesOf([code])[0] || null;
  } catch { return null; }
}

function numberValue(value) {
  const normalized = String(value).replace(/,/g, '').trim();
  const compact = normalized.match(/^(-?\d+(?:\.\d+)?)([kmb])$/i);
  const result = typeof value === 'number' ? value : compact
    ? Number(compact[1]) * ({ k: 1e3, m: 1e6, b: 1e9 }[compact[2].toLowerCase()]) : Number(normalized);
  return Number.isFinite(result) ? Math.abs(result) : null;
}

export function pluralForm(value, forms, locale = 'en') {
  const number = numberValue(value);
  if (number == null || !forms || typeof forms !== 'object') return '';
  const code = supportedLocale(locale, Intl.PluralRules);
  let category = 'other';
  if (code) {
    if (!pluralRules.has(code)) pluralRules.set(code, new Intl.PluralRules(code));
    category = pluralRules.get(code).select(number);
  }
  if (!Array.isArray(forms)) return forms[category] ?? forms.other ?? '';
  // Legacy packs use positional arrays. Named categories are preferred for
  // languages whose zero/two/few/many forms cannot fit this old convention.
  const index = { one: 0, few: 1, two: 1, many: 2 }[category] ?? forms.length - 1;
  return forms[Math.min(index, forms.length - 1)] || forms.at(-1) || '';
}

export function countMessage(value, unit, patterns = {}, locale = 'en') {
  const singular = ({ users: 'user', videos: 'video', comments: 'comment', subscribers: 'subscriber', views: 'view', likes: 'like', replies: 'reply', playlists: 'playlist' })[unit] || unit;
  if (!['user', 'video', 'comment', 'subscriber', 'view', 'like', 'reply', 'playlist'].includes(singular)) return null;
  return completeNumberPattern(value, `count_${singular}`, patterns, locale);
}

function completeNumberPattern(value, key, patterns, locale) {
  const number = numberValue(value);
  if (number == null) return null;
  const code = supportedLocale(locale, Intl.PluralRules);
  let category = 'other';
  if (code) {
    if (!pluralRules.has(code)) pluralRules.set(code, new Intl.PluralRules(code));
    category = pluralRules.get(code).select(number);
  }
  // Unsupported locales may supply a grammar-independent complete count label.
  // Never choose a neighbouring language's plural categories on their behalf.
  const template = code ? patterns[`${key}_${category}`] || patterns[key] || patterns[`${key}_other`] : patterns[key];
  if (typeof template !== 'string' || !template.includes('{count}')) return null;
  const numberLocale = supportedLocale(locale, Intl.NumberFormat);
  const formatted = numberLocale ? new Intl.NumberFormat(numberLocale, { notation: /[kmb]$/i.test(String(value).trim()) ? 'compact' : 'standard', maximumFractionDigits: 2 }).format(number) : String(value);
  return formatMessage(template, { count: formatted });
}

export function countLabel(value, unit, pack = {}, locale = 'en') {
  const plural = ({ user: 'users', video: 'videos', comment: 'comments', subscriber: 'subscribers', view: 'views', like: 'likes', reply: 'replies', playlist: 'playlists' })[unit];
  if (!plural) return String(value ?? '');
  const complete = countMessage(value, unit, pack.patterns, locale);
  if (complete) return complete;
  const forms = pack.plurals?.[plural] || pack.plurals?.[unit] || { one: unit, other: plural };
  let noun = pluralForm(value, forms, locale);
  // Old packs sometimes copied the English plural metadata despite having a
  // translated label. Prefer that label over displaying the leaked English word.
  if (noun === unit || noun === plural) noun = pack.translations?.[noun] || noun;
  return `${value ?? 0} ${noun}`;
}

export function relativeAge(value, unit, locale = 'en', patterns = {}) {
  const number = numberValue(value);
  const code = supportedLocale(locale, Intl.RelativeTimeFormat);
  const singular = String(unit).replace(/s$/, '');
  if (number == null || !['year', 'month', 'week', 'day', 'hour', 'minute', 'second'].includes(singular)) return null;
  const complete = completeNumberPattern(value, `ago_${singular}`, patterns, locale);
  if (complete) return complete;
  if (!code) return null;
  if (!relativeFormatters.has(code)) relativeFormatters.set(code, new Intl.RelativeTimeFormat(code, { numeric: 'always' }));
  return relativeFormatters.get(code).format(-number, singular);
}

export function durationUnit(value, unit, locale = 'en', patterns = {}) {
  const number = numberValue(value);
  const code = supportedLocale(locale, Intl.NumberFormat);
  const singular = String(unit).replace(/s$/, '');
  if (number == null || !['year', 'month', 'week', 'day', 'hour', 'minute', 'second'].includes(singular)) return null;
  const complete = completeNumberPattern(value, `duration_${singular}`, patterns, locale);
  if (complete) return complete;
  if (!code) return null;
  const key = `${code}:${singular}`;
  try {
    if (!unitFormatters.has(key)) unitFormatters.set(key, new Intl.NumberFormat(code, { style: 'unit', unit: singular, unitDisplay: 'long' }));
    return unitFormatters.get(key).format(number);
  } catch { return null; }
}
