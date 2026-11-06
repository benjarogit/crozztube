import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { createHash } from 'node:crypto';
import assert from 'node:assert/strict';
import { chromium, expect } from '@playwright/test';
import { applicationJson, layoutSession, layoutProblems } from './fixtures/readonly-layout.ts';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..');
const batch = JSON.parse(fs.readFileSync(path.join(root, 'tools/i18n/batches/rest-asian-rtl-primary-ui-labels.json')));
const source = applicationJson('/api/i18n/en', false).translations;
const hash = value => createHash('sha256').update(String(value)).digest('hex');
const browser = await chromium.launch({ headless: true });
try {
  for (const locale of Object.keys(batch.messages.Home)) {
    const session = await layoutSession(browser, { language: locale, authenticated: false, width: 390 });
    const observed = new Set();
    try {
      const page = session.page;
      await session.goto('/');
      await expect(page.locator('.mobile-nav button').first()).toContainText(batch.messages.Home[locale]);
      observed.add('Home');
      assert.deepEqual(await layoutProblems(page), [], `${locale}: home at390px`);
      await session.goto('/login');
      if (batch.messages['Log in'][locale]) {
        await expect(page.locator('.auth-form button[type="submit"], .auth-form > button.btn.primary')).toHaveText(batch.messages['Log in'][locale]);
        observed.add('Log in');
      }
      if (batch.messages['Create an account'][locale]) {
        await expect(page.locator('.auth-switch-copy button')).toHaveText(batch.messages['Create an account'][locale]);
        observed.add('Create an account');
      }
      const input = page.locator('.auth-card input').first();
      await input.fill('saved@example.test');
      await expect(input).toHaveValue('saved@example.test');
      assert.deepEqual(await layoutProblems(page), [], `${locale}: login at390px`);
      assert.deepEqual(session.errors, [], `${locale}: runtime errors`);
      assert.deepEqual(session.writes, [], `${locale}: no mutations`);
      if (process.argv.includes('--record')) {
        const file = path.join(root, 'tools/i18n/reviews', `${locale}.json`);
        const review = JSON.parse(fs.readFileSync(file));
        for (const key of observed) {
          const value = batch.messages[key][locale], row = review.messages[hash(key)];
          assert.equal(row?.source_hash, hash(source[key] ?? key));
          assert.equal(row?.translation_hash, hash(value));
          row.status = 'verified';
          row.verified_at = new Date().toISOString();
          row.contexts = [...new Set([...row.contexts, 'Source-assisted short labels in actual mobile Home navigation/login/register-link at390px; editable login input retained; no overflow/runtime errors/application writes. Only observed labels, no full-locale acceptance.'])];
        }
        fs.writeFileSync(file, JSON.stringify(review, null, 2) + '\n');
      }
      console.log(`${locale}: verified ${observed.size} source-assisted short labels in actual narrow home/login.`);
    } finally { await session.context.close(); }
  }
} finally { await browser.close(); }
