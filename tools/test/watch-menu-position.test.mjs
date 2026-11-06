import test from 'node:test';
import assert from 'node:assert/strict';
import { watchMenuPosition } from '../../resources/js/watchMenuPosition.js';
test('the reported upward menu remains below the sticky header', () => {
  const bounds = { top: 80, bottom: 890, left: 12, right: 912 };
  const menu = watchMenuPosition({ top: 458, bottom: 502, right: 884 }, bounds, 620);
  assert.equal(menu.placement, 'down');
  assert.equal(menu.top, 510);
  assert.equal(menu.maxHeight, 380);
  assert.ok(menu.top + menu.maxHeight <= bounds.bottom);
});
test('flipping uses the real usable space above and constrains long menus', () => {
  const bounds = { top: 80, bottom: 890, left: 12, right: 912 };
  const menu = watchMenuPosition({ top: 700, bottom: 744, right: 884 }, bounds, 620);
  assert.equal(menu.placement, 'up');
  assert.equal(menu.top, 80);
  assert.equal(menu.maxHeight, 612);
  const narrow = watchMenuPosition({ top: 180, bottom: 224, right: 304 }, { top: 72, bottom: 348, left: 12, right: 308 }, 620);
  assert.equal(narrow.width, 296);
  assert.ok(narrow.top >= 72 && narrow.top + narrow.maxHeight <= 348);
  assert.equal(narrow.left, 12);
});
