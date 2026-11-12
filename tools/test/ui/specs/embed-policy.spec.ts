import { test, expect, firefox } from '@playwright/test';
import { applicationJson, layoutSession } from '../fixtures/readonly-layout';

test.use({ trace: 'off', video: 'off' });

for (const engine of ['chromium', 'firefox']) {
  for (const mode of ['valid', 'file-iframe', 'file-link']) {
    test(`${engine}: ${mode} mirror uses supported permissions and never navigates to local files`, async ({ browser }) => {
      const isolated = engine === 'firefox' ? await firefox.launch({ headless: true }) : null;
      const slug = 'trumps-executive-order-13848-was-the-2026-midterm-election-a-trap-for-the-deep-state';
      const original = applicationJson(`/api/videos/${slug}`, false);
      const mirrorUrl = 'https://www.bitchute.com/embed/WtW1GZBy4Tme/';
      const session = await layoutSession(isolated || browser, { authenticated: false, transform: (path, payload) => {
        if (path === `/api/videos/${slug}`) return { ...original, player_ads: null, video: { ...original.video,
          video_url: null, video_sources: [], embed_url: mode === 'valid' ? null : 'file:///tmp/video.html', playback_pending: true,
        } };
        if (path.startsWith('/api/source-resolver/status/slug/')) return { source: { ...payload.source,
          state: 'pending', fresh: false, refreshing: false, has_direct: false, auto_refresh_allowed: false,
          mirror: { mode: mode === 'file-link' ? 'link' : 'iframe', provider: 'bitchute', label: 'BitChute',
            embed_url: mode === 'valid' ? mirrorUrl : 'file:///tmp/video.html', external_url: 'file:///tmp/video.html',
          },
        } };
        return payload;
      } });
      const consoleMessages: string[] = [], localRequests: string[] = [];
      session.page.on('console', message => consoleMessages.push(message.text()));
      session.page.on('request', request => { if (request.url().startsWith('file:')) localRequests.push(request.url()); });
      await session.context.route('https://www.bitchute.com/embed/**', route => route.fulfill({ contentType: 'text/html', body: '<title>Provider mirror fixture</title>' }));
      try {
        await session.goto(`/watch/${slug}`);
        const { page } = session;
        if (mode === 'valid') {
          await page.locator('.crz-mirror-toggle').click();
          const frame = page.locator('.crz-mirror-layer iframe');
          await expect(frame).toHaveAttribute('src', mirrorUrl);
          await expect(frame).toHaveAttribute('allowfullscreen', '');
          const policy = await page.evaluate(() => {
            const host = document as any;
            const policy = host.permissionsPolicy || host.featurePolicy;
            return policy?.features?.() || [];
          });
          const allowed = ((await frame.getAttribute('allow')) || '').split(';').map(value => value.trim()).filter(Boolean);
          for (const feature of allowed) expect(policy).toContain(feature);
          expect(allowed).not.toContain('accelerometer');
          expect(allowed).not.toContain('gyroscope');
          if (engine === 'chromium') expect(allowed).toContain('autoplay');
          if (engine === 'firefox') expect(allowed).toEqual([]);
          await page.locator('.crz-mirror-toggle').click();
          await expect(page.locator('.crz-mirror-layer')).toHaveCount(0);
        } else {
          await expect(page.locator('.watch-player-stage')).toBeVisible();
          await expect(page.locator('.crz-mirror-slot')).toHaveCount(0);
          await expect(page.locator('iframe[src^="file:"]')).toHaveCount(0);
        }
        expect(consoleMessages.filter(message => /unsupported feature|unrecognized feature|content-lite|No Listener|file:\/\//i.test(message))).toEqual([]);
        expect(localRequests).toEqual([]);
        expect(session.writes).toEqual([]);
        expect(session.errors).toEqual([]);
      } finally {
        await session.context.close().catch(() => {});
        await isolated?.close();
      }
    });
  }
}
