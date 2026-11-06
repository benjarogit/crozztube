import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { createHash } from 'node:crypto';
import assert from 'node:assert/strict';
import { chromium, expect } from '@playwright/test';
import { applicationJson, layoutSession, layoutProblems } from './fixtures/readonly-layout.ts';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..');
const batch = JSON.parse(fs.readFileSync(path.join(root, 'tools/i18n/batches/rest-asian-rtl-count-expansion.json')));
const source = applicationJson('/api/i18n/en', false).patterns;
const requestedLocale = process.argv.find(arg => arg.startsWith('--locale='))?.slice(9);
if (requestedLocale) assert.ok(batch.metadata[requestedLocale], 'Unknown locale for count review.');
const counts = [0, 1, 2, 3, 11, 21, 1000];
const words = { view: 'views', subscriber: 'subscribers', like: 'likes', reply: 'replies', video: 'videos', user: 'users', comment: 'comments', playlist: 'playlists' };
const hash = text => createHash('sha256').update(String(text)).digest('hex');
const browser = await chromium.launch({ headless: true });
try {
  for (const [locale, metadata] of Object.entries(batch.metadata)) {
    if (requestedLocale && locale !== requestedLocale) continue;
    const session = await layoutSession(browser, { authenticated: false, language: locale, width: 390 });
    try {
      await session.goto('/login');
      const original = Object.entries(words).flatMap(([unit, word]) => counts.map(count => ({ unit, count, text: `${count} ${word}` })));
      await session.page.evaluate(rows => {
        const section = document.createElement('section');
        section.id = 'rest-asian-quantity-context';
        for (const row of rows) {
          const span = document.createElement('span');
          span.style.display = 'block';
          span.textContent = row.text;
          section.append(span);
        }
        document.querySelector('.auth-card').append(section);
      }, original);
      const chosenLocale = await session.page.locator('html').getAttribute('lang');
      assert.equal(chosenLocale, locale.replaceAll('_', '-'));
      const expected = await session.page.evaluate(({ original, patterns, chosenLocale }) => {
        const number = new Intl.NumberFormat(chosenLocale, { maximumFractionDigits: 2 });
        return original.map(row => patterns[`count_${row.unit}`].replace('{count}', number.format(row.count)));
      }, { original, patterns: metadata.__patterns, chosenLocale });
      await expect(session.page.locator('#rest-asian-quantity-context span')).toHaveText(expected);
      assert.deepEqual(await layoutProblems(session.page), [], `${locale}: narrow quantity rendering`);
      assert.deepEqual(session.errors, [], `${locale}: runtime errors`);
      assert.deepEqual(session.writes, [], `${locale}: read-only browser`);
      if (process.argv.includes('--record')) {
        const file = path.join(root, 'tools/i18n/reviews', `${locale}.json`);
        const review = JSON.parse(fs.readFileSync(file));
        for (const [key, value] of Object.entries(metadata.__patterns)) {
          const row = review.messages[hash(`__patterns:${key}`)];
          assert.equal(row?.source_hash, hash(JSON.stringify(source[key])));
          assert.equal(row?.translation_hash, hash(JSON.stringify(value)));
          row.status = 'verified';
          row.verified_at = new Date().toISOString();
          row.contexts = [...new Set([...row.contexts, 'Complete quantity patterns through actual DOM translation bridge at 390px; 0/1/2/3/11/21/1000 for views/subscribers/likes/replies/videos/users/comments/Playlists, locale digits and original number retained, no overflow/runtime errors/application writes'])];
        }
        fs.writeFileSync(file, JSON.stringify(review, null, 2) + '\n');
      }
      console.log(`${locale}: verified 8 complete count patterns across ${counts.length} values.`);
    } finally { await session.context.close(); }
  }
} finally { await browser.close(); }
