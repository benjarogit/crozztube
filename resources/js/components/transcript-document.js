export function seekWithoutStopping(player, seconds, wasPlaying) {
  if (!player || typeof player !== 'object') return false;
  const target = Math.max(0, Number(seconds) || 0);
  if (!Number.isFinite(target)) return false;
  const duration = Number(player.duration || 0);
  player.currentTime = duration > 0 ? Math.min(target, Math.max(0, duration - 0.05)) : target;
  if (wasPlaying && player.paused && typeof player.play === 'function') player.play();
  return true;
}

export function visibleTranscript(document, query) {
  const needle = String(query || '').trim().toLocaleLowerCase();
  const matches = (value) => !needle || String(value || '').toLocaleLowerCase().includes(needle);
  const cues = Array.isArray(document?.cues)
    ? document.cues.filter((cue) => Number.isFinite(Number(cue?.start_seconds)) && matches(cue.text))
    : [];
  if (Array.isArray(document?.cues) && document.cues.length) return { kind: 'cues', cues };
  const text = typeof document?.text === 'string' ? document.text : '';
  if (!text.trim()) return { kind: 'empty', cues: [], text: '' };
  if (!needle) return { kind: 'text', cues: [], text };
  const lines = text.split('\n').filter((line) => matches(line));
  return lines.length ? { kind: 'text', cues: [], text: lines.join('\n') } : { kind: 'empty', cues: [], text: '' };
}

export function formatCueTime(seconds) {
  const total = Math.max(0, Math.floor(Number(seconds) || 0));
  const hours = Math.floor(total / 3600);
  const minutes = Math.floor((total % 3600) / 60);
  const remainder = total % 60;
  const clock = `${hours ? String(minutes).padStart(2, '0') : minutes}:${String(remainder).padStart(2, '0')}`;
  return hours ? `${hours}:${clock}` : clock;
}
