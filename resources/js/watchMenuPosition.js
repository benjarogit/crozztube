/** Anchor a scrollable menu inside the usable area between fixed navigation. */
export function watchMenuPosition(trigger, viewport, naturalHeight) {
  const gap = 8;
  const width = Math.min(318, Math.max(0, viewport.right - viewport.left));
  const below = Math.max(0, viewport.bottom - trigger.bottom - gap);
  const above = Math.max(0, trigger.top - viewport.top - gap);
  const placement = below >= naturalHeight || below >= above ? 'down' : 'up';
  const maxHeight = Math.max(0, placement === 'up' ? above : below);
  const height = Math.min(naturalHeight, maxHeight);
  return {
    placement, width, maxHeight,
    left: Math.max(viewport.left, Math.min(trigger.right - width, viewport.right - width)),
    top: Math.max(viewport.top, placement === 'up' ? trigger.top - gap - height : trigger.bottom + gap),
  };
}
