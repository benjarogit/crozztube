import { test, expect, type Browser, type Locator, type Route } from '@playwright/test';
import { applicationJson, layoutSession, expectContained } from '../fixtures/readonly-layout';

test.use({ trace: 'off', video: 'off' });
const pixel = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=', 'base64');
const imageFile = (name = 'selected.png') => ({ name, mimeType: 'image/png', buffer: pixel });
const recipient = { id: 802, name: 'Attachment recipient', handle: '@attachment-recipient', can_message: true, profile_path: '/channel/attachment-recipient' };
const parentComment = { id: 8800, body: 'Existing comment for an attachment reply', user: { id: 802, name: 'Comment author', channel: { slug: 'comment-author', name: 'Comment author' } }, replies: [], replies_count: 0, likes_count: 0, created_label: 'Earlier' };
const formFields = (body: string) => [...body.matchAll(/name="([^"]+)"\r\n\r\n([^\r\n]*)/g)].map(match => [match[1], match[2]]);
const field = (body: string, name: string) => formFields(body).find(([key]) => key === name)?.[1] || '';
const idsIn = (body: string) => formFields(body).filter(([name]) => name === 'image_ids[]').map(([, id]) => id);
type Options = { holdFirst?: boolean; failName?: string; loseResponse?: boolean; busyAttempts?: number; width?: number; language?: string };

async function attachmentSession(browser: Browser, options: Options = {}) {
  const user = applicationJson('/api/auth/me').user;
  const videoList = applicationJson('/api/videos?per_page=50').data;
  const video = videoList.find((item: any) => !item.is_short), short = videoList.find((item: any) => item.is_short);
  const seedPost: any = { id: 7700, type: 'text', body: 'Existing community post for image comments', author: { ...user.channel, user_id: user.id }, attachments: [], likes_count: 0, comments_count: 1, can_edit: true, can_delete: true, created_at: '2026-11-05T06:00:00Z' };
  const session = await layoutSession(browser, { width: options.width || 1024, height: 900, language: options.language, transform: (path, data) => path === '/api/integrations/public' ? { ...data, features: { ...data.features, live_streaming: { ...data.features?.live_streaming, enabled: true } } } : data });
  const puts: { id: string; name: string; purpose: string | null; target: string | null; type: string; bytes: number; authorization: string }[] = [];
  const deletes: string[] = [], gets: string[] = [], publishes: { path: string; body: string; ids: string[] }[] = [];
  const records = new Map<string, any>(), attempts = new Map<string, number>();
  let active = 0, maximum = 0, released = false, releaseFirst = () => {};
  const firstGate = new Promise<void>(resolve => { releaseFirst = () => { released = true; resolve(); }; });
  let posts = [seedPost];
  let conversation: any = { id: 901, name: recipient.name, recipient, to: '/messages/901', messages: [], unread: 0, section: 'primary' };
  const payload = (id: string, name: string) => ({ id, status: 'ready', attachment: { type: 'image', image_id: id, url: `https://images.example.test/${id}.png`, image_candidates: [`https://images.example.test/${id}.png`, `https://fallback.example.test/${id}.png`], name, mime: 'image/png', size: pixel.length, width: 1, height: 1 } });
  const json = (route: Route, value: any, status = 200, headers: Record<string, string> = {}) => route.fulfill({ status, headers, contentType: 'application/json', body: JSON.stringify(value) });
  await session.context.route('**/api/**', async route => {
    const request = route.request(), url = new URL(request.url()), method = request.method();
    const id = url.pathname.match(/^\/api\/image-uploads\/([^/]+)$/)?.[1];
    if (id) {
      if (method === 'GET') { gets.push(id); return json(route, records.get(id) || { message: 'Unknown draft' }, records.has(id) ? 200 : 404); }
      if (method === 'DELETE') { deletes.push(id); records.set(id, { id, status: 'abandoned', attachment: null }); return json(route, { id, status: 'abandoned' }); }
      if (method !== 'PUT') throw new Error('Unexpected upload method');
      const name = decodeURIComponent(request.headers()['x-image-name'] || ''), attempt = (attempts.get(id) || 0) + 1; attempts.set(id, attempt);
      puts.push({ id, name, purpose: url.searchParams.get('purpose'), target: url.searchParams.get('target_id'), type: request.headers()['content-type'], bytes: request.postDataBuffer()?.length || 0, authorization: request.headers()['authorization'] || '' });
      active++; maximum = Math.max(maximum, active);
      try {
        if (options.holdFirst && puts.length === 1 && !released) await firstGate;
        if (records.get(id)?.status === 'abandoned') return json(route, { message: 'Cancelled draft' }, 409).catch(() => {});
        if (attempt <= (options.busyAttempts || 0)) return json(route, { id, status: 'failed', message: 'Image hosting is busy. Try the same file again shortly.' }, 429, { 'Retry-After': '1' });
        if (name === options.failName && attempt === 1) { records.set(id, { id, status: 'failed', attachment: null }); return json(route, { id, status: 'failed', message: 'Image hosting is unavailable. Select the file again or retry shortly.' }, 503); }
        const value = payload(id, name); records.set(id, value);
        if (options.loseResponse && attempt === 1) return route.abort('connectionfailed');
        return json(route, value, 201).catch(() => {});
      } finally { active--; }
    }
    if (method === 'GET' && url.pathname === '/api/messages/users') return json(route, { users: [recipient] });
    if (method === 'GET' && url.pathname === '/api/messages') return json(route, { conversations: [conversation], unread_count: 0 });
    if (method === 'GET' && url.pathname === '/api/messages/901') return json(route, { conversation });
    if (method === 'GET' && /^\/api\/channels\/[^/]+\/community-posts$/.test(url.pathname)) return json(route, { data: posts });
    if (method === 'GET' && /\/comments$/.test(url.pathname) && /^\/api\/(videos|community-posts)\//.test(url.pathname)) return json(route, { data: [parentComment], meta: { current_page: 1, last_page: 1, total: 1 } });
    if (method === 'GET' && url.pathname === '/api/live-room/attachment-replay') return json(route, { stream: { id: 7788, slug: 'attachment-replay', title: 'Attachment replay', status: 'ended', creator: { ...user.channel, user_id: user.id }, replay: { ...video, status: 'ready' }, stream_settings: { enable_chat: false }, appearance: {}, polls: [] }, access: { is_owner: true }, gifts: [], super_stickers: [] });
    if (method === 'POST' && (/\/comments$/.test(url.pathname) || /\/community-posts$/.test(url.pathname) || url.pathname === '/api/messages' || url.pathname === '/api/messages/901/messages')) {
      const body = request.postData() || '', ids = idsIn(body); publishes.push({ path: url.pathname, body, ids });
      const attachments = ids.map(id => records.get(id)?.attachment).filter(Boolean);
      if (/\/comments$/.test(url.pathname)) return json(route, { comment: { ...parentComment, id: 8801, body: field(body, 'body'), attachments, user: { id: user.id, name: user.name, channel: user.channel } } }, 201);
      if (/\/community-posts$/.test(url.pathname)) { const post = { ...seedPost, id: 7701, body: field(body, 'body'), type: field(body, 'type'), attachments, metadata: { title: field(body, 'event_title'), starts_at: field(body, 'event_starts_at') } }; posts = [post, ...posts]; return json(route, { post }, 201); }
      conversation = { ...conversation, messages: [{ id: 9901, own: true, sender: { id: user.id, name: user.name }, body: field(body, 'body'), attachments, time: 'Just now' }] };
      return json(route, { conversation }, 201);
    }
    return route.fallback();
  });
  return { ...session, user, video, short, puts, gets, deletes, publishes, records, attempts, releaseFirst, maxActive: () => maximum };
}

test('community images upload sequentially on selection, survive resize/failure and submit only ready UUIDs', async ({ browser }) => {
  const s = await attachmentSession(browser, { holdFirst: true, failName: 'retry.png' });
  try {
    await s.goto('/profile/community');
    const composer = s.page.locator('.community-composer');
    await composer.locator('textarea').fill('Text stays while images upload.');
    await composer.getByRole('button', { name: 'Image', exact: true }).click();
    await composer.locator('input[type=file]').setInputFiles([imageFile('first.png'), imageFile('retry.png'), imageFile('third.png')]);
    await expect(composer.locator('[data-upload-state="uploading"]')).toHaveCount(1);
    await expect(composer.locator('[data-upload-state="queued"]')).toHaveCount(2);
    await expect(composer.getByRole('button', { name: 'Post', exact: true })).toBeDisabled();
    await s.page.setViewportSize({ width: 390, height: 844 });
    await expect(composer.locator('textarea')).toHaveValue('Text stays while images upload.');
    s.releaseFirst();
    await expect(composer.locator('[data-upload-state="ready"]')).toHaveCount(2);
    await expect(composer.locator('[data-upload-state="error"]')).toHaveCount(1);
    expect(s.puts).toHaveLength(3); expect(s.maxActive()).toBe(1);
    await expect(composer.locator('textarea')).toHaveValue('Text stays while images upload.');
    const failedId = await composer.locator('[data-upload-state="error"]').getAttribute('data-image-upload-id');
    await composer.getByTestId('image-upload-retry').click();
    await expect(composer.locator('[data-upload-state="ready"]')).toHaveCount(3);
    expect(s.attempts.get(failedId!)).toBe(2);
    expect(s.puts.every(item => item.type === 'image/png' && item.bytes === pixel.length && item.purpose === 'community_post' && item.target === String(s.user.channel.id))).toBe(true);
    await expectContained(s.page, 'ready image drafts on a narrow phone');
    await composer.getByRole('button', { name: 'Post', exact: true }).click();
    await expect(composer.locator('[data-image-upload-id]')).toHaveCount(0);
    expect(s.publishes).toHaveLength(1); expect(s.publishes[0].ids).toHaveLength(3);
    expect(s.publishes[0].body).not.toContain('filename='); expect(s.publishes[0].body).not.toContain('name="images[]"');
    expect(s.deletes).toEqual([]); expect(s.errors).toEqual([]);
  } finally { s.releaseFirst(); await s.context.close(); }
});

test('removing queued and in-flight images cancels drafts without discarding text or starting queued PUTs', async ({ browser }) => {
  const s = await attachmentSession(browser, { holdFirst: true });
  try {
    await s.goto(`/watch/${s.video.slug}`);
    const composer = s.page.locator('.watch-comments-card > .watch-comment-form');
    await composer.locator('input:not([type=file])').fill('Keep this draft.');
    await composer.locator('input[type=file]').setInputFiles([imageFile('one.png'), imageFile('two.png')]);
    await expect(composer.locator('[data-upload-state="queued"]')).toHaveCount(1);
    await composer.locator('[data-upload-state="queued"]').getByTestId('image-upload-remove').click();
    await composer.locator('[data-upload-state="uploading"]').getByTestId('image-upload-remove').click();
    await expect(composer.locator('[data-image-upload-id]')).toHaveCount(0);
    await expect.poll(() => s.deletes.length).toBe(2);
    s.releaseFirst(); await s.page.waitForTimeout(100);
    expect(s.puts).toHaveLength(1);
    await expect(composer.locator('input:not([type=file])')).toHaveValue('Keep this draft.');
  } finally { s.releaseFirst(); await s.context.close(); }
});

test('lost successful PUT response recovers through GET without a duplicate transfer and unmount abandons draft', async ({ browser }) => {
  const s = await attachmentSession(browser, { loseResponse: true });
  try {
    await s.goto(`/watch/${s.video.slug}`);
    const composer = s.page.locator('.watch-comments-card > .watch-comment-form');
    await composer.locator('input[type=file]').setInputFiles(imageFile());
    await expect(composer.locator('[data-upload-state="ready"]')).toHaveCount(1);
    expect(s.puts).toHaveLength(1); expect(s.gets).toEqual([s.puts[0].id]);
    await s.page.locator('.topbar .menu-button').click();
    await s.page.locator('#website-sidebar').getByRole('button', { name: 'Home', exact: true }).click();
    await expect(s.page).toHaveURL(/\/$/);
    await expect.poll(() => s.deletes.includes(s.puts[0].id)).toBe(true);
  } finally { await s.context.close(); }
});

test('capacity retries are bounded and reuse the same UUID without publishing early', async ({ browser }) => {
  const s = await attachmentSession(browser, { busyAttempts: 2 });
  try {
    await s.goto(`/watch/${s.video.slug}`);
    const composer = s.page.locator('.watch-comments-card > .watch-comment-form');
    await composer.locator('input[type=file]').setInputFiles(imageFile());
    await expect(composer.locator('[data-upload-state="ready"]')).toHaveCount(1);
    expect(s.puts).toHaveLength(3); expect(new Set(s.puts.map(item => item.id)).size).toBe(1); expect(s.publishes).toEqual([]);
  } finally { await s.context.close(); }
});

for (const scenario of ['watch', 'watch-reply', 'short', 'short-reply', 'community-comment', 'community-reply', 'event', 'new-message', 'existing-message', 'replay', 'replay-reply']) {
  test(`${scenario} uploads on selection and binds IDs with the correct purpose and target`, async ({ browser }) => {
    const s = await attachmentSession(browser);
    try {
      let composer: Locator, input: Locator, submit: Locator;
      let purpose = 'video_comment', target = s.video.id;
      if (scenario.includes('message')) {
        purpose = 'private_message'; target = recipient.id;
        await s.goto(scenario === 'new-message' ? '/messages?compose=1' : '/messages/901');
        if (scenario === 'new-message') {
          composer = s.page.locator('.messages-compose-form');
          await expect(composer.getByRole('button', { name: 'Attach image', exact: true })).toBeDisabled();
          await s.page.locator('.messages-recipient-results').getByRole('button').first().click();
          await composer.locator('textarea').fill('Send with image.');
        } else { composer = s.page.locator('.private-chat-form'); await composer.locator('input:not([type=file])').fill('Send with image.'); }
        input = composer.locator('input[type=file]'); submit = composer.getByRole('button', { name: scenario === 'new-message' ? 'Send message' : 'Send', exact: true });
      } else if (scenario === 'event') {
        purpose = 'community_post'; target = s.user.channel.id;
        await s.goto('/profile/community'); composer = s.page.locator('.community-composer');
        await composer.getByRole('button', { name: 'Event', exact: true }).click();
        await composer.getByPlaceholder('Event title *').fill('Image event');
        await composer.locator('input[type=datetime-local]').first().fill('2027-01-01T12:00');
        input = composer.locator('.community-event-editor input[type=file]'); submit = composer.getByRole('button', { name: 'Post', exact: true });
      } else {
        if (scenario.startsWith('community')) { purpose = 'community_comment'; target = 7700; await s.goto(`/channel/${s.user.channel.slug}/community`); await s.page.locator('.community-post-actions').first().getByRole('button').nth(1).click(); }
        else if (scenario.startsWith('short')) { target = s.short.id; await s.goto(`/shorts/${s.short.slug}`); await s.page.getByRole('button', { name: 'Comments', exact: true }).click(); }
        else if (scenario.startsWith('replay')) { await s.goto('/live/attachment-replay'); await s.page.getByRole('tablist', { name: 'Replay sections' }).getByRole('button', { name: /^Comments/ }).click(); }
        else await s.goto(`/watch/${s.video.slug}`);
        if (scenario.endsWith('reply')) { await s.page.locator('.watch-comment').first().locator('.watch-reply-button').first().click(); composer = s.page.locator('.watch-inline-reply-form').first(); }
        else composer = s.page.locator('.watch-comments-card > .watch-comment-form').first();
        await composer.locator('input:not([type=file])').fill('Send with image.');
        input = composer.locator('input[type=file]'); submit = composer.getByRole('button', { name: scenario.endsWith('reply') ? 'Reply' : 'Publish', exact: true });
      }
      await input.setInputFiles(imageFile());
      await expect(composer.locator('[data-upload-state="ready"]')).toHaveCount(1);
      expect(s.puts).toHaveLength(1); expect(s.puts[0].purpose).toBe(purpose); expect(s.puts[0].target).toBe(String(target));
      await submit.click(); await expect.poll(() => s.publishes.length).toBe(1);
      expect(s.publishes[0].ids).toEqual([s.puts[0].id]); expect(s.publishes[0].body).not.toContain('filename='); expect(s.deletes).toEqual([]); expect(s.errors).toEqual([]);
    } finally { await s.context.close(); }
  });
}

test('attachment and gallery fallbacks advance locally and terminate in a neutral placeholder', async ({ browser }) => {
  const s = await attachmentSession(browser);
  try {
    let breakPrimary = false, breakFallback = false;
    await s.context.route('https://images.example.test/**', route => breakPrimary ? route.abort() : route.fulfill({ contentType: 'image/png', body: pixel }));
    await s.context.route('https://fallback.example.test/**', route => breakFallback ? route.abort() : route.fulfill({ contentType: 'image/png', body: pixel }));
    await s.goto(`/watch/${s.video.slug}`); const composer = s.page.locator('.watch-comments-card > .watch-comment-form');
    await composer.locator('input[type=file]').setInputFiles(imageFile()); await expect(composer.locator('[data-upload-state="ready"]')).toHaveCount(1);
    await composer.getByRole('button', { name: 'Publish', exact: true }).click();
    const image = s.page.locator('.comment-attachments-grid img[data-image-fallback]').first();
    await expect(image).toHaveAttribute('src', `https://images.example.test/${s.puts[0].id}.png`);
    await expect.poll(() => image.evaluate((element: HTMLImageElement) => element.complete && element.naturalWidth > 0)).toBe(true);
    const backendRequests: string[] = []; s.page.on('request', request => { if (new URL(request.url()).pathname.startsWith('/api/')) backendRequests.push(request.url()); });
    breakPrimary = true; await image.evaluate((element: HTMLImageElement) => { element.src += '?broken'; });
    await expect(image).toHaveAttribute('src', `https://fallback.example.test/${s.puts[0].id}.png`);
    await s.page.locator('.comment-attachment-image').first().click();
    const galleryImage = s.page.locator('.playhub-gallery-stage img');
    // A decoded primary may still be cached; force its load failure in this separate image instance.
    await galleryImage.evaluate((element: HTMLImageElement) => { if (element.src.includes('images.example.test')) element.src += '?gallery-broken'; });
    await expect(galleryImage).toHaveAttribute('src', `https://fallback.example.test/${s.puts[0].id}.png`);
    await s.page.getByRole('button', { name: 'Close gallery', exact: true }).click();
    breakFallback = true; await image.evaluate((element: HTMLImageElement) => { element.src += '?broken'; });
    await expect(s.page.locator('.comment-attachments-grid [data-image-fallback="empty"]').first()).toBeVisible(); expect(backendRequests).toEqual([]);
  } finally { await s.context.close(); }
});

test('German upload status and retry controls remain understandable without discarding draft text', async ({ browser }) => {
  const s = await attachmentSession(browser, { language: 'de_de', failName: 'retry.png', width: 390 });
  try {
    await s.goto(`/watch/${s.video.slug}`); const composer = s.page.locator('.watch-comments-card > .watch-comment-form');
    await composer.locator('input:not([type=file])').fill('Mein Text bleibt erhalten.');
    await composer.locator('input[type=file]').setInputFiles(imageFile('retry.png'));
    await expect(composer.locator('[data-upload-state="error"]')).toHaveCount(1);
    await expect(composer.getByTestId('image-upload-retry')).toHaveText('Wiederholen');
    await expect(composer.locator('input:not([type=file])')).toHaveValue('Mein Text bleibt erhalten.');
    await composer.getByTestId('image-upload-retry').click();
    await expect(composer.locator('[data-upload-state="ready"] [role=status]')).toHaveText('Bereit');
    await expectContained(s.page);
  } finally { await s.context.close(); }
});

test('independent message composers share one browser transfer queue', async ({ browser }) => {
  const s = await attachmentSession(browser, { holdFirst: true });
  try {
    await s.goto('/messages/901?compose=1');
    await s.page.locator('.messages-recipient-results').getByRole('button').first().click();
    const compose = s.page.locator('.messages-compose-form'), chat = s.page.locator('.private-chat-form');
    await compose.locator('input[type=file]').setInputFiles(imageFile('compose.png'));
    await chat.locator('input[type=file]').setInputFiles(imageFile('chat.png'));
    await expect(compose.locator('[data-upload-state="uploading"]')).toHaveCount(1);
    await expect(chat.locator('[data-upload-state="queued"]')).toHaveCount(1);
    expect(s.puts).toHaveLength(1);
    s.releaseFirst();
    await expect(compose.locator('[data-upload-state="ready"]')).toHaveCount(1);
    await expect(chat.locator('[data-upload-state="ready"]')).toHaveCount(1);
    expect(s.puts).toHaveLength(2); expect(s.maxActive()).toBe(1);
  } finally { s.releaseFirst(); await s.context.close(); }
});

test('account epoch changes cancel pending drafts without replaying their bytes as the next identity', async ({ browser }) => {
  const s = await attachmentSession(browser, { holdFirst: true });
  try {
    await s.goto(`/watch/${s.video.slug}`);
    const composer = s.page.locator('.watch-comments-card > .watch-comment-form');
    await composer.locator('input[type=file]').setInputFiles([imageFile('one.png'), imageFile('two.png')]);
    await expect(composer.locator('[data-upload-state="queued"]')).toHaveCount(1);
    await s.page.evaluate(() => localStorage.setItem('playhub_token', 'next-layout-test-identity'));
    await composer.locator('input:not([type=file])').fill('A new account must not send another account draft.');
    await expect(composer.locator('[data-image-upload-id]')).toHaveCount(0);
    s.releaseFirst(); await s.page.waitForTimeout(100);
    expect(s.puts).toHaveLength(1);
    expect(s.puts[0].authorization).toBe('Bearer layout-fixture-in-memory-identity');
  } finally { s.releaseFirst(); await s.context.close(); }
});

test('four-image and per-surface size limits stop excess file transfers before upload', async ({ browser }) => {
  const s = await attachmentSession(browser);
  try {
    await s.goto(`/watch/${s.video.slug}`);
    const composer = s.page.locator('.watch-comments-card > .watch-comment-form');
    await composer.locator('input[type=file]').setInputFiles({ name: 'large.png', mimeType: 'image/png', buffer: Buffer.concat([pixel, Buffer.alloc(6 * 1024 * 1024)]) });
    await expect(composer.locator('[data-image-upload-id]')).toHaveCount(0); expect(s.puts).toHaveLength(0);
    await composer.locator('input[type=file]').setInputFiles(Array.from({ length: 5 }, (_, index) => imageFile(`image-${index}.png`)));
    await expect(composer.locator('[data-upload-state="ready"]')).toHaveCount(4); expect(s.puts).toHaveLength(4);
    // Owner post forms allow the larger 8MiB image budget, still without a multipart file.
    await s.goto('/profile/community');
    const post = s.page.locator('.community-composer');
    await post.getByRole('button', { name: 'Image', exact: true }).click();
    await post.locator('input[type=file]').setInputFiles({ name: 'large-post.png', mimeType: 'image/png', buffer: Buffer.concat([pixel, Buffer.alloc(6 * 1024 * 1024)]) });
    await expect(post.locator('[data-upload-state="ready"]')).toHaveCount(1);
    expect(s.puts.at(-1)?.bytes).toBeGreaterThan(6 * 1024 * 1024);
    expect(s.puts.at(-1)?.purpose).toBe('community_post');
  } finally { await s.context.close(); }
});

const knownLostImage = { type: 'image', image_id: 'a1111111-1111-4111-8111-111111111111', url: null, image_candidates: [], name: 'known-lost.png', mime: 'image/png', size: pixel.length, width: 1, height: 1 };
const postFixtures = (user: any, attachments = [knownLostImage]) => [
  { id: 7700, type: 'image', body: 'Known lost picture', author: { ...user.channel, user_id: user.id }, attachments, likes_count: 0, comments_count: 1 },
  { id: 7701, type: 'event', body: 'Known lost event', author: { ...user.channel, user_id: user.id }, attachments: [knownLostImage], metadata: { title: 'Known lost event cover', starts_at: '2027-01-01T12:00:00Z' }, likes_count: 0, comments_count: 0 },
  { id: 7702, type: 'image', body: 'Locked image content', author: { ...user.channel, user_id: user.id }, attachments: [knownLostImage], sale: { is_paid: true, has_access: false, photo_count: 1, price_label: '$1.00', purchase_available: false }, likes_count: 0, comments_count: 0 },
];

for (const surface of ['video-comment', 'private-message', 'community', 'community-comment', 'search']) {
  test(`known-lost ${surface} images retain a neutral placeholder while paid media stays hidden`, async ({ browser }) => {
    const s = await attachmentSession(browser);
    try {
      const posts = postFixtures(s.user);
      await s.context.route('**/api/**', async route => {
        const url = new URL(route.request().url());
        if (route.request().method() !== 'GET') return route.fallback();
        let payload;
        if (/\/(videos|community-posts)\/[^/]+\/comments$/.test(url.pathname)) payload = { data: [{ ...parentComment, attachments: [knownLostImage] }] };
        else if (/\/channels\/[^/]+\/community-posts$/.test(url.pathname)) payload = { data: posts };
        else if (url.pathname === '/api/search') payload = { community: posts };
        else if (url.pathname === '/api/messages/901') payload = { conversation: { id: 901, name: recipient.name, recipient, messages: [{ id: 9901, own: true, sender: { id: s.user.id, name: s.user.name }, body: 'Known lost private image', attachments: [knownLostImage], time: 'Earlier' }] } };
        else return route.fallback();
        return route.fulfill({ contentType: 'application/json', body: JSON.stringify(payload) });
      });
      if (surface === 'video-comment') {
        await s.goto(`/watch/${s.video.slug}`);
        await expect(s.page.locator('.comment-attachments-grid [data-image-fallback="empty"]')).toBeVisible();
        await expect(s.page.locator('.comment-attachment-image')).toBeDisabled();
      } else if (surface === 'private-message') {
        await s.goto('/messages/901');
        await expect(s.page.locator('.private-chat-attachments [data-image-fallback="empty"]')).toBeVisible();
      } else if (surface === 'community' || surface === 'community-comment') {
        await s.goto(`/channel/${s.user.channel.slug}/community`);
        await expect(s.page.locator('#post-7700 .community-image-strip [data-image-fallback="empty"]')).toBeVisible();
        await expect(s.page.locator('#post-7701 .hosted-event-cover[data-image-fallback="empty"]')).toBeVisible();
        await expect(s.page.locator('#post-7702 [data-image-fallback]')).toHaveCount(0);
        if (surface === 'community-comment') {
          await s.page.locator('#post-7700 .community-post-actions').getByRole('button').nth(1).click();
          await expect(s.page.locator('.comment-attachments-grid [data-image-fallback="empty"]')).toBeVisible();
          await expect(s.page.locator('.comment-attachment-image')).toBeDisabled();
        } else {
          await s.page.locator('#post-7700 .community-image-tile').click();
          await expect(s.page.locator('.playhub-gallery-overlay')).toHaveCount(0);
        }
      } else {
        await s.goto('/search?q=known-lost');
        const picture = s.page.locator('.search-community-list .community-post-card').filter({ hasText: 'Known lost picture' });
        const event = s.page.locator('.search-community-list .community-post-card').filter({ hasText: 'Known lost event' });
        const locked = s.page.locator('.search-community-list .community-post-card').filter({ hasText: 'Locked image content' });
        await expect(picture.locator('[data-image-fallback="empty"]')).toBeVisible();
        await expect(event.locator('.hosted-event-cover[data-image-fallback="empty"]')).toBeVisible();
        await expect(locked.locator('[data-image-fallback]')).toHaveCount(0);
        await picture.locator('.community-image-tile').click();
        await expect(s.page.locator('.playhub-gallery-overlay')).toHaveCount(0);
      }
      expect(s.puts).toEqual([]); expect(s.gets).toEqual([]); expect(s.errors).toEqual([]);
    } finally { await s.context.close(); }
  });
}

for (const surface of ['comment', 'community']) {
  test(`${surface} gallery indexes remain correct when a lost image precedes healthy images`, async ({ browser }) => {
    const s = await attachmentSession(browser);
    try {
      const attachments = [knownLostImage, ...[1, 2].map(index => ({ ...knownLostImage, image_id: `b${index}111111-1111-4111-8111-111111111111`, name: `healthy-${index}.png`, url: `https://images.example.test/healthy-${index}.png`, image_candidates: [`https://images.example.test/healthy-${index}.png`] }))];
      await s.context.route('https://images.example.test/**', route => route.fulfill({ contentType: 'image/png', body: pixel }));
      await s.context.route('**/api/**', async route => {
        const url = new URL(route.request().url());
        if (route.request().method() !== 'GET') return route.fallback();
        let payload;
        if (/\/videos\/[^/]+\/comments$/.test(url.pathname)) payload = { data: [{ ...parentComment, attachments }] };
        else if (/\/channels\/[^/]+\/community-posts$/.test(url.pathname)) payload = { data: [postFixtures(s.user, attachments)[0]] };
        else return route.fallback();
        return route.fulfill({ contentType: 'application/json', body: JSON.stringify(payload) });
      });
      if (surface === 'comment') {
        await s.goto(`/watch/${s.video.slug}`);
        await expect(s.page.locator('.comment-attachment-image').first()).toBeDisabled();
        await s.page.locator('.comment-attachment-image:not(:disabled)').first().click();
      } else {
        await s.goto(`/channel/${s.user.channel.slug}/community`);
        await expect(s.page.locator('.community-image-tile [data-image-fallback="empty"]')).toHaveCount(1);
        await s.page.locator('.community-image-tile').filter({ has: s.page.locator('img') }).first().click();
      }
      await expect(s.page.locator('.playhub-gallery-stage img')).toHaveAttribute('src', 'https://images.example.test/healthy-1.png');
      await s.page.getByRole('button', { name: 'Next image', exact: true }).click();
      await expect(s.page.locator('.playhub-gallery-stage img')).toHaveAttribute('src', 'https://images.example.test/healthy-2.png');
      expect(s.errors).toEqual([]);
    } finally { await s.context.close(); }
  });
}
