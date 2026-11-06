# BitChute Provider

Registers BitChute video/embed URLs, including `www` and `old` host aliases, for automatic detection and manual provider selection. Channel, search and playlist URLs are not individual video imports. No API key is needed.

Metadata comes from the fixed public BitChute video API, supplemented by the shared yt-dlp engine. Descriptions, tags, creator, thumbnail and duration are retained. Available yt-dlp chapters/captions use the core import pipeline. The current BitChute extractor does not expose subtitles for every video; absent text is not synthesized. Native playback uses the shared Source Resolver; the official BitChute embed remains available as fallback. No video files are stored.

Requires Source Resolver 0.8.0 and the core chapter discovery/duplicate protection update. The same BitChute video ID is one import across watch links, embeds, tracking links and manual direct overrides. Provider restrictions still apply.

Build: `bash tools/dev/package-plugin.sh crozztube.bitchute`, then install/enable the ZIP through plugin management.
