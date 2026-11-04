const writableStatuses = new Set(['draft', 'scheduled', 'waiting-for-source', 'live']);

export function liveStudioStreamWritable(stream) {
  return Number(stream?.id) > 0 && writableStatuses.has(stream?.status);
}

export function liveStudioDraftRequest(stream) {
  return liveStudioStreamWritable(stream)
    ? { path: `/live-streams/${stream.id}`, method: 'PUT' }
    : { path: '/live-streams', method: 'POST' };
}

export function liveStudioPreflightReady(stream, testedFingerprint, currentFingerprint) {
  return Boolean(liveStudioStreamWritable(stream) && stream.preflight_tested_at && stream.preflight?.ready
    && testedFingerprint && testedFingerprint === currentFingerprint);
}

export function liveStudioResponseMatches(currentStream, responseStream) {
  return liveStudioStreamWritable(currentStream) && liveStudioStreamWritable(responseStream)
    && Number(currentStream.id) === Number(responseStream.id);
}
