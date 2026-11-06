import test from 'node:test';
import assert from 'node:assert/strict';
import { continueWatchingState } from '../../resources/js/viewerCards.js';
test('remaining time uses actual seconds, never a rounded progress estimate', () => {
  assert.deepEqual(continueWatchingState({ progress: 2, duration_seconds: 543, current_time: 16 }), { progress: 2, remaining: 527 });
  for (const video of [{ progress: 42 }, { progress: 42, duration_seconds: 543 }, { duration_seconds: 0, current_time: 1 }, { duration_seconds: 10, current_time: null }, { duration_seconds: 10, current_time: -1 }]) assert.equal(continueWatchingState(video).remaining, null);
});
test('completed and corrupt legacy progress cannot overflow the card', () => {
  assert.deepEqual(continueWatchingState({ progress: 150, duration_seconds: 60, current_time: 90 }), { progress: 100, remaining: 0 });
  assert.equal(continueWatchingState({ progress: -20 }).progress, 0);
  assert.equal(continueWatchingState({ progress: 'broken' }).progress, 0);
  assert.equal(continueWatchingState({ current_time: Infinity, duration_seconds: 30 }).remaining, null);
});
