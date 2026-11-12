export function formatChapterTimestamp(value) {
  const seconds = Math.max(0, Math.floor(Number(value) || 0));
  const hours = Math.floor(seconds / 3600);
  const minutes = Math.floor((seconds % 3600) / 60);
  const remainder = String(seconds % 60).padStart(2, '0');
  return hours > 0 ? `${hours}:${String(minutes).padStart(2, '0')}:${remainder}` : `${minutes}:${remainder}`;
}

// Provider refreshes may replace automatic suggestions, never an explicit choice.
export function importChapterDraft(current, metadata, providerLabel = 'provider') {
  if (current.initialized && !current.automatic) return current;
  const rows = (Array.isArray(metadata?.chapters) ? metadata.chapters : [])
    .filter((chapter) => chapter?.start_time != null && Number.isFinite(Number(chapter.start_time)) && Number(chapter.start_time) >= 0)
    .map((chapter) => ({ start: formatChapterTimestamp(chapter.start_time), title: String(chapter.title || '').trim().slice(0, 160) }))
    .filter((chapter) => chapter.title)
    .slice(0, 100);
  const detected = rows.length >= 2;
  return {
    rows: detected ? rows : [],
    source: detected ? metadata.chapters_source || 'provider' : 'generated',
    fileName: detected ? `Imported from ${providerLabel}` : '',
    disabled: !detected,
    initialized: detected,
    automatic: true,
  };
}
