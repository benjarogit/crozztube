import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { createHash } from 'node:crypto';
import assert from 'node:assert/strict';
import { chromium, expect } from '@playwright/test';
import { applicationJson, layoutSession, layoutProblems } from './fixtures/readonly-layout.ts';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..');
const batches = ['rest-european-core-semantics', 'rest-european-personal-core', 'rest-european-private-visibility', 'rest-european-baltic-balkan-personal', 'rest-european-personal-fy-cy-mk-sq'].map(name => JSON.parse(fs.readFileSync(path.join(root, `tools/i18n/batches/${name}.json`))));
const words = key => Object.assign({}, ...batches.map(batch => batch.messages[key] || {}));
const locales = Object.keys(words('Shorts'));
const requested = process.argv.find(arg => arg.startsWith('--locale='))?.slice(9);
const hash = value => createHash('sha256').update(String(value)).digest('hex');
const source = applicationJson('/api/i18n/en', false).translations;
const browser = await chromium.launch({ headless: true });
try {
  for (const locale of locales.filter(code => !requested || code === requested)) {
    const observed = new Map();
    const word = key => {
      const values = words(key), value = values[locale] ?? values[locale.split('_')[0]];
      if (value !== undefined) observed.set(key, value);
      return value;
    };
    const session = await layoutSession(browser, { language: locale, width: 390 });
    try {
      await session.goto('/upload');
      const page = session.page;
      await expect(page.locator('html')).toHaveAttribute('lang', locale.replaceAll('_', '-'));
      await expect(page.locator('.mobile-nav')).toContainText(word('Shorts'));
      if (word('Home')) await expect(page.locator('.mobile-nav button').first()).toContainText(word('Home'));
      if (word('Review')) await expect(page.locator('.upload-step strong').nth(3)).toHaveText(word('Review'));
      if (word('Choose who can watch your video')) await expect(page.locator('.upload-step small').nth(2)).toHaveText(word('Choose who can watch your video'));
      if (word('Only you and people you choose')) await expect(page.locator('.upload-radio-group label').nth(2)).toContainText(word('Only you and people you choose'));
      const titlePlaceholder = word('Enter video title');
      if (titlePlaceholder) {
        const input = page.getByPlaceholder(titlePlaceholder, { exact: true });
        await input.fill('Home');
        await expect(input).toHaveValue('Home');
        await expect(page.getByPlaceholder(word('Enter video description'), { exact: true })).toBeVisible();
      }
      assert.deepEqual(await layoutProblems(page), [], `${locale}: 390px upload layout`);
      assert.deepEqual(session.errors, [], `${locale}: runtime errors`);
      assert.deepEqual(session.writes, [], `${locale}: no application writes`);
      if (process.argv.includes('--record')) {
        const file = path.join(root, 'tools/i18n/reviews', `${locale}.json`);
        const review = JSON.parse(fs.readFileSync(file));
        for (const [key, value] of observed) {
          const row = review.messages[hash(key)];
          assert.equal(row?.source_hash, hash(source[key] ?? key), `${locale}: current source ${key}`);
          assert.equal(row?.translation_hash, hash(value), `${locale}: editorial value ${key}`);
          row.status = 'verified';
          row.verified_at = new Date().toISOString();
          row.contexts = [...new Set([...row.contexts, 'Upload at 390px: actual navigation, review step, private-access wording and applicable personal form prompts; user input stays literal; no overflow/runtime errors/application writes'])];
        }
        fs.writeFileSync(file, JSON.stringify(review, null, 2) + '\n');
      }
      console.log(`${locale}: ${observed.size} observed core/upload messages verified in actual 390px UI.`);
    } finally { await session.context.close(); }
  }
} finally { await browser.close(); }
