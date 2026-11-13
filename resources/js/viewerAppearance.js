/** Choose the higher WCAG contrast for the configured opaque hex accent. */
export function accentInk(color) {
  let hex = String(color || '').trim().replace(/^#/, '');
  if (/^[\da-f]{3}$/i.test(hex)) hex = [...hex].map(value => value + value).join('');
  if (!/^[\da-f]{6}$/i.test(hex)) return '#fff';
  const linear = [0, 2, 4].map(start => {
    const value = parseInt(hex.slice(start, start + 2), 16) / 255;
    return value <= .04045 ? value / 12.92 : ((value + .055) / 1.055) ** 2.4;
  });
  const luminance = linear[0] * .2126 + linear[1] * .7152 + linear[2] * .0722;
  return (luminance + .05) / .05 >= 1.05 / (luminance + .05) ? '#000' : '#fff';
}

/** Theme-specific platform defaults, while keeping explicit admin/user accents. */
export function websiteAccent(website = {}, theme = 'light', preset = null) {
  const dark = theme === 'dark';
  const colors = website.design?.[dark ? 'darkColors' : 'colors'] || {};
  const paired = (website.design?.colorPaletteVersion || 0) >= 1;
  const primary = preset?.primary || (paired && website.defaultPrimaryColor === '#1a73b8'
    ? colors.primary : website.defaultPrimaryColor) || colors.primary || (dark ? '#01579b' : '#1a73b8');
  const primary2 = preset?.primary2 || (paired && website.defaultSecondaryColor === '#165f99'
    ? colors['primary-2'] : website.defaultSecondaryColor) || colors['primary-2'] || (dark ? '#014b84' : '#165f99');
  const lightSoft = paired && website.defaultPrimarySoftColor === '#e2edf7'
    ? colors['primary-soft'] : website.defaultPrimarySoftColor || colors['primary-soft'];
  const soft = preset ? (dark ? preset.softDark : preset.softLight)
    : (dark ? colors['primary-soft'] : lightSoft) || (dark ? '#1e3449' : '#e2edf7');
  return { primary, primary2, soft, ink: accentInk(primary), hoverInk: accentInk(primary2) };
}
