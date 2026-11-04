/** Vidstack React passes the provider detail as the first onProviderSetup argument. */
export function liveProviderVideo(provider) {
  const video = provider?.video;
  return video?.nodeName === 'VIDEO' ? video : null;
}
