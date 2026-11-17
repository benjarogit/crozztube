/**
 * Home, library and watch design evidence.
 *
 * Renders the real application against read-only API responses and writes
 * before/after screenshots to a local folder. It never writes application data,
 * never downloads media and runs one browser at a time.
 *
 * Usage: node tools/test/ui/design-shots.mjs <label> [outputDir]
 */
import fs from 'node:fs';
import path from 'node:path';
import { chromium } from '@playwright/test';
import { layoutSession, layoutProblems } from './fixtures/readonly-layout.ts';

const label = process.argv[2] || 'current';
const outDir = process.argv[3] || `/tmp/opencode/shots/${label}`;
fs.mkdirSync(outDir, { recursive: true });

const LONG = {
  de: [
    'Ein sehr langer deutscher Videotitel über Landschaften, Jahreszeiten und die Frage, warum wir immer weiterreisen',
    'Wie ein kleines Team aus Hamburg mit begrenztem Budget eine vollständige Dokumentarserie produziert hat',
  ],
  en: [
    'A very long English video title about landscapes, seasons and the question why we keep travelling further',
    'How a small team from a coastal town produced a complete documentary series on a limited budget',
  ],
};

const cloneWithVariants = (items, language, prefix) => {
  const titles = LONG[language] || LONG.en;
  const base = items.slice(0, 3);
  const out = [];
  for (let round = 0; round < 3; round += 1) {
    for (const item of base) {
      out.push({
        ...item,
        id: `${item.id}-${prefix}${round}`,
        slug: `${item.slug}-${prefix}${round}`,
        title: round === 0 ? item.title : `${titles[round % titles.length]} (${round})`,
        views_count: round === 0 ? item.views_count : 1200 * (round + 3),
      });
    }
  }
  return out;
};

const transform = (language, dense) => (apiPath, payload) => {
  if (!dense) return payload;
  if (!apiPath.startsWith('/api/videos?')) return payload;
  if (!Array.isArray(payload.data)) return payload;
  const isShort = /type=short/.test(apiPath);
  if (isShort) return payload;
  return { ...payload, data: cloneWithVariants(payload.data, language, 'x') };
};

const shots = [];
const browser = await chromium.launch({ headless: true });
try {
  for (const language of ['en', 'de']) {
    for (const dark of [false, true]) {
      for (const dense of [false, true]) {
        const session = await layoutSession(browser, {
          authenticated: true,
          language,
          dark,
          width: 1440,
          height: 980,
          transform: transform(language, dense),
        });
        const { page } = session;
        try {
          await session.goto('/');
          await page.waitForTimeout(400);
          const problems = await layoutProblems(page);
          const name = `home-${language}-${dark ? 'dark' : 'light'}-${dense ? 'dense' : 'sparse'}.png`;
          await page.screenshot({ path: path.join(outDir, name), fullPage: false });
          shots.push({ name, problems: problems.length ? problems : 'none' });
          await page.setViewportSize({ width: 390, height: 844 });
          await page.waitForTimeout(250);
          const mobileName = `home-mobile-${language}-${dark ? 'dark' : 'light'}-${dense ? 'dense' : 'sparse'}.png`;
          const mobileProblems = await layoutProblems(page);
          await page.screenshot({ path: path.join(outDir, mobileName), fullPage: false });
          shots.push({ name: mobileName, problems: mobileProblems.length ? mobileProblems : 'none' });
          if (session.writes.length || session.errors.length) {
            shots.push({ name: `${name} runtime`, problems: [...session.writes, ...session.errors] });
          }
        } finally {
          await session.context.close();
        }
      }
    }
  }
} finally {
  await browser.close();
}

console.log(JSON.stringify({ label, outDir, shots }, null, 2));
