import { test } from 'node:test';
import assert from 'node:assert/strict';
import { embedPermissions, httpEmbedUrl } from '../../resources/js/embedPermissions.js';

test('only supported player permissions are delegated, without sensor access', () => {
  const host = { featurePolicy: { features: () => ['autoplay', 'accelerometer', 'fullscreen', 'clipboard-write', 'gyroscope'] } };
  assert.equal(embedPermissions(host), 'autoplay; clipboard-write; fullscreen');
  assert.equal(embedPermissions({ permissionsPolicy: { features: () => ['encrypted-media', 'picture-in-picture'] } }), 'encrypted-media; picture-in-picture');
});

test('browsers without policy introspection get no unknown directives', () => {
  assert.equal(embedPermissions({}), '');
  assert.equal(embedPermissions(null), '');
  assert.equal(embedPermissions({ featurePolicy: { features: () => { throw new Error('Unsupported'); } } }), '');
  assert.equal(embedPermissions({ featurePolicy: { features: () => undefined } }), '');
});

test('embed navigation rejects local files and executable/data protocols', () => {
  for (const value of ['file:///', 'file:///tmp/video.html', 'javascript:alert(1)', 'data:text/html,test', '//example.test/embed', '', null, 'not a URL']) assert.equal(httpEmbedUrl(value), '', String(value));
  assert.equal(httpEmbedUrl('https://example.test/embed?v=1#start'), 'https://example.test/embed?v=1#start');
  assert.equal(httpEmbedUrl('http://example.test/embed'), 'http://example.test/embed');
});
