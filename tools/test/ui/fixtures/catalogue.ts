import { applicationJson } from './readonly-layout';

let cached: { video: string; short: string; channel: string } | undefined;

export function catalogue() {
  if (!cached) {
    const payload = applicationJson('/api/videos?per_page=50');
    const videos = Array.isArray(payload) ? payload : payload.data || [];
    const video = videos.find((item: any) => !item.is_short) || videos[0];
    const short = videos.find((item: any) => item.is_short);
    const user = applicationJson('/api/auth/me').user;
    if (!video?.slug || !short?.slug || !user?.channel?.slug) {
      throw new Error('Responsive tests require one video, one measured short and a test user channel.');
    }
    cached = { video: video.slug, short: short.slug, channel: user.channel.slug };
  }
  return cached;
}
