import { test, expect } from '@playwright/test';
import { layoutSession, expectContained } from '../fixtures/readonly-layout';

test.use({ trace: 'off', video: 'off' });

test('German import details use complete messages and retain provider content on a narrow display', async ({ browser }) => {
  const session = await layoutSession(browser, { language: 'de_de', width: 390 });
  try {
    await session.context.route('**/api/video-imports/metadata', route => {
      const body = route.request().postDataJSON();
      return route.fulfill({ json: { metadata: {
        provider: body.provider, source_url: body.url, title: 'Saved', description: 'Home',
        duration_seconds: 120, captions: [{ language: 'de' }, { language: 'en' }],
        chapters: [{ start_time: 0, title: 'Home' }, { start_time: 60, title: 'Settings' }], chapters_source: 'provider',
      } } });
    });
    await session.goto('/upload');
    const { page } = session;
    await expect(page.getByRole('heading', { name: 'Video importieren', exact: true })).toBeVisible();
    const input = page.getByRole('textbox', { name: 'Videolink', exact: true });
    await expect(input).toHaveAttribute('placeholder', 'https://…');
    await input.fill('https://www.youtube.com/watch?v=fixture1234');
    await expect(page.locator('.upload-import-results')).toContainText('Gefundene Untertitelsprachen: 2');
    await expect(page.locator('.upload-import-results')).toContainText('Gefundene Kapitel: 2');
    await expect(page.locator('input[value="Saved"]')).toBeVisible();
    await expect(page.locator('textarea').first()).toHaveValue('Home');
    await expect(page.locator('main')).not.toContainText('original language');
    await expect(page.locator('.upload-steps-panel')).toContainText('Videoeinstellungen prüfen');
    await expectContained(page, 'German import at 390px');
    expect(session.errors).toEqual([]);
    expect(session.writes).toEqual([]);
  } finally { await session.context.close(); }
});

test('language changes restore UI text and preserve names, URLs and the login draft', async ({ browser }) => {
  const session = await layoutSession(browser, { authenticated: false, language: 'en' });
  try {
    await session.goto('/login');
    const { page } = session;
    page.setDefaultTimeout(10_000);
    const email = page.locator('.auth-card input').first();
    await email.fill('saved@example.test');
    await page.evaluate(() => {
      const fixture = document.createElement('section');
      fixture.id = 'localization-fixture';
      for (const [key, text, skip] of [['age', '2 hours ago', false], ['singular', '1 view', false], ['plural', '2 views', false], ['name', 'Saved', true], ['url', 'https://example.test/watch?q=Saved', false]]) {
        const element = document.createElement('span');
        element.dataset.testid = String(key);
        element.textContent = String(text);
        if (skip) element.dataset.i18nSkip = '';
        fixture.append(element);
      }
      document.body.append(fixture);
    });
    const choose = async (code: string) => {
      await page.locator('.auth-language .language-switcher-trigger').click();
      await page.locator('.language-dialog-list button').filter({ has: page.locator('small', { hasText: new RegExp(`^${code}$`) }) }).first().click();
    };
    await choose('de-de');
    await expect(page.getByTestId('age')).toHaveText('vor 2 Stunden');
    await expect(page.getByTestId('singular')).toHaveText('1 Aufruf');
    await expect(page.getByTestId('plural')).toHaveText('2 Aufrufe');
    await expect(email).toHaveValue('saved@example.test');
    await expect(page.getByTestId('name')).toHaveText('Saved');
    await expect(page.getByTestId('url')).toHaveText('https://example.test/watch?q=Saved');
    await choose('en');
    await expect(page.getByTestId('age')).toHaveText('2 hours ago');
    await expect(page.getByTestId('singular')).toHaveText('1 view');
    await expect(email).toHaveValue('saved@example.test');
    expect(session.errors).toEqual([]);
    expect(session.writes).toEqual([]);
  } finally { await session.context.close(); }
});

test('video titles, descriptions and channel names that match UI messages keep their original wording', async ({ browser }) => {
  const session = await layoutSession(browser, { language: 'de_de', authenticated: false, transform: (path, payload) => {
    if (!path.startsWith('/api/videos?') || !Array.isArray(payload.data)) return payload;
    return { ...payload, data: payload.data.map((video: any) => ({ ...video,
      title: 'Home', description: 'Save for later', channel: { ...video.channel, name: 'Settings' },
    })) };
  } });
  try {
    await session.goto('/');
    const { page } = session;
    await expect(page.locator('.hero-copy h1')).toHaveText('Home');
    await expect(page.locator('.hero-copy p')).toHaveText('Save for later');
    await expect(page.locator('.video-card h3').first()).toHaveText('Home');
    await expect(page.locator('.video-card .channel-link').first()).toHaveText('Settings');
    await expect(page.locator('.hero-buttons .primary')).toContainText('Jetzt ansehen');
    expect(session.errors).toEqual([]);
    expect(session.writes).toEqual([]);
  } finally { await session.context.close(); }
});

for (const [legacy, canonical] of [['de_de_formal', 'de_de'], ['nl_nl_formal', 'nl_nl'], ['de_ch_informal', 'de_ch']]) {
  test(`stored ${legacy} preference selects ${canonical} without changing the login draft`, async ({ browser }) => {
    const session = await layoutSession(browser, { authenticated: false, language: legacy, width: 390 });
    try {
      await session.goto('/login');
      const page = session.page;
      await expect(page.locator('html')).toHaveAttribute('lang', canonical.replaceAll('_', '-'));
      await expect.poll(() => page.evaluate(() => localStorage.getItem('playhub_language'))).toBe(canonical);
      const email = page.locator('.auth-card input').first();
      await email.fill('saved@example.test');
      await page.locator('.auth-language .language-switcher-trigger').click();
      await expect(page.locator('.language-dialog-list')).not.toContainText(/de-de-formal|de-ch-informal|nl-nl-formal/);
      await expect(email).toHaveValue('saved@example.test');
      expect(session.errors).toEqual([]);
      expect(session.writes).toEqual([]);
    } finally { await session.context.close(); }
  });
}

test('administrator page text remains literal while surrounding controls use the selected language', async ({ browser }) => {
  const session = await layoutSession(browser, { authenticated: false, language: 'de_de', transform: (path, payload) => {
    if (!path.startsWith('/api/content-pages/privacy') || !payload.page) return payload;
    return { ...payload, page: { ...payload.page, source_locale: 'en', customized: false,
      title: 'Settings', navigation_label: 'Settings', subtitle: 'Saved',
      sections: [{ title: 'Home', body: 'Save for later' }],
    } };
  } });
  try {
    await session.goto('/privacy');
    const page = session.page;
    await expect(page.locator('.info-doc-article h1')).toHaveText('Settings');
    await expect(page.locator('.info-doc-article > p')).toHaveText('Saved');
    await expect(page.locator('.info-doc-sections h2')).toHaveText('1. Home');
    await expect(page.locator('.info-doc-sections p')).toHaveText('Save for later');
    await expect(page.locator('.info-toc button').first()).toHaveText('1. Home');
    await expect(page.locator('.info-breadcrumb strong')).toHaveText('Settings');
    await expect(page.locator('.info-breadcrumb button')).toHaveText('Startseite');
    expect(session.errors).toEqual([]);
    expect(session.writes).toEqual([]);
  } finally { await session.context.close(); }
});

test('French social login uses a complete action and customized authentication copy stays literal', async ({ browser }) => {
  const session = await layoutSession(browser, { authenticated: false, language: 'fr_fr', transform: (path, payload) => {
    if (!path.startsWith('/api/integrations/public')) return payload;
    return { ...payload, auth: { ...payload.auth, social: { google: { enabled: true }, facebook: { enabled: true }, apple: { enabled: true } } } };
  } });
  try {
    await session.goto('/login');
    const page = session.page;
    await expect(page.locator('.auth-social-button.google')).toHaveText('Se connecter avec Google');
    const email = page.locator('.auth-card input').first();
    await email.fill('saved@example.test');
    await page.evaluate(() => {
      const app = window as any;
      app.__PLAYHUB_WEBSITE__ = { ...app.__PLAYHUB_WEBSITE__, authPreview: { title: 'Settings', description: 'Home', feature1: 'Saved', feature2: '', feature3: '', feature4: '' } };
      window.dispatchEvent(new CustomEvent('playhub:website-config'));
    });
    await expect(page.locator('.auth-preview h1')).toHaveText('Settings');
    await expect(page.locator('.auth-preview > p')).toHaveText('Home');
    await expect(page.locator('.auth-preview li')).toHaveText('Saved');
    await expect(email).toHaveValue('saved@example.test');
    expect(session.errors).toEqual([]);
    expect(session.writes).toEqual([]);
  } finally { await session.context.close(); }
});

test('membership buttons keep German word order and currency formatting around a literal price', async ({ browser }) => {
  const session = await layoutSession(browser, { language: 'de_de', width: 390, transform: (path, payload) => {
    if (path.startsWith('/api/auth/me') && payload.user) return { ...payload, user: { ...payload.user, storage: { ...payload.user.storage, membership: 'starter', membership_plans: [{ id: 'plus', paid: true, price_usd: 12.5 }] } } };
    if (path.startsWith('/api/integrations/public')) return { ...payload, payments: { providers: { stripe: { enabled: true } } } };
    return payload;
  } });
  try {
    await session.goto('/plans');
    const page = session.page;
    await expect(page.locator('.plan-option-card').filter({ has: page.getByRole('heading', { name: 'Plus', exact: true }) }).locator('button')).toHaveText('Für 12,50 $ im Monat abonnieren');
    await expectContained(page, 'Localized membership amount at 390px');
    expect(session.errors).toEqual([]);
    expect(session.writes).toEqual([]);
  } finally { await session.context.close(); }
});

for (const [locale, expected] of [
  ['fr_fr', ['1 vue', '2 vues', '5 vues', '21 vues']],
  ['nl_nl', ['1 weergave', '2 weergaven', '5 weergaven', '21 weergaven']],
  ['ru_ru', ['1 просмотр', '2 просмотра', '5 просмотров', '21 просмотр']],
] as const) {
  test(`${locale} renders real singular and plural view counts after the language pack loads`, async ({ browser }) => {
    const session = await layoutSession(browser, { authenticated: false, language: locale, width: 390 });
    try {
      await session.goto('/login');
      await session.page.evaluate(() => {
        const fixture = document.createElement('div');
        fixture.id = 'grammar-context';
        for (const count of [1, 2, 5, 21]) {
          const text = document.createElement('span');
          text.textContent = `${count} views`;
          fixture.append(text);
        }
        document.querySelector('.auth-card')!.append(fixture);
      });
      await expect(session.page.locator('#grammar-context span')).toHaveText([...expected]);
      expect(session.errors).toEqual([]);
      expect(session.writes).toEqual([]);
    } finally { await session.context.close(); }
  });
}

for (const language of ['de_de', 'de_at', 'de_ch']) {
  test(`${language} community post menu uses whole German action labels`, async ({ browser }) => {
    const session = await layoutSession(browser, { authenticated: false, language, width: 1254 });
    try {
      await session.context.addInitScript(() => Object.defineProperty(navigator, 'clipboard', { configurable: true, value: { writeText: async () => {} } }));
      await session.goto('/communities');
      const post = session.page.locator('.community-post-card').first();
      await post.getByRole('button', { name: 'Beitragsoptionen', exact: true }).click();
      await expect(post.getByRole('menuitem', { name: 'Link zum Beitrag kopieren', exact: true })).toBeVisible();
      await expect(post.getByRole('menuitem', { name: 'Beitrag teilen', exact: true })).toBeVisible();
      await post.getByRole('menuitem', { name: 'Link zum Beitrag kopieren', exact: true }).click();
      await expect(post).toContainText('Beitragslink kopiert.');
      await session.page.evaluate(() => Object.defineProperty(navigator, 'clipboard', { configurable: true, value: { writeText: async () => { throw new Error('Fixture copy failure'); } } }));
      await post.getByRole('button', { name: 'Beitragsoptionen', exact: true }).click();
      await post.getByRole('menuitem', { name: 'Link zum Beitrag kopieren', exact: true }).click();
      await expect(post).toContainText('Der Link zum Beitrag konnte nicht kopiert werden.');
      await expectContained(session.page, `${language} community menu`);
      expect(session.errors).toEqual([]);
      expect(session.writes).toEqual([]);
    } finally { await session.context.close(); }
  });
}
