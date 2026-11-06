import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import assert from 'node:assert/strict';
import { chromium, expect } from '@playwright/test';
import { applicationJson, layoutSession, layoutProblems } from './fixtures/readonly-layout.ts';
import { messageHash, reviewableEntries, sourceReference } from '../../i18n/audit.mjs';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..');
const batch = JSON.parse(fs.readFileSync(path.join(root, 'tools/i18n/batches/rest-european-counts.json')));
const sourcePack = applicationJson('/api/i18n/en', false);
const source = reviewableEntries({ ...sourcePack.translations, __patterns: sourcePack.patterns, __plurals: sourcePack.plurals });
const locales = applicationJson('/api/i18n', false).languages.map(row => row.code).filter(code => batch.metadata[code] || batch.metadata[code.split('_')[0]]);
const requested = process.argv.find(arg => arg.startsWith('--locale='))?.slice(9);
const units = { user: 'users', video: 'videos', comment: 'comments', subscriber: 'subscribers', view: 'views', like: 'likes', reply: 'replies', playlist: 'playlists' };
const counts = [0, 1, 2, 3, 5, 11, 21, 101, 1.5];
const nativeAnchors = {
  pl_pl: { '1:view': '1 wyświetlenie', '2:view': '2 wyświetlenia', '5:view': '5 wyświetleń', '21:view': '21 wyświetleń' },
  ro_ro: { '0:view': '0 vizualizări', '1:view': '1 vizualizare', '2:view': '2 vizualizări', '21:view': '21 de vizualizări' },
  sl_si: { '1:view': '1 ogled', '2:view': '2 ogleda', '3:view': '3 ogledi', '5:view': '5 ogledov' },
  lv: { '0:view': '0 skatījumu', '1:view': '1 skatījums', '2:view': '2 skatījumi', '11:view': '11 skatījumu', '21:view': '21 skatījums' },
  lt_lt: { '1:view': '1 peržiūra', '2:view': '2 peržiūros', '11:view': '11 peržiūrų' },
  hu_hu: { '1:view': '1 megtekintés', '2:view': '2 megtekintés' },
};
const browser = await chromium.launch({ headless: true });
try {
  const probeContext = await browser.newContext({ locale: 'en-US' });
  const probe = await probeContext.newPage();
  const capabilities = await probe.evaluate(({locales, counts}) => Object.fromEntries(locales.map(locale => {
    const code = locale.replaceAll('_', '-');
    const numbers = Intl.NumberFormat.supportedLocalesOf([code]).length > 0;
    const plural = Intl.PluralRules.supportedLocalesOf([code]).length > 0;
    return [locale, { numbers, formatted: counts.map(count => numbers ? new Intl.NumberFormat(code).format(count) : String(count)), categories: counts.map(count => plural ? new Intl.PluralRules(code).select(count) : 'other') }];
  })), {locales, counts});
  await probeContext.close();
  for (const locale of locales.filter(code => !requested || requested === code)) {
    const metadata = batch.metadata[locale] || batch.metadata[locale.split('_')[0]];
    const observed = new Set();
    const cases = [];
    for (const [unit, label] of Object.entries(units)) {
      for (const [index, count] of counts.entries()) {
        const code = locale.replaceAll('_', '-');
        const category = capabilities[locale].categories[index];
        const keys = [`count_${unit}_${category}`, `count_${unit}`, `count_${unit}_other`];
        const key = keys.find(key => typeof metadata.__patterns[key] === 'string');
        assert.ok(key, `${locale}: complete ${unit} pattern`);
        const expected = metadata.__patterns[key].replace('{count}', capabilities[locale].formatted[index]);
        if (nativeAnchors[locale]?.[`${count}:${unit}`]) assert.equal(expected, nativeAnchors[locale][`${count}:${unit}`]);
        cases.push({ raw: `${count} ${label}`, expected });
        observed.add(key);
      }
    }
    const session = await layoutSession(browser, { language: locale, authenticated: false, width: 390 });
    try {
      await session.goto('/login');
      await session.page.evaluate(cases => {
        const fixture = document.createElement('div');
        fixture.id = 'native-count-context';
        for (const item of cases) { const line = document.createElement('div'); line.textContent = item.raw; fixture.append(line); }
        document.querySelector('.auth-card').append(fixture);
      }, cases);
      await expect(session.page.locator('#native-count-context > div')).toHaveText(cases.map(item => item.expected));
      assert.deepEqual(await layoutProblems(session.page), [], `${locale}: narrow count layout`);
      assert.deepEqual(session.errors, [], `${locale}: runtime errors`);
      assert.deepEqual(session.writes, [], `${locale}: no application writes`);
      if (process.argv.includes('--record')) {
        const file = path.join(root, 'tools/i18n/reviews', `${locale}.json`);
        const review = JSON.parse(fs.readFileSync(file));
        for (const key of observed) {
          const id = `__patterns:${key}`, row = review.messages[messageHash(id)];
          assert.equal(row?.source_hash, messageHash(sourceReference(source, id)), `${locale}: current source ${key}`);
          assert.equal(row?.translation_hash, messageHash(JSON.stringify(metadata.__patterns[key])), `${locale}: editorial template ${key}`);
          row.status = 'verified';
          row.verified_at = new Date().toISOString();
          row.contexts = [...new Set([...row.contexts, 'Actual browser localization of eight complete quantity messages, 0/1/2/3/5/11/21/101/1.5, including CLDR dual/few/many/zero and native anchor cases where applicable; 390px, no runtime errors or application writes'])];
        }
        fs.writeFileSync(file, JSON.stringify(review, null, 2) + '\n');
      }
      console.log(`${locale}: ${cases.length} complete count phrases; ${observed.size} observed templates verified; native NumberFormat ${capabilities[locale].numbers ? 'supported' : 'unavailable, literal numbers preserved'}.`);
    } finally { await session.context.close(); }
  }
} finally { await browser.close(); }
