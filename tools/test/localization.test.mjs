import { test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { formatMessage, pluralForm, relativeAge, durationUnit, countMessage } from '../../resources/js/localizationFormat.js';
import { auditPacks, extractUiMessages, reviewCoverage, messageHash, formalAddressCandidate } from '../i18n/audit.mjs';

test('address review finds formal customer language across scripts without rejecting everyday personal forms', () => {
  for (const [locale, sentence] of [['de_de', 'Ändern Sie Ihr Passwort.'], ['nl_nl', 'Voer uw naam in.'], ['fr_ca', 'Choisissez votre langue.'], ['es_mx', 'Para usted.'], ['it_it', 'Il Suo account.'], ['ru_ru', 'Ваш пароль'], ['uk', 'Ваші налаштування'], ['cs_cz', 'Váš účet'], ['zh_tw', '請輸入您的密碼。'], ['ja', 'お客様の設定'], ['ko_kr', '고객님의 계정']]) {
    assert.equal(formalAddressCandidate(locale, sentence), true, locale);
  }
  for (const [locale, sentence] of [['ru_ru', 'Введите ваш пароль.'], ['id_id', 'Nama Anda'], ['hi_in', 'आपका नाम'], ['bn_bd', 'আপনার নাম'], ['vi', 'Quý khách'], ['ro_ro', 'Contul dumneavoastră']]) assert.equal(formalAddressCandidate(locale, sentence), true, locale);
  for (const [locale, sentence] of [['de_de', 'Ändere dein Passwort.'], ['nl_nl', 'Kies je taal.'], ['fr_fr', 'Choisis ta langue.'], ['pt_br', 'Escolha seu idioma.'], ['zh_cn', '输入你的密码。'], ['ja', 'パスワードを入力してください。'], ['ko_kr', '비밀번호를 입력해 주세요.']]) {
    assert.equal(formalAddressCandidate(locale, sentence), false, locale);
  }
});

test('dynamic messages preserve missing placeholders, repeated tokens, zero and literal values', () => {
  assert.equal(formatMessage('{name}: {count} / {count} {unknown}', { name: '<b>$&</b>', count: 0 }), '<b>$&</b>: 0 / 0 {unknown}');
  assert.equal(formatMessage('{enabled}', { enabled: false }), 'false');
  assert.equal(formatMessage(''), '');
  assert.equal(formatMessage(null), null);
});

test('German relative dates and durations use complete localized grammar', () => {
  assert.equal(relativeAge(1, 'hour', 'de_de'), 'vor 1 Stunde');
  assert.equal(relativeAge(2, 'hours', 'de_ch'), 'vor 2 Stunden');
  assert.equal(durationUnit(1, 'day', 'de_de'), '1 Tag');
  assert.equal(durationUnit(2, 'days', 'de_de'), '2 Tage');
  assert.match(relativeAge(2, 'hours', 'ar'), /ساعت/);
  assert.equal(relativeAge(1, 'hour', 'zz'), null);
  assert.equal(durationUnit(2, 'views', 'de_de'), null);
  assert.equal(relativeAge('invalid', 'hour', 'de_de'), null);
});

test('named plural categories handle zero, singular, Slavic and Arabic counts', () => {
  assert.equal(pluralForm(0, { one: 'Aufruf', other: 'Aufrufe' }, 'de_de'), 'Aufrufe');
  assert.equal(pluralForm(1, { one: 'Aufruf', other: 'Aufrufe' }, 'de_de'), 'Aufruf');
  assert.equal(pluralForm('1k', { one: 'view', other: 'views' }, 'en'), 'views');
  const forms = { one: 'one', two: 'two', few: 'few', many: 'many', zero: 'zero', other: 'other' };
  for (const [number, category] of [[1, 'one'], [2, 'few'], [5, 'many'], [21, 'one']]) assert.equal(pluralForm(number, forms, 'ru_ru'), category);
  for (const [number, category] of [[0, 'zero'], [2, 'two'], [3, 'few'], [11, 'many']]) assert.equal(pluralForm(number, forms, 'ar'), category);
});

test('whole count messages own word order, classifiers, plural categories and local number formatting', () => {
  assert.equal(countMessage(1, 'views', { count_view_one: '{count} view', count_view_other: '{count} views' }, 'en'), '1 view');
  assert.equal(countMessage(2, 'views', { count_view_one: '{count} view', count_view_other: '{count} views' }, 'en'), '2 views');
  assert.equal(countMessage(2, 'views', { count_view: '조회수 {count}회' }, 'ko_kr'), '조회수 2회');
  assert.equal(countMessage(1000, 'views', { count_view: '{count}回視聴' }, 'ja'), '1,000回視聴');
  assert.equal(countMessage(2, 'views', { count_view: 'المشاهدات: {count}' }, 'ar-u-nu-arab'), 'المشاهدات: ٢');
  const russian = { count_view_one: '{count} просмотр', count_view_few: '{count} просмотра', count_view_many: '{count} просмотров', count_view_other: '{count} просмотра' };
  for (const [count, expected] of [[1, '1 просмотр'], [2, '2 просмотра'], [5, '5 просмотров'], [21, '21 просмотр']]) assert.equal(countMessage(count, 'views', russian, 'ru_ru'), expected);
  assert.equal(countMessage(2, 'views', { count_view_one: '{count} view' }, 'zz'), null);
  assert.equal(countMessage(2, 'views', { count_view: 'Views: {count}' }, 'zz'), 'Views: 2');
  assert.equal(countMessage(2, 'views', { count_view: 'Views' }, 'en'), null);
  assert.equal(countMessage('invalid', 'views', { count_view: '{count} views' }, 'en'), null);
  assert.equal(countMessage(2, 'unknown', { count_unknown: '{count}' }, 'en'), null);
});

test('audit catches lost or repeated placeholders and markers without declaring English matches defective', () => {
  const en = { 'Hello {name}': 'Hello {name}', 'Missing': 'Missing', 'Watch your saved videos': 'Watch your saved videos', 'PlayHub': 'PlayHub', __patterns: { ago: '{count} {unit} ago' } };
  const report = auditPacks({ en, de_de: { 'Hello {name}': 'Hallo {name} {name}', 'PlayHub': 'ZXTERM0ZX', 'Watch your saved videos': 'Watch your saved videos', __patterns: { ago: 'vor {unit}' } } }, [{ message: 'New action' }]);
  const issues = report.languages.de_de.issues;
  assert.equal(issues.placeholder_mismatches.length, 2);
  assert.deepEqual(issues.protected_markers, ['PlayHub']);
  assert.deepEqual(issues.english_candidates, ['Watch your saved videos']);
  assert.deepEqual(issues.missing_keys, ['Missing']);
  assert.deepEqual(issues.missing_ui_messages, ['New action']);
  assert.equal(report.languages.de_de.errors, 3);
  assert.equal(report.languages.en.errors, 0);
});

test('source inventory records accessibility, errors, complete templates and conditional UI labels', () => {
  const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'crozztube-copy-'));
  try {
    fs.writeFileSync(path.join(directory, 'fixture.jsx'), `export const Panel = () => <button aria-label="Open menu">{busy ? 'Loading...' : 'Save'}{t('Chapters found: {count}', {count: 2})}{failed && 'Try again'}</button>; setError('Unable to save.'); t(busy ? 'Fetching details' : 'Fetch details');`);
    const messages = extractUiMessages(directory).map(row => row.message);
    for (const value of ['Open menu', 'Loading...', 'Save', 'Chapters found: {count}', 'Try again', 'Unable to save.', 'Fetching details', 'Fetch details']) assert.ok(messages.includes(value), value);
    assert.equal(messages.includes('count'), false);
  } finally { fs.rmSync(directory, { recursive: true, force: true }); }
});

test('source inventory distinguishes component copy from CSS, routes and machine-valued attributes', () => {
  const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'crozztube-copy-attributes-'));
  try {
    fs.writeFileSync(path.join(directory, 'fixture.jsx'), `export const Panel = () => <Card className={busy ? 'btn primary' : 'btn subtle'} role={busy ? 'status' : 'alert'} href={busy ? '/upload' : '/home'} text="Find your community" emptyText="No videos here yet." title={busy ? 'Loading details' : 'Video details'}>{busy ? 'Loading...' : 'Done'}</Card>;`);
    const messages = extractUiMessages(directory).map(row => row.message);
    for (const key of ['Find your community', 'No videos here yet.', 'Loading details', 'Video details', 'Loading...', 'Done']) assert.ok(messages.includes(key), key);
    for (const key of ['btn primary', 'btn subtle', 'status', 'alert', '/upload', '/home']) assert.equal(messages.includes(key), false, key);
  } finally { fs.rmSync(directory, { recursive: true, force: true }); }
});

test('editorial review and contextual verification stay separate and edits invalidate old reviews', () => {
  const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'crozztube-reviews-'));
  try {
    const packs = { en: { Save: 'Save', Next: 'Next' }, de_de: { Save: 'Speichern', Next: 'Weiter' } };
    const review = { messages: { [messageHash('Save')]: { source_hash: messageHash('Save'), translation_hash: messageHash('Speichern'), status: 'editorial', contexts: ['Upload form'] } } };
    fs.writeFileSync(path.join(directory, 'de_de.json'), JSON.stringify(review));
    assert.deepEqual(reviewCoverage(packs, directory).de_de, { pending: 1, editorial: 1, verified: 0, stale: 0, total: 2 });
    packs.en.Save = 'Save changes';
    assert.equal(reviewCoverage(packs, directory).de_de.stale, 1);
    packs.en.Save = 'Save';
    packs.de_de.Save = 'Sichern';
    assert.equal(reviewCoverage(packs, directory).de_de.stale, 1);
    review.messages[messageHash('Save')].translation_hash = messageHash('Sichern');
    review.messages[messageHash('Save')].status = 'verified';
    fs.writeFileSync(path.join(directory, 'de_de.json'), JSON.stringify(review));
    assert.equal(reviewCoverage(packs, directory).de_de.verified, 1);
  } finally { fs.rmSync(directory, { recursive: true, force: true }); }
});

test('review coverage includes plural forms and count templates instead of certifying only dictionary entries', () => {
  const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'crozztube-plural-review-'));
  try {
    const packs = { en: { Save: 'Save', __plurals: { view: ['view', 'views'] }, __patterns: { unread: '{count} unread' } }, fr_fr: { Save: 'Enregistrer', __plurals: { view: { one: 'vue', other: 'vues' } }, __patterns: { unread: 'Non lus : {count}' } } };
    assert.equal(reviewCoverage(packs, directory).fr_fr.total, 3);
    const row = { source_hash: messageHash(JSON.stringify(packs.en.__plurals.view)), translation_hash: messageHash(JSON.stringify(packs.fr_fr.__plurals.view)), status: 'editorial', contexts: ['View counters'] };
    fs.writeFileSync(path.join(directory, 'fr_fr.json'), JSON.stringify({ messages: { [messageHash('__plurals:view')]: row } }));
    assert.equal(reviewCoverage(packs, directory).fr_fr.editorial, 1);
    packs.fr_fr.__plurals.view.other = 'incorrect';
    assert.equal(reviewCoverage(packs, directory).fr_fr.stale, 1);
  } finally { fs.rmSync(directory, { recursive: true, force: true }); }
});

test('structural audit rejects unusable plural categories and empty patterns', () => {
  const report = auditPacks({ en: {}, fixture: { __patterns: { unread: '' }, __plurals: { view: { singular: 'view' }, user: { one: 1, other: 'users' } } } });
  assert.deepEqual(report.languages.fixture.issues.invalid_patterns, ['unread']);
  assert.deepEqual(report.languages.fixture.issues.invalid_plural_forms, ['view', 'user']);
  assert.equal(report.languages.fixture.errors, 3);
});

test('correcting the English reference cannot hide an old untranslated English sentence in another language', () => {
  const key = 'A technical demo message for creators';
  const report = auditPacks({ en: { [key]: 'Share your videos with your community.' }, de_de: { [key]: key } });
  assert.deepEqual(report.languages.de_de.issues.english_candidates, [key]);
});

test('language-specific count categories use the generic English reference without invented English plural forms', () => {
  const en = { __patterns: { count_view: '{count} views' } };
  const report = auditPacks({ en, ru_ru: { __patterns: { count_view_few: '{count} просмотра' } } });
  assert.deepEqual(report.languages.ru_ru.issues.placeholder_mismatches, []);
  const broken = auditPacks({ en, ru_ru: { __patterns: { count_view_few: 'просмотра' } } });
  assert.equal(broken.languages.ru_ru.issues.placeholder_mismatches.length, 1);
});

test('whole time patterns preserve grammatical case and unsupported-locale wording', () => {
  const patterns = { ago_hour_one: 'fyrir {count} klukkustund', ago_hour_other: 'fyrir {count} klukkustundum', duration_hour_one: '{count} klukkustund', duration_hour_other: '{count} klukkustundir' };
  assert.equal(relativeAge(1, 'hour', 'is', patterns), 'fyrir 1 klukkustund');
  assert.equal(relativeAge(2, 'hours', 'is', patterns), 'fyrir 2 klukkustundum');
  assert.equal(durationUnit(2, 'hours', 'is', patterns), '2 klukkustundir');
  assert.equal(relativeAge(2, 'hours', 'zz', { ago_hour: 'Age: {count}' }), 'Age: 2');
  assert.equal(durationUnit(2, 'hours', 'zz', { duration_hour_other: '{count} hours' }), null);
  const report = auditPacks({ en: { __patterns: { ago_hour: '{count} hours ago' } }, fixture: { __patterns: { ago_hour_few: 'vor {count} Stunden' } } });
  assert.deepEqual(report.languages.fixture.issues.placeholder_mismatches, []);
});

test('typed count labels use complete templates or the available translated noun', async () => {
  const { countLabel } = await import('../../resources/js/localizationFormat.js');
  assert.equal(countLabel(2, 'view', { patterns: { count_view: '조회수 {count}회' } }, 'ko_kr'), '조회수 2회');
  assert.equal(countLabel(2, 'view', { translations: { views: 'Aufrufe' }, plurals: { views: ['view', 'views'] } }, 'de_de'), '2 Aufrufe');
  assert.equal(countLabel(1, 'view', { translations: { view: 'Aufruf' } }, 'de_de'), '1 Aufruf');
  assert.equal(countLabel(0, 'view'), '0 views');
});

test('source inventory includes explicit component translation vocabularies', () => {
  const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'crozztube-vocabulary-'));
  try {
    fs.writeFileSync(path.join(directory, 'fixture.js'), `const mediaTranslationWords = Object.freeze(['Audio', 'Seek Forward']); const routeNames = ['machine-key'];`);
    assert.deepEqual(extractUiMessages(directory).map(row => row.message), ['Audio', 'Seek Forward']);
  } finally { fs.rmSync(directory, { recursive: true, force: true }); }
});

test('catalog orthography codes map to their own registered BCP47 tags in both directions', async () => {
  const { languageTag, catalogLocale } = await import('../../resources/js/localizationFormat.js');
  assert.equal(languageTag('pt_pt_ao90'), 'pt-PT-ao1990');
  assert.equal(catalogLocale('pt-PT-ao1990'), 'pt_pt_ao90');
  assert.equal(languageTag('pt-PT-ao1990'), 'pt-PT-ao1990');
  assert.equal(languageTag('de_ch'), 'de-ch');
  assert.equal(languageTag('zz'), 'zz');
  const patterns={count_view_one:'{count} visualização',count_view_other:'{count} visualizações'};
  assert.equal(countMessage(1,'views',patterns,'pt_pt_ao90'),'1 visualização');
  assert.equal(countMessage(2,'views',patterns,'pt_pt_ao90'),'2 visualizações');
});
