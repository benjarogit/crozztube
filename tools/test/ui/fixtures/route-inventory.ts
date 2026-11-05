/** Reachable SPA/server routes and explicitly disabled/empty states.
 * Dynamic slugs are resolved from the actual local catalogue, never hardcoded.
 * Add a row here whenever App routing or Admin navigation gains a new surface.
 */
export type RouteEntry = { name: string; path: string; area: 'public' | 'account' | 'creator' | 'admin' | 'plugin' | 'disabled'; guest?: boolean };
const rows = (area: RouteEntry['area'], paths: string[], guest = false): RouteEntry[] => paths.map(path => ({ name: path, path, area, guest }));

export const routeInventory: RouteEntry[] = [
  ...rows('public', ['/', '/explore', '/trending', '/shorts', '/communities', '/subscriptions', '/watch-later', '/playlists', '/search?q=video', '/channel/{channel}', '/watch/{video}', '/shorts/{short}', '/embed/{video}'], true),
  ...rows('public', ['/login', '/register', '/forgot-password', '/activate'], true),
  ...rows('public', ['/about', '/contact', '/developers', '/faq', '/support', '/refund', '/privacy', '/terms', '/cookies'], true),
  ...rows('account', ['/bookmarks', '/favorites', '/liked-videos', '/notifications', '/messages', '/messages/999999', '/plans', '/wallet', '/profile', '/settings']),
  ...rows('account', ['channel', 'privacy', 'security', 'notifications', 'playback', 'appearance', 'connected', 'billing'].map(section => `/settings?section=${section}`)),
  ...rows('creator', ['/studio', '/studio/content', '/studio/analytics', '/studio/growth', '/studio/earnings', '/studio/videos/{video}/edit', '/studio/videos/{video}/boost', '/upload', '/upload/shorts']),
  ...rows('admin', ['/admin', '/admin/dashboard', '/admin/users', '/admin/creators', '/admin/content', '/admin/gifts', '/admin/stickers', '/admin/ads', '/admin/reports', '/admin/monetization', '/admin/analytics', '/admin/support', '/admin/roles-permissions', '/admin/website-configuration', '/admin/pages', '/admin/seo', '/admin/languages', '/admin/email-templates', '/admin/system-updates', '/admin/system-settings']),
  ...rows('admin', ['general', 'registration', 'login', 'social-login', 'live-streaming', 'publishing-access', 'streaming-engine', 'docker-configuration', 'notifications', 'storage', 'uploads', 'video-import', 'ads', 'smtp', 'sms', 'moderation', 'integrations', 'maintenance'].map(section => `/admin/system-settings?section=${section}`)),
  ...rows('plugin', ['/admin/plugins']),
  ...rows('disabled', ['/live', '/live/now', '/live/upcoming', '/live/replays', '/go-live', '/admin/live-streams']),
  { name: 'unauthenticated account gate', path: '/settings', area: 'disabled', guest: true },
  { name: 'unauthenticated admin gate', path: '/admin', area: 'disabled', guest: true },
];

export const representativeRoutes = ['/', '/watch/{video}', '/shorts/{short}', '/search?q=video', '/settings', '/upload', '/studio/content', '/admin/users', '/admin/content', '/admin/plugins', '/live'];

export function resolveRoute(path: string, catalogue: { video: string; short: string; channel: string }): string {
  return path.replace('{video}', catalogue.video).replace('{short}', catalogue.short).replace('{channel}', catalogue.channel);
}
