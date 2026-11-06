import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { createHash } from 'node:crypto';
import assert from 'node:assert/strict';
import { chromium } from 'playwright';
import { applicationJson, layoutSession, layoutProblems } from './fixtures/readonly-layout.ts';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..');
const batch = JSON.parse(fs.readFileSync(path.join(root, 'tools/i18n/batches/community-foundation.json'), 'utf8'));
const key = 'Find out how we handle your data in our privacy policy.';
const hash = value => createHash('sha256').update(String(value)).digest('hex');
const source = applicationJson('/api/i18n/en', false).translations[key];
const requestedLocale = process.argv.find(arg => arg.startsWith('--locale='))?.slice(9);
const locales = applicationJson('/api/i18n', false).languages.map(row => row.code)
  .filter(locale => batch.messages[key][locale] || batch.messages[key][locale.split('_')[0]])
  .filter(locale => !requestedLocale || locale === requestedLocale);
assert.ok(locales.length, 'The requested locale must have an editorial batch to verify.');
const browser = await chromium.launch({ headless: true });
let checked = 0;
try {
  for (const locale of locales) {
    const expected = batch.messages[key][locale] || batch.messages[key][locale.split('_')[0]];
    const session = await layoutSession(browser, { authenticated: false, language: locale, width: 390 });
    try {
      await session.goto('/login');
      const link = session.page.locator('.auth-safe-note a');
      assert.equal((await link.innerText()).trim(), expected, `${locale}: complete translated sentence`);
      assert.equal(await link.getAttribute('href'), '/privacy');
      assert.deepEqual(await layoutProblems(session.page), [], `${locale}: narrow login layout`);
      assert.deepEqual(session.errors, [], `${locale}: runtime errors`);
      assert.deepEqual(session.writes, [], `${locale}: read-only browser check`);
      if (process.argv.includes('--record')) {
        const file = path.join(root, 'tools/i18n/reviews', `${locale}.json`);
        const review = JSON.parse(fs.readFileSync(file, 'utf8'));
        const row = review.messages[hash(key)];
        assert.equal(row?.source_hash, hash(source), `${locale}: source reference unchanged`);
        assert.equal(row?.translation_hash, hash(expected), `${locale}: editorial translation unchanged`);
        row.status = 'verified';
        row.contexts = [...new Set([...row.contexts, 'Login: complete privacy link, 390px, correct locale, no overflow, no writes or runtime errors'])];
        row.verified_at = new Date().toISOString();
        fs.writeFileSync(file, JSON.stringify(review, null, 2) + '\n');
      }
      checked++;
      if (checked % 10 === 0) console.log(`Verified privacy link and narrow layout: ${checked}/${locales.length} locales.`);
    } finally { await session.context.close(); }
  }
  console.log(`Verified complete privacy copy in ${checked} regional languages; this does not certify their remaining messages.`);
} finally { await browser.close(); }
