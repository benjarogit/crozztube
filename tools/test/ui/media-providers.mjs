import fs from 'node:fs';
import path from 'node:path';
import assert from 'node:assert/strict';
import { chromium, expect } from '@playwright/test';
import { layoutSession } from './fixtures/readonly-layout.ts';

// Real compiled UI and reference catalogs; provider mutations are isolated
// browser fixtures. Never sign in to external accounts or save real settings.
const output = path.resolve('tools/test/.artifacts/media-providers');
fs.mkdirSync(output, { recursive: true });
const browser = await chromium.launch({ headless: true });
let checks = 0;
const check = (condition, message) => { assert(condition, message); checks++; };
try {
  for (const language of ['en', 'de_de']) {
    const session = await layoutSession(browser, { language, width: 1440, strictHttps: true });
    const { page, context } = session;
    page.setDefaultTimeout(12_000);
    const providers = {
      oracle: { region: 'eu-frankfurt-1', namespace: 'fixture-namespace', bucket: 'fixture-private-bucket', access_key_id: '', secret_access_key: '', configured_secrets: { access_key_id: true, secret_access_key: true } },
      odysee: { channel_id: 'b'.repeat(40), auth_token: '', configured_secrets: { auth_token: true } },
      directupload: { enabled: true, minute_limit: 10, day_limit: 300, configured_secrets: {} },
      fastpic: { enabled: true, key: '', minute_limit: 60, day_limit: 5000, configured_secrets: { key: true } },
      postimages: { enabled: true, key: '', client_o: 'a'.repeat(32), client_m: 'b'.repeat(32), minute_limit: 10, day_limit: 100, month_limit: 1000, configured_secrets: { key: true } },
    };
    const writes = [];
    let loads = 0;
    let failNextSave = false;
    const respond = (route, body, status = 200) => route.fulfill({ status, contentType: 'application/json', body: JSON.stringify(body) });
    await context.route('**/api/admin/media-providers**', async (route) => {
      const request = route.request();
      const pathname = new URL(request.url()).pathname;
      if (request.method() === 'GET') { loads++; return respond(route, { providers }); }
      writes.push({ pathname, method: request.method(), body: request.postDataJSON() });
      if (pathname.endsWith('/odysee/connect')) {
        return respond(route, { connection_id: '12345678-abcd-4abc-8abc-123456789abc', expires_in: 300, channels: [{ id: 'b'.repeat(40), name: '@crozztube-fixture' }, { id: 'c'.repeat(40), name: '@other-fixture' }] });
      }
      if (pathname.endsWith('/fastpic/test')) return respond(route, { message: 'Fastpic accepted the test image but only permits thumbnail links for it. Original-size images cannot be used this way.', warning: true });
      if (pathname.endsWith('/test')) return respond(route, { message: pathname.includes('oracle') ? 'Oracle bucket access confirmed.' : 'Odysee account and channel access confirmed.' });
      if (failNextSave) { failNextSave = false; return respond(route, { message: 'Media provider settings could not be saved. Try again shortly.' }, 503); }
      const provider = pathname.split('/').at(-1);
      const body = request.postDataJSON();
      for (const [key, value] of Object.entries(body.changes)) {
        if (Object.hasOwn(providers[provider].configured_secrets, key)) {
          if (value) providers[provider].configured_secrets[key] = true;
          providers[provider][key] = '';
        } else providers[provider][key] = value;
      }
      for (const key of body.clear_secrets) providers[provider].configured_secrets[key] = false;
      return respond(route, { message: 'Media provider settings saved.', providers });
    });
    try {
      await session.goto('/admin/system-settings?section=storage');
      const root = page.locator('.admin-media-providers');
      await expect(root).toBeVisible();
      await expect(root.locator('.admin-media-fields')).toBeVisible();
      const german = language === 'de_de';
      await expect(page.locator('#setting-external-media').getByRole('heading')).toHaveText(german ? 'Externe Mediendienste' : 'External media services'); checks++;
      check(await root.locator('input[type=password]').count() === 2, 'Oracle must show separate write-only access and secret fields.');
      for (const field of await root.locator('input[type=password]').all()) { check(await field.inputValue() === '', 'Saved credentials must never be prefilled.'); }
      const namespace = root.getByLabel('Namespace', { exact: true });
      await namespace.fill('edited-fixture-namespace');
      const test = root.getByRole('button', { name: german ? 'Verbindung testen' : 'Test connection', exact: true });
      await expect(test).toBeDisabled(); checks++;
      for (const dark of [false, true]) {
        await page.setViewportSize({ width: 1440, height: 1000 });
        await page.emulateMedia({ colorScheme: dark ? 'dark' : 'light', reducedMotion: 'reduce' });
        if ((await page.locator('.admin-dashboard-shell').first().getAttribute('data-admin-theme')) !== (dark ? 'dark' : 'light')) {
          await page.locator('.admin-top-actions > button.admin-icon-button').click();
        }
        await expect(page.locator('.admin-dashboard-shell').first()).toHaveAttribute('data-admin-theme', dark ? 'dark' : 'light');
        const panel = await root.evaluate((node) => getComputedStyle(node).getPropertyValue('--ad-panel').trim());
        check(dark ? panel === '#050505' : ['#fff', '#ffffff'].includes(panel), `The real admin theme variables must change, not just the emulated system theme (${language}, dark=${dark}, panel=${panel}).`);
        for (const width of [1440, 900, 390, 320]) {
          await page.setViewportSize({ width, height: 960 });
          await root.scrollIntoViewIfNeeded();
          check(await namespace.inputValue() === 'edited-fixture-namespace', 'Resize must preserve unsaved provider values.');
          const bounds = await root.evaluate((node) => { const r = node.getBoundingClientRect(); return { left: r.left, right: r.right, viewport: innerWidth, scroll: node.scrollWidth, width: node.clientWidth }; });
          check(bounds.left >= -1 && bounds.right <= bounds.viewport + 1 && bounds.scroll <= bounds.width + 1, 'Provider forms must fit phones, tablets and desktop without clipping.');
          await root.screenshot({ path: path.join(output, `${language}-${dark ? 'dark' : 'light'}-${width}.png`) });
        }
      }
      check(loads === 1, 'Resizing and theme changes must not reload the provider configuration.');
      await page.setViewportSize({ width: 1200, height: 1000 });
      const save = root.getByRole('button', { name: german ? 'Verbindung speichern' : 'Save connection', exact: true });
      failNextSave = true;
      await save.focus();
      await save.press('Enter');
      await expect(root.getByRole('alert')).toContainText(german ? 'nicht gespeichert' : 'could not be saved'); checks++;
      check(await namespace.inputValue() === 'edited-fixture-namespace', 'Failed saves must preserve the form.');
      await save.click();
      await expect(save).toBeDisabled(); checks++;
      check(writes.at(-1).body.changes.secret_access_key === '' && !Object.hasOwn(writes.at(-1).body.changes, 'configured_secrets'), 'Only edited fields and explicit empty secret preservation may be submitted.');
      await test.click();
      await expect(root.getByRole('status')).toContainText(german ? 'bestätigt' : 'confirmed'); checks++;
      await root.locator('.admin-media-provider-choice').getByRole('button', { name: /^Odysee\b/ }).click();
      await root.locator('.admin-media-login summary').click();
      const login = root.locator('.admin-media-login');
      await login.locator('input[type=email]').fill('fixture@example.invalid');
      await login.locator('input[type=password]').fill('fixture-password-not-real');
      await login.getByRole('button').click();
      await expect(root.getByRole('combobox')).toBeVisible(); checks++;
      check(await login.locator('input[type=password]').inputValue() === '', 'A completed sign-in must erase the password field.');
      check(await root.locator('.adaptive-select__single-value').textContent() === '@crozztube-fixture', 'A previously selected owned channel must remain selected.');
      await root.getByRole('button', { name: german ? 'Verbindung speichern' : 'Save connection', exact: true }).click();
      await expect(root.getByRole('button', { name: german ? 'Verbindung speichern' : 'Save connection', exact: true })).toBeDisabled(); checks++;
      check(Boolean(writes.at(-1).body.connection_id) && writes.at(-1).body.changes.auth_token === '', 'The browser must save a pending login reference, never receive the central token.');
      await root.locator('.admin-media-guide summary').click();
      await expect(root.locator('.admin-media-guide')).toContainText(german ? 'Nicht gelistete Videos' : 'Unlisted videos'); checks++;
      await root.locator('.admin-media-provider-choice').getByRole('button', { name: /^Fastpic\b/ }).click();
      await root.getByRole('button', { name: german ? 'Testbild hochladen' : 'Upload test image', exact: true }).click();
      await expect(root.locator('.admin-media-feedback.is-warning')).toContainText(german ? 'Vorschaulinks' : 'thumbnail links'); checks++;
      const clear = root.getByRole('button', { name: german ? 'Schlüssel entfernen' : 'Remove credential', exact: true });
      await clear.click();
      await root.getByRole('button', { name: german ? 'Verbindung speichern' : 'Save connection', exact: true }).click();
      await expect(root.getByRole('button', { name: german ? 'Verbindung speichern' : 'Save connection', exact: true })).toBeDisabled();
      check(writes.at(-1).body.clear_secrets.includes('key'), 'Removing a saved credential requires an explicit clear operation.');
      // Each guide exposes real credential/account locations, not a generic homepage.
      const expectedLinks = {
        Oracle: ['https://cloud.oracle.com/identity/domains/my-profile/auth-tokens?region=eu-frankfurt-1', 'https://cloud.oracle.com/object-storage/buckets?region=eu-frankfurt-1'],
        Fastpic: ['https://fastpic.org/keys', 'https://fastpic.org/account'],
        Postimages: ['https://postimages.org/login/api', 'https://postimages.org/signup'],
        Directupload: ['https://www.directupload.eu/yourload.php'],
        Odysee: ['https://odysee.com/$/channels', 'https://odysee.com/$/signup'],
      };
      for (const [provider, links] of Object.entries(expectedLinks)) {
        await root.locator('.admin-media-provider-choice').getByRole('button', { name: new RegExp('^' + provider + '\\b') }).click();
        const guide = root.locator('.admin-media-guide');
        if (!(await guide.getAttribute('open') !== null)) await guide.locator('summary').click();
        check(JSON.stringify(await guide.locator('a').evaluateAll((nodes) => nodes.map((node) => node.href))) === JSON.stringify(links), provider + ' guide must link directly to the correct account pages.');
        check(await guide.locator('a').evaluateAll((nodes) => nodes.every((node) => node.target === '_blank' && node.rel.includes('noopener') && node.textContent.trim().length > 4)), 'Guide links need meaningful names and must preserve the open settings form.');
      }
      check(session.writes.length === 0 && session.failedGets.length === 0 && session.errors.length === 0, 'Tests must not trigger real writes, failing application GETs or browser errors.');
      console.log(`${language}: provider settings, login, permissions boundary, failure recovery and responsive states passed.`);
    } finally { await context.close(); }
  }
} finally { await browser.close(); }
console.log(`${checks} media-provider browser checks passed with regular HTTPS verification and one browser worker.`);
