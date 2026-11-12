import { test, expect } from '@playwright/test';
import { layoutSession, expectContained } from '../fixtures/readonly-layout';
test.use({ trace: 'off', video: 'off' });
test('AUF1 automatic and manual selection preserve chapters and show real availability', async ({ browser }) => {
  const session = await layoutSession(browser, { language: 'de_de', width: 390 });
  const requests: any[] = [];
  await session.context.route('**/api/video-imports/metadata', route => {
    requests.push(route.request().postDataJSON());
    return route.fulfill({ json: { metadata: { provider: 'auf1', provider_id: '6428', import_identity: 'external:auf1:6428',
      source_url: 'https://auf1.tv/nachrichten-auf1/nachrichten-auf1-vom-06-november-2026/', title: 'Home', description: 'Saved', duration_seconds: 1559,
      tags: ['Home', 'Saved'], captions: [], chapters: [{ start_time: 0, title: 'Home' }, { start_time: 60, title: 'Settings' }], chapters_source: 'provider',
    } } });
  });
  try {
    const { page } = session;
    await session.goto('/upload');
    await page.getByRole('textbox', { name: 'Videolink', exact: true }).fill('https://www.auf1.tv/nachrichten-auf1/nachrichten-auf1-vom-06-november-2026/?test=tracking');
    await expect(page.locator('input[value="Home"]')).toBeVisible();
    await expect.poll(() => requests.length).toBeGreaterThan(0);
    expect(requests[0].provider).toBe('auf1');
    await expect(page.locator('.upload-import-provider')).toContainText('AUF1');
    await expect(page.locator('.upload-import-results')).toContainText('Gefundene Kapitel: 2');
    await expect(page.locator('.upload-import-results')).not.toContainText('Untertitelsprachen:');
    await expect(page.locator('.upload-import-box')).toContainText('Originalstream');
    // The same registry also exposes AUF1 as an explicit choice.
    const service = page.locator('.upload-import-field').first().getByRole('combobox');
    await service.press('ArrowDown');
    await page.getByRole('option', { name: 'AUF1', exact: true }).click();
    await expect(service.locator('..').locator('..')).toContainText('AUF1');
    const category = page.locator('.upload-label').filter({ hasText: /^Kategorie/ }).locator('..').getByRole('combobox');
    await category.press('ArrowDown');
    await page.getByRole('option', { disabled: false }).first().click();
    await page.getByRole('button', { name: 'Weiter', exact: true }).click();
    await page.getByRole('button', { name: 'Weiter', exact: true }).click();
    await expect(page.getByRole('checkbox', { name: /Kapitel übernehmen/ })).toBeChecked();
    await expect(page.locator('.upload-chapter-row')).toHaveCount(2);
    await expectContained(page, 'AUF1 chapter review at 390');
    expect(session.writes).toEqual([]);
    expect(session.errors).toEqual([]);
  } finally { await session.context.close(); }
});
