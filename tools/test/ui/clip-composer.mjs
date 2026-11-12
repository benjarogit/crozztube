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
    { language: 'de_de', width: 390, source: true, dark: true },
    { language: 'de_de', width: 412, source: false, dark: false },
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
        const labels = scenario.language === 'en'
          ? { title: 'Clip title', start: 'Start time in seconds', duration: 'Duration', visibility: 'Visibility', create: 'Create a clip' }
          : { title: 'Clip-Titel', start: 'Startzeit in Sekunden', duration: 'Dauer', visibility: 'Sichtbarkeit', create: 'Clip erstellen' };
        await expect(dialog.getByLabel(labels.title)).toHaveValue('Originaltitel bleibt unverändert clip');
        await expect(dialog.getByLabel(labels.start)).toHaveValue('0');
        await expect(dialog.getByRole('slider', { name: labels.duration })).toHaveValue('30');
        await expect(dialog.getByLabel(labels.visibility)).toHaveValue('private');
        await dialog.getByRole('button', { name: '60 s' }).click();
        await expect(dialog.getByRole('slider', { name: labels.duration })).toHaveValue('60');
        await dialog.getByLabel(labels.start).fill('60');
        await expect(dialog).toContainText('1:00 – 2:00');
        await expect(dialog.getByRole('button', { name: labels.create })).toBeVisible();
        const appearance = await dialog.evaluate(node => {
          const normalize = value => {
            const sample = document.createElement('span');
            sample.style.backgroundColor = value;
            document.body.append(sample);
            const color = getComputedStyle(sample).backgroundColor;
            sample.remove();
            return color;
          };
          const root = getComputedStyle(document.documentElement);
          const close = node.querySelector('.clip-composer-close');
          const title = node.querySelector('input:not([type="range"])');
          const timing = node.querySelector('.clip-composer-timing');
          const selected = node.querySelector('.clip-composer-duration-presets [aria-pressed="true"]');
          return {
            panel2: normalize(root.getPropertyValue('--panel-2')),
            primarySoft: normalize(root.getPropertyValue('--primary-soft')),
            close: getComputedStyle(close).backgroundColor,
            closeRadius: getComputedStyle(close).borderRadius,
            title: getComputedStyle(title).backgroundColor,
            titleRadius: getComputedStyle(title).borderRadius,
            timing: getComputedStyle(timing).backgroundColor,
            selected: getComputedStyle(selected).backgroundColor,
          };
        });
        assert.equal(appearance.close, appearance.panel2);
        assert.equal(appearance.title, appearance.panel2);
        assert.equal(appearance.timing, appearance.panel2);
        assert.equal(appearance.selected, appearance.primarySoft);
        assert.equal(appearance.closeRadius, appearance.titleRadius);
        checks += 12;
      } else {
        await expect(dialog).toContainText('Diese Quelle kann dein Browser nicht als Clip verarbeiten.');
        await expect(dialog.getByRole('button', { name: 'Zeitlink kopieren' })).toBeVisible();
        checks += 2;
      }

      await expectContained(page, `clip composer ${scenario.language} ${scenario.width}`);
      await page.screenshot({ path: `${artifacts}/${scenario.language}-${scenario.width}-${scenario.source ? 'form' : 'fallback'}.png`, fullPage: true });
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
  console.log(`${checks} clip composer browser checks passed. Timeline, duration presets, English/German, desktop/mobile, light/dark, time-link fallback, focus return, no writes or media.`);
} finally {
  await browser.close();
}
