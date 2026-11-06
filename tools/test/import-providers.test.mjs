import { test } from 'node:test';
import assert from 'node:assert/strict';
import { detectImportProvider, resolveImportProvider } from '../../resources/js/importProviders.js';

const providers = [
  { key: 'direct', enabled: true, domains: [] },
  { key: 'youtube', enabled: true, domains: ['youtube.com', 'youtu.be', 'youtube-nocookie.com'] },
  { key: 'rumble', enabled: true, domains: ['rumble.com'] },
  { key: 'odysee', enabled: true, domains: ['odysee.com', 'lbry.tv'] },
  { key: 'bitchute', enabled: true, domains: ['bitchute.com'] },
  { key: 'auf1', enabled: true, domains: ['auf1.tv'] },
  { key: 'vimeo', enabled: false, domains: ['vimeo.com'] },
];

for (const [url, expected] of [
  ['https://rumble.com/v7hpvpi-example.html?pri=test', 'rumble'],
  ['https://www.rumble.com/embed/v7fjis0/', 'rumble'],
  ['https://odysee.com/@garden:b/plants:e', 'odysee'],
  ['https://odysee.com/$/embed/plants%3Aabcdef', 'odysee'],
  ['https://lbry.tv/plants:abc', 'odysee'],
  ['https://www.bitchute.com/video/UGlrF9o9b-Q/', 'bitchute'],
  ['https://old.bitchute.com/embed/UGlrF9o9b-Q/', 'bitchute'],
  ['https://auf1.tv/nachrichten-auf1/fixture-video/?t=5', 'auf1'],
  ['https://www.auf1.tv/auf1-am-abend/fixture-video', 'auf1'],
  ['https://youtu.be/dQw4w9WgXcQ', 'youtube'],
  ['https://www.youtube.com/shorts/example', 'youtube'],
  ['https://www.youtube-nocookie.com/embed/example', 'youtube'],
  ['https://cdn.example.com/movie.mp4?token=1', 'direct'],
  ['https://another-ytdlp-site.example/watch/123', 'direct'],
  ['https://vimeo.com/123', 'vimeo'],
  ['https://rumble.com.evil.example/v123.html', 'direct'],
  ['https://auf1.tv.evil.example/nachrichten-auf1/fixture-video', 'direct'],
  ['https://www.youtube.com./watch?v=test', 'youtube'],
  ['ftp://example.com/video.mp4', undefined],
  ['https://user:secret@rumble.com/v123', undefined],
  ['not a URL', undefined],
  ['', undefined],
]) test(`detect ${url || 'empty input'}`, () => assert.equal(detectImportProvider(url, providers)?.key, expected));

test('automatic mode resolves the URL without a manual service selection', () => {
  assert.equal(resolveImportProvider({ provider: 'auto', url: 'https://rumble.com/v123-test.html' }, providers).key, 'rumble');
});
test('manual direct override survives recognized provider URLs', () => {
  assert.equal(resolveImportProvider({ provider: 'direct', url: 'https://rumble.com/v123-test.html' }, providers).key, 'direct');
});
test('manual provider is preserved while URL changes', () => {
  assert.equal(resolveImportProvider({ provider: 'rumble', url: 'https://youtube.com/watch?v=123' }, providers).key, 'rumble');
});
test('disabled known provider is exposed for a warning, rather than falling through to direct', () => {
  assert.equal(resolveImportProvider({ provider: 'auto', url: 'https://vimeo.com/123' }, providers).enabled, false);
});
