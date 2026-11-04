/** Aggregate views only for tags that occur on the loaded Shorts feed. */
export function shortsTagViewTotals(videos = []) {
  const totals = new Map();

  for (const video of Array.isArray(videos) ? videos : []) {
    const rawViews = Number(video?.views_count || 0);
    const views = Number.isFinite(rawViews) ? Math.max(0, Math.floor(rawViews)) : 0;
    const seen = new Set();

    for (const value of Array.isArray(video?.tags) ? video.tags : []) {
      if (typeof value !== 'string') continue;
      const tag = value.trim().replace(/^#+/, '').trim();
      const key = tag.toLowerCase();
      if (!key || seen.has(key)) continue;
      seen.add(key);

      const current = totals.get(key) || { tag, views: 0 };
      current.views += views;
      totals.set(key, current);
    }
  }

  return [...totals.values()]
    .sort((left, right) => right.views - left.views)
    .map(({ tag, views }) => [tag, views]);
}
