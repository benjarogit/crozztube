import assert from 'node:assert/strict';
import { mkdir } from 'node:fs/promises';
import { chromium, expect } from '@playwright/test';
import { applicationJson, expectContained, layoutSession } from './fixtures/readonly-layout.ts';

const catalog = applicationJson('/api/videos?type=videos&per_page=1');
const original = catalog.data?.[0];
assert(original?.slug, 'A readable fixture video is required.');
const browser = await chromium.launch({ headless: true });
const artifacts = 'tools/test/.artifacts/clip-composer';
await mkdir(artifacts, { recursive: true });
let checks = 0;

try {
  for (const scenario of [
    { language: 'en', width: 1440, source: true, dark: false },
    { language: 'de_de', width: 390, source: false, dark: true },
  ]) {
    const session = await layoutSession(browser, {
      authenticated: true,
      language: scenario.language,
      width: scenario.width,
      dark: scenario.dark,
      strictHttps: true,
      transform: (path, payload) => {
        if (path === `/api/videos/${original.slug}` && payload.video) {
          return {
            ...payload,
            video: {
              ...payload.video,
              title: 'Originaltitel bleibt unverändert',
              duration_seconds: 120,
              video_sources: scenario.source
                ? [{ src: 'https://fixture.invalid/source.mp4', type: 'video/mp4' }]
                : [{ src: 'https://fixture.invalid/source.m3u8', type: 'application/x-mpegURL' }],
            },
          };
        }
        return payload;
      },
    });

    try {
      const { page } = session;
      await session.goto(`/watch/${encodeURIComponent(original.slug)}`);
      await expect(page.locator('.watch-page')).toBeVisible();
      const trigger = page.locator('.video-detail-menu-shell > button');
      await trigger.click();
      await page.getByRole('menuitem', {
        name: scenario.language === 'en' ? 'Create a clip' : 'Clip erstellen',
      }).click();
      const dialog = page.getByRole('dialog', {
        name: scenario.language === 'en' ? 'Create a clip' : 'Clip erstellen',
      });
      await expect(dialog).toBeVisible();
      checks++;

      if (scenario.source) {
        await expect(dialog.getByLabel('Clip title')).toHaveValue('Originaltitel bleibt unverändert clip');
        await expect(dialog.getByLabel('Start time in seconds')).toHaveValue('0');
        await expect(dialog.getByLabel('End time in seconds')).toHaveValue('30');
        await expect(dialog.getByLabel('Visibility')).toHaveValue('private');
        checks += 4;
      } else {
        await expect(dialog).toContainText('Diese Quelle kann dein Browser nicht als Clip verarbeiten.');
        await expect(dialog.getByRole('button', { name: 'Zeitlink kopieren' })).toBeVisible();
        checks += 2;
      }

      await expectContained(page, `clip composer ${scenario.language} ${scenario.width}`);
      await page.screenshot({ path: `${artifacts}/${scenario.language}-${scenario.width}.png`, fullPage: true });
      await page.keyboard.press('Escape');
      await expect(dialog).toHaveCount(0);
      await expect(trigger).toBeFocused();
      assert(session.writes.every(write => write.startsWith('POST /api/source-resolver/refresh/')),
        `Unexpected write attempt: ${session.writes.join(', ')}`);
      assert(!session.writes.some(write => write.includes('clip-transfer')), 'The read-only dialog test must not start a clip transfer.');
      assert.deepEqual(session.errors, []);
      assert.deepEqual(session.failedGets, []);
      checks += 5;
    } finally {
      await session.context.close();
    }
  }
  console.log(`${checks} clip composer browser checks passed. English/German, desktop/mobile, light/dark, direct-source form, time-link fallback, focus return, no writes or media.`);
} finally {
  await browser.close();
}
