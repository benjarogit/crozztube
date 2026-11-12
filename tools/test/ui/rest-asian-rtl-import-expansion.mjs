import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { createHash } from 'node:crypto';
import assert from 'node:assert/strict';
import { chromium, expect } from '@playwright/test';
import { applicationJson, layoutSession, layoutProblems } from './fixtures/readonly-layout.ts';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..');
const batch = JSON.parse(fs.readFileSync(path.join(root, 'tools/i18n/batches/rest-asian-rtl-import-expansion.json')));
const source = applicationJson('/api/i18n/en', false).translations;
const requestedLocale = process.argv.find(arg => arg.startsWith('--locale='))?.slice(9);
const locales = applicationJson('/api/i18n', false).languages.map(row => row.code)
  .filter(locale => batch.messages['Fetch details'][locale] || batch.messages['Fetch details'][locale.split('_')[0]])
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
      await expect(page.locator('.upload-import-box h2')).toHaveText(word('Import video from URL'));
      await expect(page.locator('.upload-import-heading p')).toHaveText(word('Paste a public video URL to detect its provider and fetch the details automatically.'));
      const input = page.locator('.upload-import-url-row input');
      await input.fill('https://www.youtube.com/watch?v=fixture1234');
      const button = page.locator('.upload-fetch-button');
      await expect(button).toHaveAttribute('aria-busy', 'true');
      await expect(button).toContainText(word('Fetching...'));
      await expect(button.locator('.upload-fetch-spinner')).toBeVisible();
      release();
      const summary = page.locator('.upload-import-summary');
      await expect(summary).toHaveAttribute('aria-busy', 'false');
      await expect(button).toContainText(word('Fetch details'));
      for (const key of ['Provider detected automatically', 'Imports video details, available subtitles and chapters from YouTube.', 'Subtitles', 'Chapters', 'Available subtitles and a transcript will be imported.', 'Review or disable them before publishing.']) {
        await expect(summary).toContainText(word(key));
      }
      for (const key of ['Subtitle languages found: {count}', 'Chapters found: {count}']) await expect(summary).toContainText(word(key).replace('{count}', '2'));
      await expect(page.locator('input[value="Saved"]')).toBeVisible();
      await expect(page.locator('textarea').first()).toHaveValue('Home');
      await input.fill('https://www.youtube.com/watch?v=fixture5678');
      await expect(button).toHaveAttribute('aria-busy', 'true');
      release();
      await expect(summary).toHaveAttribute('aria-busy', 'false');
      await expect(summary.locator('dd > strong')).toHaveText([word('None found'), word('None found')]);
      for (const key of ['No available subtitle tracks found.', 'You can add chapters manually in Review.']) await expect(summary).toContainText(word(key));
      assert.deepEqual(await layoutProblems(page), [], `${locale}: narrow import layout`);
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
          row.contexts = [...new Set([...row.contexts, 'URL import at 390px: loading/spinner, automatic YouTube detection, two subtitles/chapters, empty results, original provider metadata retained; isolated read-only browser'])];
        }
        fs.writeFileSync(file, JSON.stringify(review, null, 2) + '\n');
      }
      console.log(`${locale}: verified ${observed.size} import messages in loading, populated and empty states.`);
    } finally { release(); await session.context.close(); }
  }
} finally { await browser.close(); }
