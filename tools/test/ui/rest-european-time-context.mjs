import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import assert from 'node:assert/strict';
import { chromium, expect } from '@playwright/test';
import { applicationJson, layoutSession, layoutProblems } from './fixtures/readonly-layout.ts';
import { messageHash, reviewableEntries, sourceReference } from '../../i18n/audit.mjs';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..');
const batch = JSON.parse(fs.readFileSync(path.join(root, 'tools/i18n/batches/rest-european-time-patterns.json')));
const sourcePack = applicationJson('/api/i18n/en', false);
const source = reviewableEntries({ ...sourcePack.translations, __patterns: sourcePack.patterns, __plurals: sourcePack.plurals });
const locales = applicationJson('/api/i18n', false).languages.map(row => row.code).filter(code => batch.metadata[code] || batch.metadata[code.split('_')[0]]);
const requested = process.argv.find(arg => arg.startsWith('--locale='))?.slice(9);
const units = { year: 'years', month: 'months', week: 'weeks', day: 'days', hour: 'hours', minute: 'minutes', second: 'seconds' };
const kinds = ['duration', 'ago'];
const counts = [0, 1, 2, 3, 6, 11, 12, 21, 1.5];
const nativeAnchors = {
  is_is: { '1:ago:minute': 'fyrir 1 mínútu', '2:ago:minute': 'fyrir 2 mínútum', '1:duration:minute': '1 mínúta', '2:duration:minute': '2 mínútur', '21:ago:year': 'fyrir 21 ári', '11:ago:year': 'fyrir 11 árum' },
  eu: { '2:ago:day': 'duela 2 egun', '2:duration:day': '2 egun' },
  gd: { '2:ago:month': '2 mhìos air ais', '3:duration:month': '3 mìosan' },
  cy: { '2:duration:year': '2 flynedd', '3:duration:year': '3 blynedd', '6:duration:year': '6 blynedd' },
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
    for (const [unit, label] of Object.entries(units)) for (const kind of kinds) {
      for (const [index, count] of counts.entries()) {
        const code = locale.replaceAll('_', '-');
        const category = capabilities[locale].categories[index];
        const keys = [`${kind}_${unit}_${category}`, `${kind}_${unit}`, `${kind}_${unit}_other`];
        const key = keys.find(key => typeof metadata.__patterns[key] === 'string');
        assert.ok(key, `${locale}: complete ${unit} pattern`);
        const expected = metadata.__patterns[key].replace('{count}', capabilities[locale].formatted[index]);
        if (nativeAnchors[locale]?.[`${count}:${kind}:${unit}`]) assert.equal(expected, nativeAnchors[locale][`${count}:${kind}:${unit}`]);
        cases.push({ raw: `${count} ${label}${kind === 'ago' ? ' ago' : ''}`, expected });
        observed.add(key);
      }
    }
    const session = await layoutSession(browser, { language: locale, authenticated: false, width: 390 });
    try {
      await session.goto('/login');
      await session.page.evaluate(cases => {
        const fixture = document.createElement('div');
        fixture.id = 'native-time-context';
        for (const item of cases) { const line = document.createElement('div'); line.textContent = item.raw; fixture.append(line); }
        document.querySelector('.auth-card').append(fixture);
      }, cases);
      await expect(session.page.locator('#native-time-context > div')).toHaveText(cases.map(item => item.expected));
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
          row.contexts = [...new Set([...row.contexts, 'Actual browser localization of eight complete duration and relative-age messages,0/1/2/3/6/11/12/21/1.5, including Icelandic dative versus standalone case and Welsh/Gaelic mutation anchor cases; 390px, no runtime errors or application writes'])];
        }
        fs.writeFileSync(file, JSON.stringify(review, null, 2) + '\n');
      }
      console.log(`${locale}: ${cases.length} complete time phrases; ${observed.size} observed templates verified; native NumberFormat ${capabilities[locale].numbers ? 'supported' : 'unavailable, literal numbers preserved'}.`);
    } finally { await session.context.close(); }
  }
} finally { await browser.close(); }
