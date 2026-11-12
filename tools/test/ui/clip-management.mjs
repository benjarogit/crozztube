import assert from 'node:assert/strict';
import { chromium, expect } from '@playwright/test';
import { expectContained, layoutSession } from './fixtures/readonly-layout.ts';

const browser = await chromium.launch({ headless: true });
let checks = 0;
try {
  const session = await layoutSession(browser, { authenticated: true, language: 'de_de', width: 390, dark: true, strictHttps: true });
  try {
    const { page, context } = session;
    const readyId = 'cb362d31-cd5a-4c58-849a-830e7530ece0';
    const failedId = '8e05ba35-1ca1-4a8c-a97f-bd4b33c6f253';
    const collectionId = 'f240f41a-f0a3-4171-a9dc-24f8e7fd3fd0';
    const original = { slug: 'fixture-original', title: 'Unveränderter Originaltitel' };
    let visibility = 'private';
    let collectionAdded = false;
    let deleted = false;
    let writes = [];
    const rows = () => [
      { id: readyId, title: 'Fertiger Clip', state: deleted ? 'cancelled' : 'ready', can_watch: !deleted, cancel_requested: deleted, available_at: deleted ? null : '2026-11-11T12:00:00Z', visibility, start: 8, end: 18, original, collections: collectionAdded ? [{ id: collectionId, title: 'Highlights' }] : [] },
      { id: failedId, title: 'Fehlgeschlagener Clip', state: 'failed', can_watch: false, can_retry: true, cancel_requested: false, available_at: null, visibility: 'private', start: 12, end: 20, original, collections: [] },
    ];
    await context.route('**/api/clip-transfers**', async route => {
      const request = route.request(), path = new URL(request.url()).pathname;
      if (request.method() === 'GET') return route.fulfill({ json: { transfers: rows() } });
      writes.push(`${request.method()} ${path}`);
      if (request.method() === 'PATCH') {
        visibility = JSON.parse(request.postData() || '{}').visibility;
        return route.fulfill({ json: { transfer: rows()[0] } });
      }
      if (request.method() === 'DELETE') {
        deleted = true;
        return route.fulfill({ status: 202, json: { transfer: rows()[0] } });
      }
      return route.fulfill({ status: 400, json: {} });
    });
    await context.route('**/api/clip-collections**', async route => {
      const request = route.request(), path = new URL(request.url()).pathname;
      if (request.method() === 'GET') return route.fulfill({ json: { collections: [{ id: collectionId, title: 'Highlights', visibility: 'private', clips_count: 0 }] } });
      writes.push(`${request.method()} ${path}`);
      if (request.method() === 'PUT') collectionAdded = true;
      return route.fulfill({ json: { included: request.method() === 'PUT' } });
    });

    await session.goto('/clip-uploads');
    const ready = page.locator('.clip-transfer-row').filter({ hasText: 'Fertiger Clip' });
    await ready.getByLabel('Sichtbarkeit des Clips').selectOption('public');
    await expect(ready.getByLabel('Sichtbarkeit des Clips')).toHaveValue('public');
    await ready.getByLabel('Clip zu einer Sammlung hinzufügen').selectOption(collectionId);
    await expect(ready).toContainText('Highlights');
    checks += 3;

    await ready.getByRole('button', { name: 'Clip löschen' }).click();
    await expect(ready.getByRole('alertdialog')).toContainText('Diesen Clip bei Odysee');
    await ready.getByRole('alertdialog').getByRole('button', { name: 'Clip löschen' }).click();
    await expect(ready).toContainText('Dein Clip-Upload wurde abgebrochen.');
    checks += 3;

    const failed = page.locator('.clip-transfer-row').filter({ hasText: 'Fehlgeschlagener Clip' });
    const retry = failed.getByRole('button', { name: 'Clip erneut erstellen' });
    await expect(retry).toBeVisible();
    await expectContained(page, 'clip management mobile');
    checks += 2;

    assert(writes.some(write => write === `PATCH /api/clip-transfers/${readyId}`));
    assert(writes.some(write => write === `PUT /api/clip-collections/${collectionId}/clips/${readyId}`));
    assert(writes.some(write => write === `DELETE /api/clip-transfers/${readyId}`));
    assert.deepEqual(session.errors, []);
    assert.deepEqual(session.failedGets, []);
    checks += 5;
  } finally {
    await session.context.close();
  }
  console.log(`${checks} clip management browser checks passed. Visibility, collection, retry, delete confirmation, German mobile layout.`);
} finally {
  await browser.close();
}
