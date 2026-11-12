import assert from 'node:assert/strict';
import { chromium, expect } from '@playwright/test';
import { applicationJson, layoutSession, layoutProblems } from './fixtures/readonly-layout.ts';

const browser = await chromium.launch({ headless: true });
try {
  for (const locale of ['de_de', 'en', 'ja', 'ko_kr', 'ar', 'ru_ru']) {
    const pack = applicationJson(`/api/i18n/${locale}`, false);
    const session = await layoutSession(browser, { language: locale, authenticated: false, width: 390, transform: (path, body) => {
      if (!path.startsWith('/api/videos?') || !Array.isArray(body.data)) return body;
      return { ...body, data: body.data.map(video => ({ ...video, views: 2, views_count: 2, title: 'Home', description: 'Saved', channel: { ...video.channel, name: 'Settings' } })) };
    } });
    try {
      await session.goto('/');
      const card = session.page.locator('.video-card').first();
      const expected = await session.page.evaluate(({locale,patterns}) => {
        const code=locale.replaceAll('_','-'), category=Intl.PluralRules.supportedLocalesOf([code]).length ? new Intl.PluralRules(code).select(2) : 'other';
        const pattern=patterns[`count_view_${category}`] || patterns.count_view || patterns.count_view_other;
        const value=Intl.NumberFormat.supportedLocalesOf([code]).length ? new Intl.NumberFormat(code).format(2) : '2';
        return pattern.replace('{count}',value);
      }, {locale,patterns:pack.patterns});
      await expect(card.locator('h3')).toHaveText('Home');
      await expect(card.locator('.channel-link')).toHaveText('Settings');
      await expect(card).toContainText(expected);
      assert.ok(await card.locator('[data-i18n-skip]').allTextContents().then(texts=>texts.includes(expected)), `${locale}: actual React counter is protected`);
      assert.deepEqual(await layoutProblems(session.page), [], `${locale}: home at390px`);
      assert.deepEqual(session.errors, []); assert.deepEqual(session.writes, []);
      console.log(`${locale}: actual video-card count, original provider title/channel,390px passed.`);
    } finally { await session.context.close(); }
  }
  const session = await layoutSession(browser, { language: 'de_de', authenticated: false, width: 1440 });
  try {
    await session.goto('/register');
    const subtitle = session.page.locator('.auth-head p');
    // Registration subtitle must resolve its original message before replacing the site brand.
    const actual = await subtitle.textContent();
    assert.ok(actual && !actual.includes('Create your'), 'Registration subtitle is localized');
    const email = session.page.locator('.auth-card input[type=email]');
    await email.fill('saved@example.test');
    await session.page.evaluate(() => {
      const span=document.createElement('span');span.id='unchanged-time';span.textContent='12:03';document.body.append(span);
    });
    const choose = async code => {
      await session.page.locator('.auth-language .language-switcher-trigger').click();
      await session.page.locator('.language-dialog-list button').filter({has:session.page.locator('small',{hasText:new RegExp(`^${code}$`)})}).first().click();
    };
    await choose('en');
    await expect(subtitle).not.toHaveText(actual);
    await expect(email).toHaveValue('saved@example.test');
    await choose('de-de');
    await expect(subtitle).toHaveText(actual);
    await expect(session.page.locator('#unchanged-time')).toHaveText('12:03');
    await expect(email).toHaveValue('saved@example.test');
    assert.deepEqual(session.errors, []);assert.deepEqual(session.writes, []);
    console.log('Custom site branding, EN↔DE registration draft and timestamp punctuation passed.');
  } finally { await session.context.close(); }
} finally { await browser.close(); }
