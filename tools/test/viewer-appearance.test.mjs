import test from 'node:test';
import assert from 'node:assert/strict';
import { accentInk } from '../../resources/js/viewerAppearance.js';
test('gold and custom accent text keeps the more readable foreground in either theme', () => {
  assert.equal(accentInk('#b68a2d'), '#000');
  assert.equal(accentInk('#635bff'), '#fff');
  assert.equal(accentInk('#000'), '#fff');
  assert.equal(accentInk('#fff'), '#000');
  for (const value of ['#14b8a6', '#22c55e', '#f59e0b', '#ef4444', '#d946ef']) assert.equal(accentInk(value), '#000');
});
