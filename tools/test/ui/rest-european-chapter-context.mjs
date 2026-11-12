import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import assert from 'node:assert/strict';
import { chromium, expect } from '@playwright/test';
import { applicationJson, layoutSession, layoutProblems } from './fixtures/readonly-layout.ts';
import { messageHash } from '../../i18n/audit.mjs';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..');
const batch = JSON.parse(fs.readFileSync(path.join(root, 'tools/i18n/batches/rest-european-chapter-validation.json')));
const actionBatch = JSON.parse(fs.readFileSync(path.join(root, 'tools/i18n/batches/rest-european-actions.json')));
const importBatch = JSON.parse(fs.readFileSync(path.join(root, 'tools/i18n/batches/rest-european-import-success.json')));
const source = applicationJson('/api/i18n/en', false).translations;
const requested = process.argv.find(arg => arg.startsWith('--locale='))?.slice(9);
const locales = applicationJson('/api/i18n', false).languages.map(row => row.code).filter(code => batch.messages['Chapter {number} name'][code] || batch.messages['Chapter {number} name'][code.split('_')[0]]);
const browser = await chromium.launch({ headless: true });
try {
  for (const locale of locales.filter(code => !requested || requested === code)) {
    const observed = new Map();
    const word = (key, values = {}) => {
      const variants = batch.messages[key] || importBatch.messages[key] || actionBatch.messages[key], value = variants?.[locale] ?? variants?.[locale.split('_')[0]];
      assert.ok(value, `${locale}: complete chapter reference ${key}`);
      observed.set(key, value);
      return value.replace(/\{(\w+)\}/g, (all, key) => values[key] ?? all);
    };
    const session = await layoutSession(browser, { language: locale, width: 390 });
    try {
      await session.context.route('**/api/video-imports/metadata', route => route.fulfill({ json: { metadata: { provider: 'youtube', source_url: route.request().postDataJSON().url, title: 'Home', description: 'Settings', duration_seconds: 120, chapters: [], is_short: false } } }));
      await session.context.route('**/api/video-imports', route => route.fulfill({ status: 201, json: { video: { id: 999999, slug: 'readonly-chapter-fixture', title: 'Home', status: 'ready', visibility: 'public' } } }));
      await session.goto('/upload');
      const page = session.page;
      await page.locator('#upload-import-url').fill('https://www.youtube.com/watch?v=chapterfixture');
      await expect(page.locator('.upload-input[maxlength="100"]')).toHaveValue('Home');
      const category = page.locator('.upload-settings-grid > div').first().getByRole('combobox');
      await category.press('ArrowDown');
      await page.getByRole('option', { disabled: false }).first().click();
      await page.locator('.upload-footer-panel button').click();
      await expect(page.locator('.upload-wizard-actions .btn.primary')).toBeVisible();
      await page.locator('.upload-wizard-actions .btn.primary').click();
      const toggle = page.locator('.upload-chapters-disable input');
      await expect(page.locator('.upload-chapters-disable strong')).toHaveText(word('Upload with chapters'));
      await toggle.check();
      await expect(page.locator('.upload-chapter-row')).toHaveCount(2);
      await expect(page.locator('.upload-chapter-row').first().locator('.sr-only').first()).toHaveText(word('Chapter {number} start time', { number: 1 }));
      await expect(page.locator('.upload-chapter-row').nth(1).getByRole('button')).toHaveAttribute('aria-label', word('Remove chapter {number}', { number: 2 }));
      const name1 = page.getByPlaceholder(word('Chapter {number} name', { number: 1 }), { exact: true });
      const name2 = page.getByPlaceholder(word('Chapter {number} name', { number: 2 }), { exact: true });
      const error = page.locator('.upload-chapter-error');
      await expect(error).toHaveText(word('Add a name for chapter {number} or turn off Upload with chapters.', { number: 1 }));
      await name1.fill('Home');
      await expect(error).toHaveText(word('Add a name for chapter {number} or turn off Upload with chapters.', { number: 2 }));
      await name2.fill('Settings');
      await expect(error).toHaveCount(0);
      const time2 = page.locator('.upload-chapter-row').nth(1).locator('input').first();
      await time2.fill('0:05');
      await expect(error).toHaveText(word('Chapter timestamps must be in order and at least {seconds} seconds apart.', { seconds: 10 }));
      await time2.fill('bad');
      await expect(error).toHaveText(word('Chapter {number} has an invalid timestamp.', { number: 2 }));
      await time2.fill('2:00');
      await expect(error).toHaveText(word('Chapter {number} starts after the video ends.', { number: 2 }));
      await time2.fill('1:00');
      await expect(error).toHaveCount(0);
      await expect(name1).toHaveValue('Home');
      await expect(name2).toHaveValue('Settings');
      assert.deepEqual(await layoutProblems(page), [], `${locale}: chapter editor at390px`);
      await page.locator('.upload-wizard-actions .btn.primary').click();
      await expect(page.locator('.upload-publish-success > p')).toHaveText(word('Your video was imported.'));
      await expect(page.locator('.publish-video-card strong')).toHaveText('Home');
      assert.deepEqual(session.errors, [], `${locale}: runtime errors`);
      assert.deepEqual(session.writes, [], `${locale}: read-only mocked import, no application writes`);
      if (process.argv.includes('--record')) {
        const file = path.join(root, 'tools/i18n/reviews', `${locale}.json`);
        const review = JSON.parse(fs.readFileSync(file));
        for (const [key, value] of observed) {
          const row = review.messages[messageHash(key)];
          assert.equal(row?.source_hash, messageHash(source[key] ?? key), `${locale}: source ${key}`);
          assert.equal(row?.translation_hash, messageHash(value), `${locale}: translation ${key}`);
          row.status = 'verified';
          row.verified_at = new Date().toISOString();
          row.contexts = [...new Set([...row.contexts, 'Actual manual chapter editor at390px: complete checkbox/name labels, missing names1/2,10s minimum gap, invalid time and chapter at exact video end; correct import-success confirmation after a stubbed submit; original title and chapter names retained; no overflow/runtime errors/application writes'])];
        }
        fs.writeFileSync(file, JSON.stringify(review, null, 2) + '\n');
      }
      console.log(`${locale}: ${observed.size} complete chapter messages verified in reachable editor/error states.`);
    } finally { await session.context.close(); }
  }
} finally { await browser.close(); }
