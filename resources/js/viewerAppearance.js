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
