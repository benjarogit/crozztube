import test from 'node:test';
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { websiteAccent, accentInk } from '../../resources/js/viewerAppearance.js';

const php = code => JSON.parse(execFileSync('php', ['-r', `require 'app/Support/InterfacePalette.php'; ${code}`], { encoding: 'utf8' }));
const [light, dark] = php('echo json_encode([App\\Support\\InterfacePalette::colors(), App\\Support\\InterfacePalette::colors(true)]);');
const luminance = hex => {
  if (hex.length === 4) hex = '#' + [...hex.slice(1)].map(x => x + x).join('');
  const v = hex.slice(1).match(/../g).map(x => parseInt(x, 16) / 255).map(x => x <= .04045 ? x / 12.92 : ((x + .055) / 1.055) ** 2.4);
  return v[0] * .2126 + v[1] * .7152 + v[2] * .0722;
};
export const contrast = (a, b) => { const x = luminance(a), y = luminance(b); return (Math.max(x, y) + .05) / (Math.min(x, y) + .05); };

test('paired palette keeps readable text, links, status and filled action states', () => {
  for (const colors of [light, dark]) {
    for (const surface of ['bg', 'panel', 'panel-2', 'panel-3', 'navigation', 'input-bg', 'primary-soft']) {
      for (const role of ['text', 'muted', 'soft']) assert(contrast(colors[role], colors[surface]) >= 4.5, `${role}/${surface} ${colors.bg}`);
    }
    for (const role of ['link', 'success', 'danger', 'warning']) assert(contrast(colors[role], colors.panel) >= 4.5, role);
    for (const role of ['focus', 'input-border']) assert(contrast(colors[role], colors['input-bg']) >= 3, role);
    for (const role of ['primary', 'primary-2']) assert(contrast(accentInk(colors[role]), colors[role]) >= 4.5, role);
  }
});

test('legacy migration replaces shipped defaults, retains custom values/content and is idempotent', () => {
  const data = php(`$old = ['defaultThemeMode'=>'light','defaultPrimaryColor'=>'#d4b477','authPreview'=>['title'=>'Custom creator text'],'design'=>['colors'=>['bg'=>'#edf0f4','text'=>'#123456'],'darkColors'=>['bg'=>'#142237'],'radii'=>['radius'=>'6px']]]; $next=App\\Support\\InterfacePalette::migrate($old); echo json_encode([$next,App\\Support\\InterfacePalette::migrate($next)]);`);
  assert.deepEqual(data[0], data[1]);
  assert.equal(data[0].design.colors.bg, light.bg);
  assert.equal(data[0].design.darkColors.bg, dark.bg);
  assert.equal(data[0].design.colors.text, '#123456');
  assert.equal(data[0].defaultThemeMode, 'system');
  assert.equal(data[0].authPreview.title, 'Custom creator text');
  assert.equal(data[0].design.radii.radius, '6px');
  assert.equal(php(`echo json_encode(App\\Support\\InterfacePalette::migrate(['defaultThemeMode'=>'light','design'=>['colorPaletteVersion'=>1]]));`).defaultThemeMode, 'light');
});

test('platform accents follow theme while explicit administrator and account accents survive', () => {
  const website = { defaultPrimaryColor: light.primary, defaultSecondaryColor: light['primary-2'], defaultPrimarySoftColor: light['primary-soft'], design: { colorPaletteVersion: 1, colors: light, darkColors: dark } };
  assert.equal(websiteAccent(website, 'dark').primary, dark.primary);
  assert.equal(websiteAccent(website).primary, light.primary);
  assert.equal(websiteAccent({ ...website, design: { ...website.design, colors: { ...light, 'primary-soft': '#ffecf0' } } }).soft, '#ffecf0');
  assert.equal(websiteAccent({ ...website, defaultPrimaryColor: '#ad31e3' }, 'dark').primary, '#ad31e3');
  assert.equal(websiteAccent(website, 'dark', { primary: '#d4b477', primary2: '#bb9e69', softDark: '#302820' }).primary, '#d4b477');
  const blue = websiteAccent(website, 'dark', { primary: '#2f80ed', primary2: '#1d4ed8', softDark: '#112233' });
  assert(contrast(blue.ink, blue.primary) >= 4.5);
  assert(contrast(blue.hoverInk, blue.primary2) >= 4.5);
});
