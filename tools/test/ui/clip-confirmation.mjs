import { chromium, expect } from '@playwright/test';
import { mkdir } from 'node:fs/promises';
import { layoutSession, expectContained } from './fixtures/readonly-layout.ts';
const browser = await chromium.launch({ headless: true });
const artifacts = 'tools/test/.artifacts/clip-confirmation';
await mkdir(artifacts, { recursive: true });
let checks = 0;
try {
  for (const [language, dark] of [['en', false], ['de_de', true]]) {
    const session = await layoutSession(browser, { language, dark, strictHttps: true, width: 1440 });
    try {
      const { page, context } = session;
      const id = 'cb362d31-cd5a-4c58-849a-830e7530ece0';
      const title = 'Home – Mein wirklich langer, unveränderter Originaltitel für einen Clip aus der Community';
      let item = { id, title, state: 'awaiting_confirmation', can_watch: true, cancel_requested: false, available_at: null };
      let playback = 0, cancels = 0, polls = 0, fail = false;
      await context.route('**/api/clip-transfers**', async route => {
        const path = new URL(route.request().url()).pathname;
        if (path.endsWith('/playback')) { playback++; return route.fulfill({ json: { embed_url: 'https://odysee.com/$/embed/fixture:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa' } }); }
        if (path.endsWith('/cancel')) { cancels++; item = { ...item, cancel_requested: true }; return route.fulfill({ json: { transfer: item } }); }
        polls++; return route.fulfill({ status: fail ? 503 : 200, json: { transfers: [item] } });
      });
      await context.route('https://odysee.com/**', route => route.fulfill({ contentType: 'text/html', body: '<title>Controlled Odysee embed fixture</title><p>Clip player</p>' }));
      await page.clock.install();
      await session.goto(`/clip-uploads?clip=${id}`);
      const panel = page.locator('.clip-uploads-page');
      await expect(panel).toBeVisible();
      await expect(panel.locator('h2')).toHaveText(title);
      await expect(panel).toContainText(language === 'en' ? 'Waiting for Odysee confirmation' : 'Wartet auf die Bestätigung von Odysee');
      await expect(panel).toContainText(language === 'en' ? 'we will notify you' : 'Wir benachrichtigen dich');
      await expect(panel.locator('iframe')).toHaveCount(0);
      await expect(panel.locator('.btn.primary')).toHaveCount(0);
      expect(playback).toBe(0); checks += 7;
      for (const width of [1440, 900, 390, 320]) {
        await page.setViewportSize({ width, height: 900 });
        await expectContained(page, `clip wait ${language} ${width}`); checks++;
      }
      await page.screenshot({ path: `${artifacts}/${language}-waiting-320.png`, fullPage: true });
      fail = true; await page.clock.fastForward(16000);
      await expect(panel.getByRole('alert')).toBeVisible(); checks++;
      fail = false; await panel.getByRole('button', { name: language === 'en' ? 'Try again' : 'Erneut versuchen', exact: true }).click();
      await expect(panel.getByRole('alert')).toHaveCount(0); checks++;
      item = { ...item, state: 'queued', position: 3 };
      await page.clock.fastForward(16000);
      await expect(panel).toContainText(language === 'en' ? 'Your position in the queue: 3.' : 'Dein Platz in der Warteschlange: 3.'); checks++;
      await panel.getByRole('button', { name: language === 'en' ? 'Cancel clip upload' : 'Clip-Upload abbrechen', exact: true }).click();
      await expect(panel).toContainText(language === 'en' ? 'Canceling your clip upload' : 'Dein Clip-Upload wird abgebrochen');
      expect(cancels).toBe(1); await expect(panel.locator('.clip-transfer-row')).toBeFocused(); checks += 3;
      item = { ...item, state: 'ready', cancel_requested: false, can_watch: true, available_at: '2026-11-07T20:00:00Z' };
      await page.clock.fastForward(16000);
      const watch = panel.getByRole('button', { name: language === 'en' ? 'Watch clip' : 'Clip ansehen', exact: true });
      await expect(watch).toBeVisible(); expect(playback).toBe(0); checks += 2;
      await watch.focus(); await page.keyboard.press('Enter');
      await expect(panel.locator('iframe')).toHaveCount(1); expect(playback).toBe(1); checks += 2;
      await panel.locator('iframe').evaluate(node => { node.dataset.preserved = 'true'; });
      await page.clock.fastForward(16000);
      for (const width of [320, 900, 1440]) {
        await page.setViewportSize({ width, height: 900 });
        await expect(panel.locator('iframe')).toHaveAttribute('data-preserved', 'true');
        await expectContained(page, `clip ready ${language} ${width}`); checks += 2;
      }
      await page.screenshot({ path: `${artifacts}/${language}-ready-1440.png`, fullPage: true });
      await session.goto('/upload');
      const draft = page.locator('.upload-import-url-row input');
      await expect(draft).toBeVisible(); await draft.fill('Mein unveröffentlichter Entwurf');
      for (const width of [390, 1440]) {
        await page.setViewportSize({ width, height: 900 }); await page.clock.fastForward(16000);
        await expect(draft).toHaveValue('Mein unveröffentlichter Entwurf'); checks++;
      }
      expect(polls).toBeGreaterThan(4);
      expect(session.writes).toEqual([]); expect(session.errors).toEqual([]); expect(session.failedGets).toEqual([]); checks += 4;
    } finally { await session.context.close(); }
  }
  console.log(`${checks} clip confirmation browser checks passed. English/German, narrow/tablet/desktop, themes, keyboard, pending/errors/cancel/ready, stable player. No real writes or media.`);
} finally { await browser.close(); }
