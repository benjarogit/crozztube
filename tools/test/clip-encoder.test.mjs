import test from 'node:test';
import assert from 'node:assert/strict';
import { clipDimensions, clipRange, clipEncodingSupport, clipEncoderLimits, ClipEncodingError } from '../../resources/js/clips/clipEncoder.js';

test('clip dimensions preserve aspect ratio and never exceed 720p', () => {
  assert.deepEqual(clipDimensions(1920, 1080), { width: 1280, height: 720 });
  assert.deepEqual(clipDimensions(3840, 2160), { width: 1280, height: 720 });
  assert.deepEqual(clipDimensions(720, 1280), { width: 404, height: 720 });
  assert.deepEqual(clipDimensions(640, 360), { width: 640, height: 360 });
});

test('clip range accepts only 5 to 60 seconds inside the source', () => {
  assert.deepEqual(clipRange(12.5, 17.5, 30), { start: 12.5, end: 17.5, seconds: 5 });
  assert.deepEqual(clipRange(0, 60, 60), { start: 0, end: 60, seconds: 60 });
  for (const range of [[0, 4.99, 30], [0, 60.01, 90], [-1, 5, 30], [20, 31, 30]]) {
    assert.throws(() => clipRange(...range), error => error instanceof ClipEncodingError && error.code === 'invalid_range');
  }
});

test('non-browser runtimes fail closed and expose approved limits', () => {
  assert.equal(clipEncodingSupport(), null);
  assert.deepEqual(clipEncoderLimits, {
    minSeconds: 5,
    maxSeconds: 60,
    maxWidth: 1280,
    maxHeight: 720,
    maxBytes: 67108864,
  });
});
