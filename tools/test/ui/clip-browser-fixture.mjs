/** Synthetic local canvas only. Neither PHP nor Node receives the video bytes. */
export async function createClipFixture(page) {
  return page.evaluate(async () => {
    if (!MediaRecorder.isTypeSupported('video/mp4;codecs=avc1')) throw new Error('H.264 fixture recording is unavailable.');
    const canvas = document.querySelector('canvas'), ctx = canvas.getContext('2d');
    const stream = canvas.captureStream(10), chunks = [];
    const recorder = new MediaRecorder(stream, { mimeType: 'video/mp4;codecs=avc1', videoBitsPerSecond: 120000 });
    recorder.ondataavailable = event => { if (event.data.size) chunks.push(event.data); };
    const stopped = new Promise((resolve, reject) => { recorder.onstop = resolve; recorder.onerror = () => reject(new Error()); });
    let frame = 0;
    const paint = () => {
      ctx.fillStyle = '#172131'; ctx.fillRect(0, 0, 320, 180);
      ctx.fillStyle = '#fff'; ctx.font = '18px sans-serif';
      ctx.fillText('CrozzTube import test', 24, 76); ctx.fillText(`${(++frame / 10).toFixed(1)} / 5 seconds`, 24, 108);
    };
    paint(); recorder.start();
    const ticker = setInterval(paint, 100);
    await new Promise(resolve => setTimeout(resolve, 5200));
    recorder.stop(); clearInterval(ticker); await stopped; stream.getTracks().forEach(track => track.stop());
    globalThis.syntheticClip = new Blob(chunks, { type: 'video/mp4' });
    const video = document.createElement('video'), url = URL.createObjectURL(globalThis.syntheticClip);
    video.muted = true; video.src = url;
    await video.play(); await new Promise(resolve => setTimeout(resolve, 250));
    if (!video.videoWidth || !video.currentTime) throw new Error('Synthetic recording is not playable.');
    video.pause(); video.removeAttribute('src'); video.load(); URL.revokeObjectURL(url);
    const digest = await crypto.subtle.digest('SHA-256', await globalThis.syntheticClip.arrayBuffer());
    return { bytes: globalThis.syntheticClip.size, sha256: Array.from(new Uint8Array(digest), byte => byte.toString(16).padStart(2, '0')).join('') };
  });
}

export async function uploadClipFixture(page, grant) {
  return page.evaluate(async value => {
    const form = new FormData();
    for (const [key, field] of Object.entries(value.fields)) form.append(key, field);
    form.append('file', globalThis.syntheticClip, 'crozztube-synthetic-test.mp4');
    try { return (await fetch(value.url, { method: 'POST', body: form, credentials: 'omit', redirect: 'error' })).status; }
    catch { return 0; }
  }, grant);
}
