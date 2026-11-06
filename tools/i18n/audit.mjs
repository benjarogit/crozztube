import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { execFileSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { parse } from '@babel/parser';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const normalize = value => String(value).replace(/\s+/g, ' ').trim();
export const placeholders = value => [...String(value).matchAll(/\{[a-zA-Z_][a-zA-Z0-9_]*\}/g)].map(match => match[0]).sort();
const entries = pack => Object.fromEntries(Object.entries(pack).filter(([key, value]) => !key.startsWith('__') && typeof value === 'string'));
export const messageHash = value => createHash('sha256').update(String(value)).digest('hex');
export const reviewableEntries = pack => Object.fromEntries([
  ...Object.entries(entries(pack)),
  ...['__patterns', '__plurals'].flatMap(section => Object.entries(pack[section] || {}).map(([key, value]) => [`${section}:${key}`, JSON.stringify(value)])),
]);
export const patternReferenceKey = key => /^((?:count_(?:user|video|comment|subscriber|view|like|reply|playlist))|(?:(?:ago|duration)_(?:year|month|week|day|hour|minute|second)))_(?:zero|one|two|few|many|other)$/.exec(key)?.[1];
export function sourceReference(source, key) {
  if (source[key] !== undefined) return source[key];
  if (key.startsWith('__patterns:')) {
    const base = patternReferenceKey(key.slice('__patterns:'.length));
    if (base && source[`__patterns:${base}`] !== undefined) return source[`__patterns:${base}`];
  }
  return key;
}
export function reviewStatus(source, translation, review) {
  if (!review) return 'pending';
  if (review.source_hash !== messageHash(source) || review.translation_hash !== messageHash(translation)) return 'stale';
  return ['editorial', 'verified'].includes(review.status) && review.contexts?.length ? review.status : 'pending';
}

// These are review prompts, never automatic replacements. For example, French
// plural vous and German third-person Sie can be correct outside direct address.
const addressPatterns = {
  de: /\b(?:Sie|Ihnen|Ihr(?:e|en|em|er|es)?)\b/u,
  nl: /\b(?:u|uw|uwe)\b/iu,
  fr: /\b(?:vous|votre|vos|veuillez)\b/iu,
  es: /\b(?:usted|ustedes)\b/iu,
  it: /\b(?:Lei|Suo|Sua|Suoi|Sue|La preghiamo)\b/u,
  pl: /\b(?:Pan|Pani|Państwo|Państwa|Pański|Pana)\b/u,
  cs: /(?<!\p{L})(?:Vy|Vás|Vám|Vaše|Váš)(?!\p{L})/u,
  sk: /(?<!\p{L})(?:Vy|Vás|Vám|Vaše|Váš)(?!\p{L})/u,
  ru: /(?<!\p{L})(?:Вы|Вас|Вам|Ваш(?:а|е|и|его|ему|ей|их|им|ими)?)(?!\p{L})/iu,
  uk: /(?<!\p{L})(?:Ви|Вас|Вам|Ваш(?:а|е|і|ого|ому|ої|их|им|ими)?)(?!\p{L})/iu,
  bg: /(?<!\p{L})(?:Вие|Вас|Вам|Ваш(?:а|е|и|ият|ата|ето|ите)?)(?!\p{L})/iu,
  el: /(?<!\p{L})(?:εσείς|εσάς|σας)(?!\p{L})/iu,
  ro: /(?<!\p{L})(?:dumneavoastră|dvs)(?!\p{L})/iu,
  hu: /(?<!\p{L})(?:Ön|Önök|Önt|Önnek|Önnel|Önöket)(?!\p{L})/u,
  pt: /\b(?:o senhor|a senhora|Vossa Excelência)\b/iu,
  tr: /(?<!\p{L})(?:siz|sizin|size|sizi|sizden)(?!\p{L})/iu,
  id: /\bAnda\b/iu,
  ms: /\banda\b/iu,
  vi: /quý khách|quý vị/iu,
  hi: /आप(?:के|की|का|को|से)?/u,
  bn: /আপনি|আপনার|আপনাকে/u,
  ur: /آپ/u,
  th: /ท่าน/u,
  ar: /حضرتك|سيادتك|حضراتكم/u,
  zh: /您|貴用戶|贵用户/u,
  ja: /お客様|御客様|貴殿|貴方/u,
  ko: /고객님|귀하/u,
};
export function formalAddressCandidate(locale, value) {
  return addressPatterns[locale.split('_')[0]]?.test(String(value)) ?? false;
}

export function reviewCoverage(packs, reviewDirectory = path.join(root, 'tools/i18n/reviews')) {
  const source = reviewableEntries(packs.en || {}), languages = {};
  for (const [locale, pack] of Object.entries(packs)) {
    const file = path.join(reviewDirectory, `${locale}.json`);
    const saved = fs.existsSync(file) ? JSON.parse(fs.readFileSync(file, 'utf8')) : {};
    const coverage = { pending: 0, editorial: 0, verified: 0, stale: 0, total: 0 };
    for (const [key, value] of Object.entries(reviewableEntries(pack))) {
      const review = saved.messages?.[messageHash(key)];
      coverage.total++;
      coverage[reviewStatus(sourceReference(source, key), value, review)]++;
    }
    languages[locale] = coverage;
  }
  return languages;
}

export function auditPacks(packs, uiMessages = []) {
  const source = entries(packs.en || {});
  const sourceKeys = Object.keys(source);
  const result = { source_locale: 'en', source_entries: sourceKeys.length, ui_messages: uiMessages.length, languages: {}, totals: { languages: 0, entries: 0, errors: 0, review_candidates: 0 } };
  for (const [locale, pack] of Object.entries(packs)) {
    const dictionary = entries(pack);
    const english = /^en(?:_|$)/.test(locale);
    const issues = { missing_keys: [], empty_values: [], placeholder_mismatches: [], protected_markers: [], invalid_patterns: [], invalid_plural_forms: [], english_candidates: [], mixed_language_candidates: [], formal_address_candidates: [], copied_patterns: [], copied_plural_keys: [], missing_ui_messages: [] };
    issues.missing_keys = sourceKeys.filter(key => !(key in dictionary));
    for (const [key, value] of Object.entries(dictionary)) {
      if (!value.trim()) issues.empty_values.push(key);
      const expected = placeholders(source[key] ?? key), actual = placeholders(value);
      if (JSON.stringify(expected) !== JSON.stringify(actual)) issues.placeholder_mismatches.push({ section: 'translation', key, expected, actual });
      if (/ZXTERM\d+ZX/.test(value)) issues.protected_markers.push(key);
      if (!english && (value === (source[key] ?? key) || value === key) && /\p{L}/u.test(value) && value.split(/\s+/).length >= 3 && value.length >= 20 && !/^https?:\/\//.test(value)) issues.english_candidates.push(key);
      if (!english && value !== (source[key] ?? key) && /\b(?:the|your|with|from|this|that|before|after|cannot|unable|will|have|when)\b/i.test(value)
        && (value.match(/\b(?:the|your|with|from|this|that|before|after|cannot|unable|will|have|when)\b/gi) || []).length >= 2) issues.mixed_language_candidates.push(key);
      if (formalAddressCandidate(locale, value)) issues.formal_address_candidates.push(key);
    }
    for (const [key, value] of Object.entries(pack.__patterns || {})) {
      if (typeof value !== 'string' || !value.trim()) issues.invalid_patterns.push(key);
      const base = patternReferenceKey(key);
      const sourcePattern = packs.en?.__patterns?.[key] ?? (base ? packs.en?.__patterns?.[base] : '') ?? '';
      const expected = placeholders(sourcePattern), actual = placeholders(value);
      if (JSON.stringify(expected) !== JSON.stringify(actual)) issues.placeholder_mismatches.push({ section: 'pattern', key, expected, actual });
      if (!english && value === sourcePattern) issues.copied_patterns.push(key);
    }
    for (const [key, forms] of Object.entries(pack.__plurals || {})) {
      if (!forms || typeof forms !== 'object' || !Object.values(forms).length
        || Object.values(forms).some(value => typeof value !== 'string' || !value.trim())
        || (!Array.isArray(forms) && (!forms.other || Object.keys(forms).some(category => !['zero', 'one', 'two', 'few', 'many', 'other'].includes(category))))) issues.invalid_plural_forms.push(key);
      if (!english && JSON.stringify(forms) === JSON.stringify(packs.en?.__plurals?.[key])) issues.copied_plural_keys.push(key);
    }
    issues.missing_ui_messages = uiMessages.filter(item => !(item.message in dictionary)).map(item => item.message);
    const errors = issues.empty_values.length + issues.placeholder_mismatches.length + issues.protected_markers.length + issues.invalid_patterns.length + issues.invalid_plural_forms.length;
    const review = Object.values(issues).reduce((sum, rows) => sum + rows.length, 0) - errors;
    result.languages[locale] = { entries: Object.keys(dictionary).length, formal_variant: /(?:^|_)formal(?:_|$)/.test(locale), errors, review_candidates: review, issues };
    result.totals.languages++;
    result.totals.entries += Object.keys(dictionary).length;
    result.totals.errors += errors;
    result.totals.review_candidates += review;
  }
  return result;
}

export function extractUiMessages(directory) {
  const messages = new Map();
  const textAttributes = new Set(['placeholder', 'title', 'aria-label', 'alt', 'label', 'text', 'subtitle', 'description', 'emptyText', 'emptyMessage', 'loadingText', 'message', 'tooltip']);
  const add = (value, file, line, kind) => {
    const message = normalize(value);
    if (!/[a-zA-Z]/.test(message) || !message || /^[\d\s%.,:/_-]+$/.test(message)) return;
    const row = messages.get(message) || { message, locations: [] };
    row.locations.push({ file: path.relative(root, file), line, kind });
    messages.set(message, row);
  };
  const files = fs.readdirSync(directory, { recursive: true }).filter(file => /\.(?:jsx|js)$/.test(file));
  for (const relative of files) {
    const file = path.join(directory, relative);
    const ast = parse(fs.readFileSync(file, 'utf8'), { sourceType: 'module', plugins: ['jsx'] });
    const visit = (node, parent) => {
      if (!node || typeof node !== 'object') return;
      if (node.type === 'JSXText') add(node.value, file, node.loc.start.line, 'jsx');
      if (node.type === 'JSXAttribute' && textAttributes.has(node.name?.name) && node.value?.type === 'StringLiteral') add(node.value.value, file, node.loc.start.line, 'attribute');
      if (node.type === 'CallExpression' && /^(?:t|notify|setError|setNotice|setSuccess|setMessage|websiteText)$/.test(node.callee?.name || '')) {
        const branches = expression => {
          if (expression?.type === 'StringLiteral') add(expression.value, file, expression.loc.start.line, 'message');
          else if (expression?.type === 'ConditionalExpression') { branches(expression.consequent); branches(expression.alternate); }
          else if (expression?.type === 'LogicalExpression') branches(expression.right);
        };
        branches(node.arguments[0]);
      }
      // Explicit translation vocabularies feed component APIs through t(word).
      // Treat their literal members as UI copy, without collecting arbitrary arrays.
      if (node.type === 'VariableDeclarator' && /TranslationWords$/.test(node.id?.name || '')) {
        const list = node.init?.type === 'ArrayExpression' ? node.init : node.init?.callee?.object?.name === 'Object' && node.init?.callee?.property?.name === 'freeze' ? node.init.arguments[0] : null;
        if (list?.type === 'ArrayExpression') for (const item of list.elements) if (item?.type === 'StringLiteral') add(item.value, file, item.loc.start.line, 'component-vocabulary');
      }
      if (node.type === 'AssignmentExpression' && ['textContent', 'innerText', 'title', 'ariaLabel', 'placeholder'].includes(node.left?.property?.name) && node.right?.type === 'StringLiteral') add(node.right.value, file, node.loc.start.line, 'dom-label');
      if (node.type === 'CallExpression' && node.callee?.property?.name === 'setAttribute' && ['aria-label', 'title', 'placeholder'].includes(node.arguments[0]?.value) && node.arguments[1]?.type === 'StringLiteral') add(node.arguments[1].value, file, node.loc.start.line, 'dom-attribute');
      if (node.type === 'ObjectProperty' && ['label', 'title', 'subtitle', 'help', 'message', 'description'].includes(node.key?.name || node.key?.value) && node.value?.type === 'StringLiteral') add(node.value.value, file, node.loc.start.line, 'label');
      const visibleExpression = parent?.type !== 'JSXAttribute' || textAttributes.has(parent.name?.name);
      if (visibleExpression && node.type === 'JSXExpressionContainer' && node.expression?.type === 'StringLiteral') add(node.expression.value, file, node.loc.start.line, 'jsx');
      if (visibleExpression && node.type === 'JSXExpressionContainer') {
        const branches = expression => {
          if (expression?.type === 'StringLiteral') add(expression.value, file, expression.loc.start.line, 'jsx-branch');
          else if (expression?.type === 'ConditionalExpression') { branches(expression.consequent); branches(expression.alternate); }
          else if (expression?.type === 'LogicalExpression') branches(expression.right);
        };
        if (['ConditionalExpression', 'LogicalExpression'].includes(node.expression?.type)) branches(node.expression);
      }
      for (const [key, child] of Object.entries(node)) {
        if (['loc', 'start', 'end', 'comments', 'tokens', 'extra'].includes(key)) continue;
        if (Array.isArray(child)) child.forEach(value => visit(value, node));
        else if (child && typeof child === 'object') visit(child, node);
      }
    };
    visit(ast, null);
  }
  return [...messages.values()].sort((a, b) => a.message.localeCompare(b.message, 'en'));
}

function main() {
  const args = process.argv.slice(2);
  const option = key => args.includes(key) ? args[args.indexOf(key) + 1] : null;
  const input = option('--input');
  const packs = JSON.parse(input ? fs.readFileSync(input, 'utf8') : execFileSync('docker', ['exec', process.env.WEB_CONTAINER || 'crozztube-web', 'php', 'tools/i18n/export.php'], { cwd: root, maxBuffer: 100 * 1024 * 1024, encoding: 'utf8' }));
  const uiMessages = extractUiMessages(path.join(root, 'resources/js'));
  const inventory = new Map(uiMessages.map(row => [row.message, row]));
  const merge = rows => rows.forEach(row => {
    const existing = inventory.get(row.message);
    if (existing) existing.locations.push(...row.locations); else inventory.set(row.message, row);
  });
  const pluginRoot = path.join(root, 'docker/plugin-src');
  for (const plugin of fs.readdirSync(pluginRoot, { withFileTypes: true }).filter(entry => entry.isDirectory())) {
    const assets = path.join(pluginRoot, plugin.name, 'assets');
    if (fs.existsSync(assets)) merge(extractUiMessages(assets));
  }
  const phpInput = option('--php-input');
  merge(JSON.parse(phpInput ? fs.readFileSync(phpInput, 'utf8') : execFileSync('docker', ['exec', process.env.WEB_CONTAINER || 'crozztube-web', 'php', 'tools/i18n/php-inventory.php'], { cwd: root, encoding: 'utf8', maxBuffer: 30 * 1024 * 1024 })));
  const messages = [...inventory.values()];
  const coverage = reviewCoverage(packs);
  const report = { ...auditPacks(packs, messages), review_coverage: coverage, ui_inventory: messages };
  const output = option('--output');
  if (output) fs.writeFileSync(output, JSON.stringify(report, null, 2) + '\n');
  console.log(JSON.stringify({ ...report.totals, source_entries: report.source_entries, ui_messages: report.ui_messages, output }, null, 2));
  for (const locale of ['en', 'de_de', 'de_at', 'de_ch', 'de_ch_informal']) {
    const row = report.languages[locale];
    if (row) console.log(`${locale}: ${row.errors} structural errors; ${row.issues.english_candidates.length} English candidates; ${row.issues.formal_address_candidates.length} formal-address candidates; ${row.issues.missing_ui_messages.length} uncatalogued UI messages`);
  }
  if (args.includes('--check') && report.totals.errors) process.exitCode = 1;
  if (args.includes('--strict') && (report.totals.errors || Object.values(coverage).some(row => row.verified !== row.total) || Object.values(report.languages).some(row => row.issues.missing_ui_messages.length))) process.exitCode = 1;
}

if (process.argv[1] && path.resolve(process.argv[1]) === fileURLToPath(import.meta.url)) main();
