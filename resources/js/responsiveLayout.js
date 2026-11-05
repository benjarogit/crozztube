import { useSyncExternalStore } from 'react';

// Navigation mode is owned here. CSS consumes data-navigation-mode instead
// of independently deciding whether a sidebar takes up content space.
export const NAV_DOCK_MIN = 1280;
export const COMPACT_MAX = 860;
const dockQuery = `(min-width: ${NAV_DOCK_MIN}px)`;
const compactQuery = `(max-width: ${COMPACT_MAX}px)`;
const subscribe = (notify) => {
  const queries = [window.matchMedia(dockQuery), window.matchMedia(compactQuery)];
  queries.forEach(query => query.addEventListener('change', notify));
  return () => queries.forEach(query => query.removeEventListener('change', notify));
};
const snapshot = () => `${window.matchMedia(dockQuery).matches ? 'docked' : 'overlay'}:${window.matchMedia(compactQuery).matches ? 'compact' : 'regular'}`;

export function useResponsiveLayout() {
  const value = useSyncExternalStore(subscribe, snapshot, () => 'overlay:compact');
  return { overlayNavigation: value.startsWith('overlay:'), compact: value.endsWith(':compact') };
}

export function navigationIsOverlay() {
  return !window.matchMedia(dockQuery).matches;
}

export function sidebarWidth() {
  if (typeof document === 'undefined' || !document.body) return 264;
  const raw = getComputedStyle(document.documentElement).getPropertyValue('--sidebar-expanded').trim();
  if (/^\d+(?:\.\d+)?px$/.test(raw)) return parseFloat(raw) || 264;
  // Layout settings accept CSS lengths, including rem/clamp(). Resolve them
  // with the browser rather than treating e.g. 18rem as 18 CSS pixels.
  const probe = document.createElement('div');
  probe.style.cssText = 'position:fixed;visibility:hidden;pointer-events:none;width:var(--sidebar-expanded,264px);padding:0;border:0';
  document.body.append(probe);
  const value = probe.getBoundingClientRect().width;
  probe.remove();
  return Number.isFinite(value) && value > 0 ? value : 264;
}

export function drawerWidth() {
  return Math.min(320, Math.max(0, window.innerWidth - 32));
}
