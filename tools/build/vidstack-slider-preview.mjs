/**
 * Vidstack 1.15.6 queues SliderPreview measurements with requestAnimationFrame,
 * but disconnecting its ResizeObserver does not cancel an already queued frame.
 * When a responsive layout/error transition destroys its parent slider first,
 * disabled() reads destroyed signal props and throws. Ignore detached previews
 * before consulting their parent. Keep the fix local to that callback and fail
 * visibly if an upstream change needs review; never edit installed packages.
 */
export function guardDetachedSliderPreview(code, id) {
  if (!/\/@vidstack\/react\/(?:prod|dev)\/chunks\//.test(id) || !code.includes('var SliderPreview = class extends Component')) return null;
  const start = code.indexOf('var SliderPreview = class extends Component');
  const end = code.indexOf('function updateSliderPreviewPlacement', start);
  const region = code.slice(start, end);
  const needle = '#updatePlacement = animationFrameThrottle(() => {\n\t\tconst { disabled, orientation } = this.#slider;';
  if (end === -1 || region.split(needle).length !== 2) throw new Error('Review the Vidstack SliderPreview lifecycle guard against the installed dependency.');
  const guarded = region.replace(needle, '#updatePlacement = animationFrameThrottle(() => {\n\t\tif (!this.el?.isConnected) return;\n\t\tconst { disabled, orientation } = this.#slider;');
  return { code: code.slice(0, start) + guarded + code.slice(end), map: null };
}
export default function vidstackSliderPreviewGuard() {
  return { name: 'crozztube-vidstack-slider-preview-guard', enforce: 'pre', transform: guardDetachedSliderPreview };
}
