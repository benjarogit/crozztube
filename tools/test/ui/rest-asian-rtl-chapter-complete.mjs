import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { createHash } from 'node:crypto';
import assert from 'node:assert/strict';
import { chromium, expect } from '@playwright/test';
import { applicationJson, layoutSession, layoutProblems } from './fixtures/readonly-layout.ts';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..');
const batch = {messages:{}};
for(const name of ['rest-asian-rtl-chapter-validation.json','rest-asian-rtl-chapter-expansion.json','rest-asian-rtl-chapter-helpers.json']){
const b=JSON.parse(fs.readFileSync(path.join(root,'tools/i18n/batches',name)));for(const[k,v]of Object.entries(b.messages))batch.messages[k]={...batch.messages[k],...v};}
const source = applicationJson('/api/i18n/en', false).translations;
const requestedLocale = process.argv.find(arg => arg.startsWith('--locale='))?.slice(9);
const locales = applicationJson('/api/i18n', false).languages.map(row => row.code)
  .filter(locale => batch.messages['Upload with chapters'][locale] || batch.messages['Upload with chapters'][locale.split('_')[0]])
  .filter(locale => !requestedLocale || locale === requestedLocale);
const hash = text => createHash('sha256').update(String(text)).digest('hex');
assert.ok(locales.length, 'The requested locale must have an editorial batch to verify.');
const browser = await chromium.launch({ headless: true });
try {
  for (const locale of locales) {
    const observed = new Map();
    const word = key => {
      const values = batch.messages[key];
      const value = values?.[locale] ?? values?.[locale.split('_')[0]];
      assert.ok(value, `${locale}: editorial reference for ${key}`);
      observed.set(key, value);
      return value;
    };
    const session = await layoutSession(browser, { language: locale, width: 390 });
    let release = () => {}, calls = 0;
    try {
      await session.context.route('**/api/video-imports/metadata', async route => {
        await new Promise(resolve => { release = resolve; });
        const body = route.request().postDataJSON();
        calls++;
        await route.fulfill({ json: { metadata: { provider: body.provider, source_url: body.url,
          title: 'Saved', description: 'Home', duration_seconds: 120,
          captions: calls === 1 ? [{ language: 'de' }, { language: 'en' }] : [],
          chapters: calls === 1 ? [{ start_time: 0, title: 'Home' }, { start_time: 60, title: 'Settings' }] : [], chapters_source: 'provider',
        } } });
      });
      await session.goto('/upload');
      const page = session.page;
      const input = page.locator('.upload-import-url-row input');
      await input.fill('https://www.youtube.com/watch?v=fixture1234');
      await expect(page.locator('.upload-fetch-button')).toHaveAttribute('aria-busy', 'true');
      release();
      await expect(page.locator('.upload-import-summary')).toHaveAttribute('aria-busy', 'false');
      await page.locator('.upload-settings-grid .adaptive-select__control').first().click();
      await page.locator('[role="option"]:not([aria-disabled="true"])').first().click();
      await page.locator('.upload-footer-actions .publish-button').click();
      await page.locator('.upload-wizard-actions .btn.primary').click();
      await expect(page.locator('.upload-chapters-disable')).toContainText(word('Upload with chapters'));
      const rows = page.locator('.upload-chapter-row');
      await expect(rows).toHaveCount(2);
      await expect(rows.first().locator('input').nth(1)).toHaveAttribute('placeholder', word('Chapter {number} name').replace('{number}', '1'));
      await expect(rows.first().locator('input').nth(1)).toHaveValue('Home');
      const error = page.locator('.upload-chapter-error');
      await rows.first().locator('input').nth(1).fill('');
      await expect(error).toHaveText(word('Add a name for chapter {number} or turn off Upload with chapters.').replace('{number}', '1'));
      await rows.first().locator('input').nth(1).fill('Home');
      await rows.nth(1).locator('input').first().fill('not-time');
      await expect(error).toHaveText(word('Chapter {number} has an invalid timestamp.').replace('{number}', '2'));
      await rows.nth(1).locator('input').first().fill('0:00');
      await expect(error).toHaveText(word('Chapter timestamps must be in order and at least {seconds} seconds apart.').replace('{seconds}', '1'));
      await rows.nth(1).locator('input').first().fill('3:00');
      await expect(error).toHaveText(word('Chapter {number} starts after the video ends.').replace('{number}', '2'));
      await rows.nth(1).locator('input').first().fill('1:00');
      await expect(error).toHaveCount(0);
      await expect(page.locator('.upload-chapters-card h2')).toHaveText(word('Video chapters'));
      await expect(page.locator('.upload-chapters-disable')).toContainText(word('Enable named segments in the video timeline.'));
      const add=page.locator('.upload-chapter-actions button');
      await expect(add).toContainText(word('Add segment'));
      await add.click();await expect(rows).toHaveCount(3);
      await rows.nth(2).locator('input').nth(1).fill('Saved');
      await expect(rows.nth(2).locator('button')).toHaveAttribute('title',word('Remove segment'));
      await rows.nth(2).locator('button').click();await expect(rows).toHaveCount(2);
      await page.locator('input[type="file"][accept*=".cue"]').setInputFiles({name:'Saved.txt',mimeType:'text/plain',buffer:Buffer.from('0:00 Home\n1:00 Saved')});
      await expect(page.locator('.upload-chapters-card .upload-panel-head small')).toHaveText('Saved.txt');
      await expect(rows.first().locator('input').nth(1)).toHaveValue('Home');
      await expect(rows.nth(1).locator('input').nth(1)).toHaveValue('Saved');
      assert.deepEqual(await layoutProblems(page), [], `${locale}: narrow chapter editor`);
      assert.deepEqual(session.errors, [], `${locale}: runtime errors`);
      assert.deepEqual(session.writes, [], `${locale}: no application writes`);
      if (process.argv.includes('--record')) {
        const file = path.join(root, 'tools/i18n/reviews', `${locale}.json`);
        const review = JSON.parse(fs.readFileSync(file));
        for (const [key, value] of observed) {
          const row = review.messages[hash(key)];
          assert.equal(row?.source_hash, hash(source[key] ?? key));
          assert.equal(row?.translation_hash, hash(value));
          row.status = 'verified';
          row.verified_at = new Date().toISOString();
          row.contexts = [...new Set([...row.contexts, 'Actual chapter editor at390px with RTL: imported chapters active; names/time/errors and valid recovery; add/remove chapter actions; real TXT file read with literal Saved.txt/Home/Saved retained; native helper text; no publishing/runtime errors/overflow/writes'])];
        }
        fs.writeFileSync(file, JSON.stringify(review, null, 2) + '\n');
      }
      console.log(`${locale}: verified ${observed.size} chapter messages in actual editor/error/recovery states.`);
    } finally { release(); await session.context.close(); }
  }
} finally { await browser.close(); }
