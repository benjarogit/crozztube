# Imported video identity and chapters

URL imports use registered provider definitions for automatic detection and manual overrides. BitChute joins YouTube, Rumble and Odysee through the same metadata/playback pipeline. Provider restrictions and existing import switches remain effective.

AUF1 is provided by `crozztube.auf1`. Its verified numeric content ID is independent of public slugs and PeerTube/Bunny hosting IDs. A manual Direct import of an AUF1 page retains that same identity and official Mirror. Its undocumented official website endpoint supplies metadata and real chapter boundaries; no captions or additional audio languages are invented. Source Resolver 0.9.0 supports optional provider source discovery before running yt-dlp against the published media candidates. See `docker/plugin-src/crozztube.auf1/README.md` for supported URL scope, checks and deployment.

Source Resolver 0.9.1 keeps the official provider HLS master when all video renditions already include audio and there are no independent language choices. AUF1's PeerTube media uses relative fragmented-MP4 URLs and byte ranges; routing this muxed master into the Googlevideo-only split-stream proxy caused a fresh resolver status to coexist with failed playback. Truly split A/V and multiple languages still use composition. A metadata/queue smoke alone is not playback evidence: run `docker exec crozztube-web php tools/test/php/auf1-live-smoke.php media VIDEO_ID` against an existing AUF1 import to decode two seconds of actual audio/video without storing media or creating videos. Browser playback must additionally confirm CORS, progression and playback controls.

## Chapters

Native yt-dlp/provider chapter lists take priority. Normalize start times to seconds, sort boundaries, drop invalid/out-of-range entries and duplicate starts, and retain up to 100 chapters. At least two usable chapters are needed. Native sections can be shorter than ten seconds and can begin after zero; no introduction is fabricated.

When native data is unavailable, parse clearly structured description timestamp lists, including leading/trailing times, bracketed times, bullets and Markdown timestamp links. Description lists must start at zero, contain at least two chapters and retain the existing ten-second spacing policy. Prose, URLs, invalid clocks and contradictory later sections are ignored. Invalid upstream chapters never reject an otherwise valid video import.

The import preview reports the detected count, fills the editable chapter list in Review and selects **Upload with chapters** when a usable list was found. Without detected chapters the checkbox stays off; enabling it manually creates editable draft segments. Duration-based drafts never replace detected import chapters. Provider chapters use `chapters_source: provider`; description lists use `timestamps`. Metadata refresh preserves edits and deliberate disabling. Explicitly disabling chapters records a `chapters_enabled` metadata override. Legacy imports with no chapter list or recorded choice can receive description chapters even when the old form implicitly disabled them.

The native player exposes imported sections in its chapter menu and timeline. Cross-origin provider iframes own their own chapter controls.

## Duplicate protection

Imports are unique across the platform by provider identity and normalized source URL. Two nullable SHA-256 keys on the video row have separate unique indexes. All model saves that change an import source recompute those keys. The migration retains historical records, assigning each pre-existing key to its oldest record; it does not delete old duplicates.

Provider IDs match watch, short, embed, tracking/start-time and manual direct variants where identity is known. Rumble uses its resolved player ID; Odysee uses the full claim ID, independent of the claim name. Case-sensitive IDs retain their case. Direct file URLs normalize query order/fragments/tracking while retaining functional query parameters. Different reuploads and different unknown hosting URLs are not compared by video content.

The preview warns about an existing entry and blocks advancing. The server returns `409` with `code: duplicate_video`, including on racing inserts; private video details/links are only returned to the owner. Public existing videos can be opened. Mass-import discovery uses the same identity comparison. Deleting a video releases its keys.

Checks: `docker exec crozztube-web php tools/test/php/chapters-duplicates-bitchute-checks.php`, plus the caption, provider, audio and frontend unit checks in `tools/test`.
