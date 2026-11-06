import { test } from 'node:test';
import assert from 'node:assert/strict';
import { playMediaWhenReady } from '../../resources/js/mediaPlayback.js';

for (const [provider, type] of [['hls', 'application/x-mpegurl'], ['dash', 'application/dash+xml']]) {
  test(`${provider} waits for manifest setup before playing, preserving track discovery`, async () => {
    let nativeCalls = 0, playerCalls = 0, loads = 0;
    const media = Object.assign(new EventTarget(), { currentSrc: 'blob:loading', play: async () => { nativeCalls++; } });
    const player = { el: new EventTarget(), provider: { type: provider, media }, state: { canPlay: false, source: { type } },
      startLoading: () => { loads++; }, play: async () => { playerCalls++; } };
    const pending = playMediaWhenReady(player, { timeoutMs: 1000 });
    assert.equal(nativeCalls, 0);
    assert.equal(loads, 1);
    player.state.canPlay = true;
    player.el.dispatchEvent(new Event('can-play'));
    await pending;
    assert.equal(playerCalls, 1);
    assert.equal(nativeCalls, 0);
  });
}

test('progressive video can still buffer from the initial user gesture', async () => {
  let nativeCalls = 0;
  const media = Object.assign(new EventTarget(), { currentSrc: 'https://cdn.example.test/video.mp4', play: async () => { nativeCalls++; } });
  const player = { el: new EventTarget(), provider: { type: 'video', media }, state: { canPlay: false, source: { type: 'video/mp4' } } };
  await playMediaWhenReady(player, { timeoutMs: 1000 });
  assert.equal(nativeCalls, 1);
});
