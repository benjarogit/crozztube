import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { createHash } from 'node:crypto';
import assert from 'node:assert/strict';
import { chromium, expect } from '@playwright/test';
import { applicationJson, layoutSession } from './fixtures/readonly-layout.ts';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..');
const batch = JSON.parse(fs.readFileSync(path.join(root, 'tools/i18n/batches/rest-european-comment-actions.json')));
const mentionBatch = JSON.parse(fs.readFileSync(path.join(root, 'tools/i18n/batches/rest-european-actions.json')));
const translations = { ...batch.messages, ...mentionBatch.messages };
const source = applicationJson('/api/i18n/en', false).translations;
const requested = process.argv.find(arg => arg.startsWith('--locale='))?.slice(9);
const locales = applicationJson('/api/i18n', false).languages.map(row => row.code).filter(locale => (translations['Pin Comment'][locale] || translations['Pin Comment'][locale.split('_')[0]]) && (!requested || locale === requested));
assert.ok(locales.length, 'Unknown comment-review locale.');
const hash = value => createHash('sha256').update(String(value)).digest('hex');
const browser = await chromium.launch({ headless: true });
try {
  for (const locale of locales) {
    const session = await layoutSession(browser, { language: locale, width: 390, transform: (url, payload) => {
      if (!url.startsWith('/api/auth/studio/overview')) return payload;
      return { ...payload, comments: [false, true].map((pinned, i) => ({ id: 99101 + i, name: 'Home', body: 'Save for later @Home #Saved', pinned, time: 'Just now', can_pin: true, can_delete: true, can_mute: true, can_report: true, video: { slug: 'readonly-comment-fixture', title: 'Home', duration_seconds: 120 } })) };
    } });
    const observed = new Set();
    const translated = key => translations[key][locale] || translations[key][locale.split('_')[0]];
    try {
      await session.goto('/studio');
      const page = session.page;
      const rows = page.locator('.studio-comment-row');
      await expect(rows).toHaveCount(2);
      await expect(rows.locator('.comment-author-link')).toHaveText(['Home', 'Home']);
      await expect(rows.locator('.comment-expandable-copy')).toHaveText(['Save for later @Home #Saved', 'Save for later @Home #Saved']);
      await expect(rows.first().locator('.comment-mention').nth(0)).toHaveAttribute('title', translated('View {name} channel').replace('{name}', '@Home'));
      await expect(rows.first().locator('.comment-mention').nth(1)).toHaveAttribute('title', translated('View posts tagged {tag}').replace('{tag}', '#Saved'));
      observed.add('View {name} channel');
      observed.add('View posts tagged {tag}');
      for (const [id, action] of [[99101, 'Pin Comment'], [99102, 'Unpin Comment']]) {
        await page.locator(`#comment-${id} .comment-menu-shell > button`).click();
        const menu = page.locator('.comment-action-menu');
        await expect(menu).toBeVisible();
        for (const key of ['Reply', 'Copy Link', action, 'Mute User', 'Report Comment', 'Delete Comment']) {
          await expect(menu).toContainText(translated(key));
          observed.add(key);
        }
        const bounds = await menu.boundingBox();
        assert.ok(bounds && bounds.x >= -1 && bounds.x + bounds.width <= 391, `${locale}: menu stays within 390px`);
        await page.keyboard.press('Escape');
        await expect(menu).toHaveCount(0);
      }
      assert.deepEqual(session.errors, [], `${locale}: runtime errors`);
      assert.deepEqual(session.writes, [], `${locale}: no mutations`);
      if (process.argv.includes('--record')) {
        const file = path.join(root, 'tools/i18n/reviews', `${locale}.json`);
        const review = JSON.parse(fs.readFileSync(file));
        for (const key of observed) {
          const value = translated(key), record = review.messages[hash(key)];
          assert.equal(record?.source_hash, hash(source[key] ?? key));
          assert.equal(record?.translation_hash, hash(value));
          record.status = 'verified';
          record.verified_at = new Date().toISOString();
          record.contexts = [...new Set([...record.contexts, 'Creator Studio comments: pinned/unpinned menu, reply/copy/mute/report/delete labels at390px; original author/body/mention/tag retained, complete localized mention-action titles; menus only, no action mutations or runtime errors'])];
        }
        fs.writeFileSync(file, JSON.stringify(review, null, 2) + '\n');
      }
      console.log(`${locale}: verified ${observed.size} comment-action messages, original user content and narrow menu.`);
    } finally { await session.context.close(); }
  }
} finally { await browser.close(); }
