import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { execFileSync } from 'node:child_process';
import { reviewableEntries, reviewStatus, messageHash, sourceReference } from './audit.mjs';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const args = process.argv.slice(2);
const option = name => args.find(value => value.startsWith(`--${name}=`))?.slice(name.length + 3);
const locale = option('locale');
if (!locale || !/^[a-z0-9_]+$/.test(locale)) throw new Error('Usage: node tools/i18n/review-queue.mjs --locale=CODE [--status=pending|editorial|verified|stale] [--audit=REPORT.json] [--input=EXPORT.json] [--limit=50]');
const input = option('input');
const packs = JSON.parse(input ? fs.readFileSync(input, 'utf8') : execFileSync('docker', ['exec', process.env.WEB_CONTAINER || 'crozztube-web', 'php', 'tools/i18n/export.php'], { cwd: root, encoding: 'utf8', maxBuffer: 100 * 1024 * 1024 }));
if (!packs[locale]) throw new Error('Unknown or retired language. Use its canonical regional code.');
const source = reviewableEntries(packs.en), target = reviewableEntries(packs[locale]);
const reviewFile = path.join(root, 'tools/i18n/reviews', `${locale}.json`);
const reviews = fs.existsSync(reviewFile) ? JSON.parse(fs.readFileSync(reviewFile, 'utf8')).messages : {};
const audit = option('audit') ? JSON.parse(fs.readFileSync(option('audit'), 'utf8')) : null;
const locations = new Map(audit?.ui_inventory?.map(row => [row.message, row.locations]) || []);
const missing = audit?.languages?.[locale]?.issues?.missing_ui_messages || [];
const keys = [...new Set([...Object.keys(source), ...Object.keys(target), ...missing])];
const requestedStatus = option('status');
if (requestedStatus && !['pending', 'editorial', 'verified', 'stale'].includes(requestedStatus)) throw new Error('Unknown review status.');
const limit = option('limit') === undefined ? Infinity : Number(option('limit'));
if (!(limit >= 0) || (Number.isFinite(limit) && !Number.isInteger(limit))) throw new Error('The limit must be a non-negative integer.');
const rows = keys.map(key => {
  const reference = sourceReference(source, key);
  const translation = target[key] ?? null;
  const review = reviews?.[messageHash(key)];
  return { locale, key, reference, translation,
    source_hash: messageHash(reference), translation_hash: translation === null ? null : messageHash(translation),
    status: translation === null ? 'pending' : reviewStatus(reference, translation, review),
    contexts: review?.contexts || [], source_locations: locations.get(key) || [],
    missing_translation: translation === null,
  };
}).filter(row => !requestedStatus || row.status === requestedStatus);
console.log(JSON.stringify({ locale, matching_messages: rows.length, messages: rows.slice(0, limit) }, null, 2));
