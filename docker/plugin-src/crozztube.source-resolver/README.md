# Source Resolver

Resolves remote video streams without storing video files. Version 0.9.0 retains HLS audio languages, chooses a confirmed original before regional/default dubs and exposes alternate tracks through the player's built-in **Settings → Audio → Track** menu. Imported text tracks remain independently selectable under **Settings → Captions**.

Version 0.9.1 keeps a provider's validated HLS master when every video rendition already contains audio and there are no independent language/role choices. An audio-only bitrate in such a master does not require synthetic A/V composition. This preserves original CDN relative init/segment URLs and byte-range responses (including PeerTube fragmented-MP4 delivery); it does not pass those streams through the Googlevideo-specific split-stream proxy. Truly split A/V and multiple audio languages retain the existing composite path. Explicit original-language selection is unchanged.

Mirror frames delegate only media permissions recognized by the current browser. Browsers without policy introspection retain the separate fullscreen attribute and do not receive speculative directives. Sensor permissions are unnecessary for video playback. Mirror navigation accepts HTTP(S) addresses only; local-file and executable/data URLs are rejected on both sides.

With the resolver enabled, provider imports wait for native playback; the original embed opens only through **Mirror**. The watch enhancer reloads a pending player when resolution succeeds, including when resolution finishes before its first status request. Failed resolution leaves an explicit retry/Mirror choice. Direct file imports retain their immediate playback path.

Original audio follows explicit yt-dlp/provider evidence (`language_preference >= 10`, original-track labels or original flags). With no original marker, the available provider order/default is retained; no language is assumed to be English or labeled Original without evidence. If a known original is unavailable in playable native formats, the original remains available through Mirror rather than silently playing a known dub. Progressive fallback also ranks original audio before resolution. Additional native audio switching currently requires HLS alternate renditions; provider iframes own their own audio and caption settings.

The synthetic HLS master keeps one rendition per language/role at its best available bitrate, including audio descriptions. Only its first/original track is the default; all other languages remain manually selectable. Video and audio segments continue streaming from the source through the existing passthrough routes. Expiration considers every retained audio URL.

The package migration adds nullable `audio_tracks` metadata. Existing records without language-aware extraction are refreshed on use before reuse. Disabling the plugin restores provider playback. Preserve administrator settings when upgrading and temporarily disable dependent provider plugins through normal plugin management.

Checks: `docker exec crozztube-web php tools/test/php/odysee-audio-checks.php`. Package: `bash tools/dev/package-plugin.sh crozztube.source-resolver`.

## Optional provider source discovery

A registered provider may supply `source_candidates(string $watchUrl): array`, returning `playback_candidates` (ordered media URL strings) and normalized `metadata` (same fields as the provider metadata callback). Providers own host/path/content-ID validation; the shared resolver additionally rejects non-HTTP transports, credentials and custom ports. It attempts at most three URLs with a 45-second extractor limit each, preserving successful provider metadata and existing format/audio selection, refresh locks and failure states. A registered provider without this callback continues using its original import URL through yt-dlp. Manual Direct imports of a site with this callback use its actual provider identity and Mirror.

The optional fourth `MediaSourceMetadata::embedUrl()` argument carries the original page URL to provider embed callbacks as a third callback argument. Existing ID-based callbacks are unchanged. AUF1 uses the source context to look up its official published embed while keeping its separate numeric content identity; it does not manufacture iframe URLs from slugs or content IDs.
