import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import { guardDetachedSliderPreview } from '../build/vidstack-slider-preview.mjs';
test('guard the real installed slider preview before reading destroyed parent signals', () => {
  const dir = 'node_modules/@vidstack/react/prod/chunks';
  const file = fs.readdirSync(dir).find(f => f.endsWith('.js') && fs.readFileSync(`${dir}/${f}`, 'utf8').includes('var SliderPreview = class extends Component'));
  assert.ok(file, 'Installed slider source must be reviewed after a dependency update.');
  const input = fs.readFileSync(`${dir}/${file}`, 'utf8');
  const result = guardDetachedSliderPreview(input, `/project/${dir}/${file}`);
  assert.ok(result);
  assert.equal(result.code.replace('\n\t\tif (!this.el?.isConnected) return;', ''), input);
  assert.equal(guardDetachedSliderPreview(input, '/project/resources/js/main.jsx'), null);
  assert.throws(() => guardDetachedSliderPreview('var SliderPreview = class extends Component {}', `/project/${dir}/${file}`), /Review the Vidstack/);
});
