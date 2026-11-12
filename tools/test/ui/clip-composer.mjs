import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
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

const mediaFile = `/tmp/crozztube-clip-preview-${process.pid}.mp4`;
let media;
try {
  execFileSync('docker', ['exec', 'crozztube-web', 'ffmpeg', '-hide_banner', '-loglevel', 'error', '-f', 'lavfi', '-i', 'color=c=#1d4ed8:s=640x360:r=12', '-f', 'lavfi', '-i', 'sine=frequency=440:sample_rate=44100', '-t', '65', '-c:v', 'libx264', '-pix_fmt', 'yuv420p', '-c:a', 'aac', '-shortest', '-movflags', '+faststart', '-y', mediaFile]);
  media = execFileSync('docker', ['exec', 'crozztube-web', 'cat', mediaFile]);
} finally {
  execFileSync('docker', ['exec', 'crozztube-web', 'rm', '-f', mediaFile]);
}

const fulfillMedia = async route => {
  const range = route.request().headers().range?.match(/bytes=(\d+)-(\d*)/);
  const start = range ? Number(range[1]) : 0;
  const end = range?.[2] ? Math.min(Number(range[2]), media.length - 1) : media.length - 1;
  await route.fulfill({
    status: range ? 206 : 200,
    contentType: 'video/mp4',
    headers: {
      'Accept-Ranges': 'bytes',
      ...(range ? { 'Content-Range': `bytes ${start}-${end}/${media.length}` } : {}),
    },
    body: media.subarray(start, end + 1),
  });
};

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
              duration_seconds: 65,
              video_url: scenario.source ? '/fixtures/clip-preview.mp4' : null,
              video_sources: scenario.source
                ? [{ src: '/fixtures/clip-preview.mp4', type: 'video/mp4' }]
                : [{ src: 'https://fixture.invalid/source.m3u8', type: 'application/x-mpegURL' }],
            },
          };
        }
        return payload;
      },
    });

    try {
      const { page, context } = session;
      const mediaRequests = [];
      if (scenario.source) {
        await context.route('**/fixtures/clip-preview.mp4', fulfillMedia);
      }
      page.on('request', request => {
        if (request.resourceType() === 'media' || /\.(?:mp4|m3u8)(?:$|\?)/i.test(request.url())) {
          mediaRequests.push(request.url());
        }
      });
      const requestedStart = scenario.width === 1440 ? 5 : 0;
      await session.goto(`/watch/${encodeURIComponent(original.slug)}${requestedStart ? `?t=${requestedStart}` : ''}`);
      await expect(page.locator('.watch-page')).toBeVisible();
      if (scenario.source) {
        const mediaElement = page.locator('.watch-vidstack-player video');
        await expect(mediaElement).toBeVisible();
        await expect.poll(() => mediaElement.evaluate(video => video.readyState)).toBeGreaterThan(1);
      }
      const trigger = page.getByRole('button', {
        name: scenario.language === 'en' ? 'Create a clip' : 'Clip erstellen',
        exact: true,
      });
      const writesBeforeDialog = session.writes.length;
      await trigger.click();
      const dialog = page.getByRole('dialog', {
        name: scenario.language === 'en' ? 'Create a clip' : 'Clip erstellen',
      });
      await expect(dialog).toBeVisible();
      checks++;

      if (scenario.source) {
        const labels = scenario.language === 'en'
          ? { title: 'Clip title', start: 'Start time in seconds', end: 'End time in seconds', visibility: 'Visibility', create: 'Create a clip', play: 'Play', pause: 'Pause' }
          : { title: 'Clip-Titel', start: 'Startzeit in Sekunden', end: 'Endzeit in Sekunden', visibility: 'Sichtbarkeit', create: 'Clip erstellen', play: 'Abspielen', pause: 'Pause' };
        await expect(dialog.getByLabel(labels.title)).toHaveValue('Originaltitel bleibt unverändert clip');
        await expect(dialog.getByLabel(labels.start)).toHaveValue(String(requestedStart));
        await expect(dialog.getByLabel(labels.end)).toHaveValue(String(requestedStart + 30));
        await expect(dialog.getByLabel(labels.visibility)).toHaveValue('private');
        await expect(dialog.locator('.clip-composer-preview canvas')).toBeVisible();
        await expect(dialog.locator('.clip-composer-preview-controls')).toHaveCount(0);
        await expect(dialog.locator('.clip-composer-overview')).toHaveCount(0);
        await expect(dialog.locator('.clip-composer-timing')).toHaveCount(0);
        await expect(dialog.locator('.clip-composer-preview-frame .clip-composer-preview-trim')).toBeVisible();
        await expect.poll(async () => dialog.locator('.clip-composer-preview canvas').evaluate(canvas => {
          if (!canvas.width || !canvas.height) return false;
          const context2d = canvas.getContext('2d');
          const sample = context2d.getImageData(Math.floor(canvas.width / 2), Math.floor(canvas.height / 2), 1, 1).data;
          return sample[0] + sample[1] + sample[2] > 0;
        })).toBe(true);
        checks += 5;
        const play = dialog.getByRole('button', { name: labels.play, exact: true });
        await play.click();
        await expect(dialog.getByRole('button', { name: labels.pause, exact: true })).toBeVisible();
        await dialog.getByRole('button', { name: labels.pause, exact: true }).click();
        await expect(play).toBeVisible();
        checks += 2;
        await dialog.getByRole('button', { name: '60 s' }).click();
        await expect(dialog.getByLabel(labels.end)).toHaveValue(String(requestedStart + 60));
        await dialog.getByLabel(labels.start).fill('5');
        await dialog.getByLabel(labels.start).dispatchEvent('pointerup');
        await expect(dialog).toContainText('0:05 – 1:05');
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
          const selected = node.querySelector('.clip-composer-duration-presets [aria-pressed="true"]');
          return {
            panel2: normalize(root.getPropertyValue('--panel-2')),
            primarySoft: normalize(root.getPropertyValue('--primary-soft')),
            close: getComputedStyle(close).backgroundColor,
            closeRadius: getComputedStyle(close).borderRadius,
            title: getComputedStyle(title).backgroundColor,
            titleRadius: getComputedStyle(title).borderRadius,
            selected: getComputedStyle(selected).backgroundColor,
          };
        });
        assert.equal(appearance.close, appearance.panel2);
        assert.equal(appearance.title, appearance.panel2);
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
      const previewWrites = session.writes.slice(writesBeforeDialog);
      assert(previewWrites.every(write => write.startsWith('POST /api/source-resolver/refresh/')),
        `Unexpected preview write attempt: ${previewWrites.join(', ')}`);
      assert(!previewWrites.some(write => write.includes('clip-transfer')), 'The read-only dialog test must not start a clip transfer.');
      assert(!mediaRequests.some(url => /clip-transfer|upload/i.test(url)), 'Preview must not upload media.');
      assert.deepEqual(session.errors, []);
      assert.deepEqual(session.failedGets, []);
      checks += 5;
    } finally {
      await session.context.close();
    }
  }
  console.log(`${checks} clip composer browser checks passed. Preview canvas, play/pause, timeline, English/German, desktop/mobile, light/dark, time-link fallback, focus return, no writes or uploads.`);
} finally {
  await browser.close();
}
