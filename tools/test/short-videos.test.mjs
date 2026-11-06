import { test } from 'node:test';
import assert from 'node:assert/strict';
import { MAX_SHORT_SECONDS, qualifiesShort, isShortVideoRecord } from '../../resources/js/shortVideos.js';

test('Shorts are at most 60 seconds and strictly portrait, including exact boundaries', () => {
  assert.equal(MAX_SHORT_SECONDS, 60);
  for (const [duration, width, height, expected] of [[1, 1080, 1920, true], [60, 1080, 1920, true], [60.01, 1080, 1920, false], [61, 1080, 1920, false], [180, 1080, 1920, false], [60, 1080, 1080, false], [60, 1920, 1080, false], [0, 1080, 1920, false], [60, null, null, false], [60, 800, 900, true]]) {
    assert.equal(qualifiesShort(duration, width, height), expected, `${duration}s ${width}x${height}`);
  }
  assert.equal(qualifiesShort(30, 1080, 1920, 'live_replay'), false);
});

test('stale provider or queue flags cannot turn long, square or landscape videos into Shorts', () => {
  for (const video of [{ duration_seconds: 61 }, { duration_seconds: 30, video_width: 1080, video_height: 1080 }, { duration_seconds: 30, video_width: 1920, video_height: 1080 }, { source_type: 'live_replay' }]) assert.equal(isShortVideoRecord({ is_short: true, ...video }), false);
  assert.equal(isShortVideoRecord({ is_short: false, duration_seconds: 60, video_width: 1080, video_height: 1920 }), true);
  assert.equal(isShortVideoRecord({ slug: 'thin-trusted-short', is_short: true }), true);
  assert.equal(isShortVideoRecord({ slug: 'unknown' }), false);
});
