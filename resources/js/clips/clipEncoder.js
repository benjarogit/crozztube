import {
  AudioBufferSource,
  BufferTarget,
  canEncodeAudio,
  canEncodeVideo,
  CanvasSource,
  Mp4OutputFormat,
  Output,
  Quality,
} from 'mediabunny';
import { registerAacEncoder } from '@mediabunny/aac-encoder';

const MIN_SECONDS = 5;
const MAX_SECONDS = 60;
const MAX_WIDTH = 1280;
const MAX_HEIGHT = 720;
const MAX_BYTES = 67108864;
let audioEncoderReady;

async function prepareEncoders() {
  if (!audioEncoderReady) {
    audioEncoderReady = (async () => {
      if (!await canEncodeVideo('avc')) {
        throw new ClipEncodingError('unsupported', 'This browser cannot encode H.264 video.');
      }
      if (!await canEncodeAudio('aac')) registerAacEncoder();
      if (!await canEncodeAudio('aac')) {
        throw new ClipEncodingError('unsupported', 'This browser cannot encode AAC audio.');
      }
    })();
  }
  return audioEncoderReady;
}

export class ClipEncodingError extends Error {
  constructor(code, message) {
    super(message);
    this.name = 'ClipEncodingError';
    this.code = code;
  }
}

export function clipDimensions(width, height) {
  if (!Number.isFinite(width) || !Number.isFinite(height) || width < 1 || height < 1) {
    throw new ClipEncodingError('invalid_source', 'The video dimensions are unavailable.');
  }
  const scale = Math.min(1, MAX_WIDTH / width, MAX_HEIGHT / height);
  return {
    width: Math.max(2, Math.floor(width * scale / 2) * 2),
    height: Math.max(2, Math.floor(height * scale / 2) * 2),
  };
}

export function clipRange(start, end, duration) {
  const from = Number(start);
  const to = Number(end);
  const sourceDuration = Number(duration);
  if (!Number.isFinite(from) || !Number.isFinite(to) || from < 0
    || to - from < MIN_SECONDS || to - from > MAX_SECONDS
    || !Number.isFinite(sourceDuration) || sourceDuration <= 0 || to > sourceDuration + 0.05) {
    throw new ClipEncodingError('invalid_range', 'Choose between 5 and 60 seconds within the video.');
  }
  return { start: from, end: to, seconds: to - from };
}

export function clipEncodingSupport() {
  if (typeof VideoEncoder === 'undefined' || typeof AudioEncoder === 'undefined'
    || typeof document === 'undefined' || typeof AudioContext === 'undefined'
    || typeof HTMLCanvasElement === 'undefined') {
    return null;
  }
  return { mimeType: 'video/mp4', videoCodec: 'avc', audioCodec: 'aac' };
}

function waitFor(target, success, failures, signal, timeout = 20000) {
  return new Promise((resolve, reject) => {
    let timer;
    const cleanup = () => {
      clearTimeout(timer);
      target.removeEventListener(success, done);
      failures.forEach(event => target.removeEventListener(event, failed));
      signal?.removeEventListener('abort', aborted);
    };
    const finish = callback => event => { cleanup(); callback(event); };
    const done = finish(resolve);
    const failed = finish(() => reject(new ClipEncodingError('source_unavailable', 'The video source could not be prepared.')));
    const aborted = finish(() => reject(new DOMException('The clip was canceled.', 'AbortError')));
    target.addEventListener(success, done, { once: true });
    failures.forEach(event => target.addEventListener(event, failed, { once: true }));
    signal?.addEventListener('abort', aborted, { once: true });
    timer = setTimeout(finish(() => reject(new ClipEncodingError('source_timeout', 'The video source took too long to respond.'))), timeout);
  });
}

export async function encodeClip({ sourceUrl, start, end, signal, onProgress = () => {} }) {
  const support = clipEncodingSupport();
  if (!support) throw new ClipEncodingError('unsupported', 'This browser cannot create compatible clips.');
  await prepareEncoders();
  if (typeof sourceUrl !== 'string' || !/^https?:\/\//.test(sourceUrl)) {
    throw new ClipEncodingError('invalid_source', 'A playable video source is required.');
  }

  const video = document.createElement('video');
  const canvas = document.createElement('canvas');
  const context = canvas.getContext('2d', { alpha: false, willReadFrequently: true });
  if (!context) throw new ClipEncodingError('unsupported', 'Canvas video processing is unavailable.');
  video.crossOrigin = 'anonymous';
  video.preload = 'auto';
  video.playsInline = true;
  video.src = sourceUrl;
  let audioContext;
  let audioProcessor;
  let output;
  let videoSource;
  let audioSource;
  let frame;
  let timer;
  let objectUrl;

  try {
    video.load();
    if (video.readyState < 1) await waitFor(video, 'loadedmetadata', ['error'], signal);
    const range = clipRange(start, end, video.duration);
    const dimensions = clipDimensions(video.videoWidth, video.videoHeight);
    canvas.width = dimensions.width;
    canvas.height = dimensions.height;
    video.currentTime = range.start;
    if (video.seeking || video.readyState < 2) await waitFor(video, 'seeked', ['error'], signal);

    try {
      context.drawImage(video, 0, 0, canvas.width, canvas.height);
      context.getImageData(0, 0, 1, 1);
    } catch {
      throw new ClipEncodingError('source_unreadable', 'This source cannot be read by the browser. Use a time link instead.');
    }

    audioContext = new AudioContext();
    const audioMix = audioContext.createGain();
    const silent = audioContext.createOscillator();
    const silence = audioContext.createGain();
    silence.gain.value = 0;
    silent.connect(silence).connect(audioMix);
    silent.start();
    try {
      audioContext.createMediaElementSource(video).connect(audioMix);
    } catch {
      // The silent AAC track remains available for sources without readable audio.
    }
    const audioChunks = [[], []];
    audioProcessor = audioContext.createScriptProcessor(4096, 2, 2);
    audioProcessor.onaudioprocess = event => {
      for (let channel = 0; channel < audioChunks.length; channel += 1) {
        audioChunks[channel].push(new Float32Array(event.inputBuffer.getChannelData(channel)));
      }
    };
    const mutedOutput = audioContext.createGain();
    mutedOutput.gain.value = 0;
    audioMix.connect(audioProcessor).connect(mutedOutput).connect(audioContext.destination);

    const target = new BufferTarget();
    output = new Output({
      format: new Mp4OutputFormat(),
      target,
    });
    videoSource = new CanvasSource(canvas, {
      codec: support.videoCodec,
      quality: new Quality({ bitrate: 2500000 }),
      latencyMode: 'quality',
    });
    audioSource = new AudioBufferSource({
      codec: support.audioCodec,
      quality: new Quality({ bitrate: 128000 }),
    });
    output.addVideoTrack(videoSource, { frameRate: 30 });
    output.addAudioTrack(audioSource);
    await output.start();

    const finished = new Promise((resolve, reject) => {
      const abort = () => reject(new DOMException('The clip was canceled.', 'AbortError'));
      signal?.addEventListener('abort', abort, { once: true });
      timer = setTimeout(
        () => reject(new ClipEncodingError('source_timeout', 'The video source took too long to respond.')),
        (range.seconds + 10) * 1000,
      );
      let nextFrameTimestamp = 0;
      const paint = async () => {
        if (signal?.aborted) return;
        try {
          context.drawImage(video, 0, 0, canvas.width, canvas.height);
        } catch {
          reject(new ClipEncodingError('source_unreadable', 'This source cannot be read by the browser. Use a time link instead.'));
          return;
        }
        const timestamp = Math.max(0, video.currentTime - range.start);
        if (timestamp >= nextFrameTimestamp) {
          try {
            await videoSource.add(nextFrameTimestamp, 1 / 30);
            nextFrameTimestamp += 1 / 30;
          } catch (error) {
            reject(error);
            return;
          }
        }
        onProgress(Math.min(1, Math.max(0, (video.currentTime - range.start) / range.seconds)));
        if (video.currentTime >= range.end || video.ended) {
          signal?.removeEventListener('abort', abort);
          clearTimeout(timer);
          resolve();
          return;
        }
        frame = requestAnimationFrame(paint);
      };
      frame = requestAnimationFrame(paint);
    });

    await audioContext.resume();
    await video.play();
    await finished;
    video.pause();
    const maximumAudioFrames = Math.floor(range.seconds * audioContext.sampleRate);
    const capturedAudioFrames = Math.min(
      maximumAudioFrames,
      audioChunks[0].reduce((total, chunk) => total + chunk.length, 0),
    );
    const audioBuffer = audioContext.createBuffer(2, capturedAudioFrames, audioContext.sampleRate);
    for (let channel = 0; channel < audioChunks.length; channel += 1) {
      const samples = audioBuffer.getChannelData(channel);
      let offset = 0;
      for (const chunk of audioChunks[channel]) {
        const remaining = capturedAudioFrames - offset;
        if (remaining <= 0) break;
        samples.set(chunk.subarray(0, remaining), offset);
        offset += Math.min(chunk.length, remaining);
      }
    }
    await audioSource.add(audioBuffer);
    videoSource.close();
    audioSource.close();
    await output.finalize();

    const buffer = target.buffer;
    if (!buffer) {
      throw new ClipEncodingError('output_empty', 'The browser did not produce a clip.');
    }
    const blob = new Blob([buffer], { type: support.mimeType });
    if (!blob.size || blob.size > MAX_BYTES) {
      throw new ClipEncodingError('output_size', 'The encoded clip exceeds the 64 MiB limit.');
    }
    const digest = await crypto.subtle.digest('SHA-256', buffer);
    const sha256 = Array.from(new Uint8Array(digest), byte => byte.toString(16).padStart(2, '0')).join('');
    objectUrl = URL.createObjectURL(blob);

    return {
      blob,
      objectUrl,
      bytes: blob.size,
      sha256,
      duration: range.seconds,
      mimeType: support.mimeType,
      ...dimensions,
    };
  } finally {
    clearTimeout(timer);
    cancelAnimationFrame(frame);
    video.pause();
    video.removeAttribute('src');
    video.load();
    if (audioProcessor) audioProcessor.onaudioprocess = null;
    videoSource?.close();
    audioSource?.close();
    if (output?.state === 'started') await output.cancel();
    await audioContext?.close().catch(() => {});
    if (signal?.aborted && objectUrl) URL.revokeObjectURL(objectUrl);
  }
}

export const clipEncoderLimits = Object.freeze({
  minSeconds: MIN_SECONDS,
  maxSeconds: MAX_SECONDS,
  maxWidth: MAX_WIDTH,
  maxHeight: MAX_HEIGHT,
  maxBytes: MAX_BYTES,
});
