import assert from 'node:assert/strict';
import test from 'node:test';
import { seekWithoutStopping, visibleTranscript } from '../../resources/js/components/transcript-document.js';

test('search keeps real cue times and leaves plain text without them', () => {
  const cues = visibleTranscript({
    cues: [{ start_seconds: 90, text: 'Hello world.' }, { start_seconds: 93, text: 'Second line.' }],
    text: null,
  }, 'hello');
  assert.equal(cues.kind, 'cues');
  assert.deepEqual(cues.cues, [{ start_seconds: 90, text: 'Hello world.' }]);

  const plain = visibleTranscript({ cues: null, text: 'A note without a clock.\nAnother line.' }, 'note');
  assert.equal(plain.kind, 'text');
  assert.equal(plain.text, 'A note without a clock.');
  assert.equal(Object.hasOwn(plain, 'start_seconds'), false);

  const missed = visibleTranscript({ cues: null, text: 'A note without a clock.' }, 'missing');
  assert.equal(missed.kind, 'empty');
});

test('a transcript seek moves the playhead and leaves playback running or paused', () => {
  const playing = { currentTime: 4, duration: 120, paused: false, play() { this.played = true; } };
  assert.equal(seekWithoutStopping(playing, 90, true), true);
  assert.equal(playing.currentTime, 90);
  assert.equal(playing.paused, false);
  assert.equal(playing.played, undefined);

  const paused = { currentTime: 4, duration: 120, paused: true, play() { this.played = true; } };
  seekWithoutStopping(paused, 12, false);
  assert.equal(paused.currentTime, 12);
  assert.equal(paused.paused, true);
  assert.equal(paused.played, undefined);

  const interrupted = {
    currentTime: 4,
    duration: 120,
    paused: false,
    set currentTime(value) { this._time = value; this.paused = true; },
    get currentTime() { return this._time; },
    play() { this.paused = false; this.resumed = true; },
  };
  seekWithoutStopping(interrupted, 30, true);
  assert.equal(interrupted.currentTime, 30);
  assert.equal(interrupted.paused, false);
  assert.equal(interrupted.resumed, true);
});
