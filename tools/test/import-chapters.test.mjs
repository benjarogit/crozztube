import { test } from 'node:test';
import assert from 'node:assert/strict';
import { importChapterDraft } from '../../resources/js/importChapters.js';

const empty = { rows: [], disabled: true, initialized: false, source: 'generated' };
const metadata = { chapters: [{ start_time: 0, title: 'Intro' }, { start_time: 5, title: 'Short section' }, { start_time: 3661, title: 'Main' }], chapters_source: 'provider' };

for (const label of ['YouTube', 'Rumble', 'Odysee', 'BitChute']) {
  test(`${label} chapters select the checkbox and keep the real boundaries`, () => {
    const draft = importChapterDraft(empty, metadata, label);
    assert.equal(draft.disabled, false);
    assert.equal(draft.automatic, true);
    assert.deepEqual(draft.rows.map(row => row.start), ['0:00', '0:05', '1:01:01']);
    assert.equal(draft.fileName, `Imported from ${label}`);
  });
}

test('no chapters, a single boundary and invalid entries leave chapters off', () => {
  for (const chapters of [undefined, [], metadata.chapters.slice(0, 1), [{start_time: -1, title: 'Bad'}, {start_time: null, title: 'Bad'}, {start_time: 'bad', title: 'Bad'}]]) {
    const draft = importChapterDraft(empty, { chapters });
    assert.equal(draft.disabled, true);
    assert.deepEqual(draft.rows, []);
  }
});

test('a metadata retry can discover chapters after an empty first response', () => {
  const none = importChapterDraft(empty, {});
  assert.equal(importChapterDraft(none, metadata).disabled, false);
});

test('a metadata retry clears stale automatic chapters when none remain', () => {
  const detected = importChapterDraft(empty, metadata);
  const refreshed = importChapterDraft(detected, {});
  assert.equal(refreshed.disabled, true);
  assert.deepEqual(refreshed.rows, []);
});

test('repeated metadata preserves explicit disable, including before discovery', () => {
  for (const draft of [empty, importChapterDraft(empty, metadata)]) {
    const disabled = { ...draft, disabled: true, initialized: true, automatic: false };
    assert.equal(importChapterDraft(disabled, metadata), disabled);
  }
});

test('repeated metadata preserves manual edits and uploaded chapter files', () => {
  for (const source of ['provider', 'timestamps', 'cue', 'manual']) {
    const manual = { ...importChapterDraft(empty, metadata), source, automatic: false, rows: [{ start: '0:00', title: 'My title' }, { start: '0:20', title: 'My main' }] };
    assert.equal(importChapterDraft(manual, { ...metadata, chapters: [] }), manual);
  }
});

test('description timestamps keep their validation provenance', () => {
  assert.equal(importChapterDraft(empty, { ...metadata, chapters_source: 'timestamps' }).source, 'timestamps');
});
