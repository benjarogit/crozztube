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
const buttonContrast = (style) => {
  const luminance = (color) => {
    const channels = color.match(/[\d.]+/g).slice(0, 3).map(Number).map(value => color.startsWith('color(srgb') ? value : value / 255);
    const linear = channels.map(value => value <= .04045 ? value / 12.92 : ((value + .055) / 1.055) ** 2.4);
    return linear[0] * .2126 + linear[1] * .7152 + linear[2] * .0722;
  };
  const colors = [luminance(style.color), luminance(style.backgroundColor)].sort((a, b) => a - b);
  return (colors[1] + .05) / (colors[0] + .05);
};
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
    let failNextTest = false;
    let storageTests = 0;
    const respond = (route, body, status = 200) => route.fulfill({ status, contentType: 'application/json', body: JSON.stringify(body) });
    await context.route('**/api/admin/storage-settings/test', (route) => { storageTests++; return respond(route, { message: 'Action completed.' }); });
    await context.route('**/api/admin/media-providers**', async (route) => {
      const request = route.request();
      const pathname = new URL(request.url()).pathname;
      if (request.method() === 'GET') { loads++; return respond(route, { providers }); }
      writes.push({ pathname, method: request.method(), body: request.postDataJSON() });
      if (pathname.endsWith('/odysee/connect')) {
        return respond(route, { connection_id: '12345678-abcd-4abc-8abc-123456789abc', expires_in: 300, channels: [{ id: 'b'.repeat(40), name: '@crozztube-fixture' }, { id: 'c'.repeat(40), name: '@other-fixture' }] });
      }
      if (pathname.endsWith('/test') && failNextTest) { failNextTest = false; return respond(route, { message: 'The connection test failed. Check the saved settings and provider availability.' }, 503); }
      if (pathname.endsWith('/postimages/test')) return respond(route, { message: 'Test image uploaded successfully.', test_image_url: 'https://i.postimg.cc/fixture/test.png' });
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
      const styleOf = (button) => button.evaluate((element) => {
        const style = getComputedStyle(element);
        return Object.fromEntries(['height', 'borderRadius', 'fontSize', 'fontWeight', 'backgroundImage', 'backgroundColor', 'color'].map(key => [key, style[key]]));
      });
      const storageSave = page.locator('#setting-storage .admin-outline-action.primary-text');
      const referenceButtonStyle = await styleOf(storageSave);
      await storageSave.hover();
      const referenceButtonHover = await styleOf(storageSave);
      const quota = page.locator('#setting-storage input[type=number]').first();
      const quotaValue = await quota.inputValue();
      await quota.hover();
      await quota.focus();
      const fieldFocus = await quota.evaluate(input => {
        const inner=getComputedStyle(input), outer=getComputedStyle(input.parentElement);
        return { innerOutline:inner.outlineStyle, outerOutline:outer.outlineStyle, outerWidth:parseFloat(outer.outlineWidth), radius:parseFloat(outer.borderRadius) };
      });
      check(fieldFocus.innerOutline === 'none' && fieldFocus.outerOutline === 'solid' && fieldFocus.outerWidth >= 2 && fieldFocus.radius > 0, 'Keyboard focus must surround the rounded field once, without an inner square outline.');
      check(await quota.inputValue() === quotaValue, 'Hover and focus must preserve the number and its native input control.');
      await quota.locator('..').locator('..').screenshot({ path: path.join(output, language + '-number-field-focus.png') });
      const dialog = page.getByRole('alertdialog');
      const closeResult = async () => {
        await expect(dialog).toHaveClass(/admin-alert-dialog/);
        await dialog.getByRole('button', { name: german ? 'Okay' : 'OK', exact: true }).click();
        await expect(dialog).toHaveCount(0);
        checks++;
      };
      // Compare the external result with the existing storage provider dialog.
      await page.locator('#setting-storage').getByRole('button', { name: german ? 'Verbindung testen' : 'Test connection', exact: true }).click();
      await expect(dialog).toBeVisible();
      const sharedDialogClass = await dialog.getAttribute('class');
      await expect(dialog.getByRole('heading')).toHaveText(german ? 'Aktion abgeschlossen' : 'Action completed'); checks++;
      await closeResult();
      const initialTest = root.getByRole('button', { name: german ? 'Verbindung testen' : 'Test connection', exact: true });
      await initialTest.click();
      await expect(dialog).toHaveClass(sharedDialogClass);
      await expect(dialog).toContainText(german ? 'bestätigt' : 'confirmed'); checks++;
      const resultButton = dialog.getByRole('button', { name: german ? 'Okay' : 'OK', exact: true });
      check(JSON.stringify(await styleOf(resultButton)) === JSON.stringify(referenceButtonStyle), 'Result buttons must share the storage save button typography, dimensions and resting surface.');
      check(buttonContrast(await styleOf(resultButton)) >= 4.5, 'Normal dialog text must retain readable contrast.');
      await page.screenshot({ path: path.join(output, language + '-test-result-light.png') });
      await resultButton.hover();
      check(JSON.stringify(await styleOf(resultButton)) === JSON.stringify(referenceButtonHover), 'Result buttons must share the storage action hover treatment.');
      check(buttonContrast(await styleOf(resultButton)) >= 4.5, 'Hover dialog text must contrast with the configured accent.');
      await page.keyboard.press('Tab');
      check(await resultButton.evaluate(e => getComputedStyle(e).outlineStyle === 'solid' && parseFloat(getComputedStyle(e).outlineWidth) >= 2), 'Result buttons must retain a visible keyboard focus indicator.');
      await page.screenshot({ path: path.join(output, language + '-test-result-focus-light.png') });
      await closeResult();
      await expect(initialTest).toBeFocused(); checks++;
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
      await expect(dialog).toHaveClass(sharedDialogClass);
      await expect(dialog).toContainText(german ? 'bestätigt' : 'confirmed'); checks++;
      await expect(root.locator('.admin-media-feedback')).toHaveCount(0); checks++;
      await closeResult();
      await expect(test).toBeFocused(); checks++;
      failNextTest = true;
      await page.setViewportSize({ width: 390, height: 700 });
      await test.click();
      check(buttonContrast(await styleOf(dialog.getByRole('button', { name: german ? 'Okay' : 'OK', exact: true }))) >= 4.5, 'Dark dialog buttons must retain readable contrast.');
      await expect(dialog).toHaveClass(/tone-danger/);
      await expect(dialog.getByRole('heading')).toHaveText(german ? 'Aktion fehlgeschlagen' : 'Action failed');
      await expect(dialog).toContainText(german ? 'Verbindungstest ist fehlgeschlagen' : 'connection test failed'); checks++;
      check(await namespace.inputValue() === 'edited-fixture-namespace', 'Connection dialogs must preserve the form.');
      const modalBounds = await dialog.boundingBox();
      check(modalBounds.x >= 0 && modalBounds.x + modalBounds.width <= 391 && modalBounds.y >= 0 && modalBounds.y + modalBounds.height <= 701, 'Test result dialogs must fit a narrow viewport.');
      await page.screenshot({ path: path.join(output, language + '-test-error-dark-mobile.png') });
      await page.keyboard.press('Escape');
      await expect(dialog).toHaveCount(0);
      await expect(test).toBeFocused(); checks++;
      await expect(test).toBeEnabled();
      await page.setViewportSize({ width: 1200, height: 1000 });
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
      await expect(dialog).toHaveClass(sharedDialogClass);
      await expect(dialog.getByRole('heading')).toHaveText(german ? 'Hinweis' : 'Notice');
      await expect(dialog).toContainText(german ? 'Vorschaulinks' : 'thumbnail links'); checks++;
      await closeResult();
      const clear = root.getByRole('button', { name: german ? 'Schlüssel entfernen' : 'Remove credential', exact: true });
      await clear.click();
      await root.getByRole('button', { name: german ? 'Verbindung speichern' : 'Save connection', exact: true }).click();
      await expect(root.getByRole('button', { name: german ? 'Verbindung speichern' : 'Save connection', exact: true })).toBeDisabled();
      check(writes.at(-1).body.clear_secrets.includes('key'), 'Removing a saved credential requires an explicit clear operation.');
      await root.locator('.admin-media-provider-choice').getByRole('button', { name: /^Postimages\b/ }).click();
      await root.getByRole('button', { name: german ? 'Testbild hochladen' : 'Upload test image', exact: true }).click();
      await expect(dialog).toHaveClass(sharedDialogClass);
      await expect(dialog).toContainText(german ? 'Testbild wurde hochgeladen' : 'Test image uploaded successfully'); checks++;
      await closeResult();
      await expect(root.getByRole('link', { name: german ? 'Testbild öffnen' : 'Open test image' })).toHaveAttribute('href', 'https://i.postimg.cc/fixture/test.png'); checks++;
      check(storageTests === 1, 'The reference test must use the existing storage provider flow without real side effects.');
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
