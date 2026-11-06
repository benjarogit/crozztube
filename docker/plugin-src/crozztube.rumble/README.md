# CrozzTube Rumble Provider

Adds Rumble as a separately switchable provider for video URL imports, manual
mass imports, metadata previews and playback. No API key is needed. Videos remain
at their original host; this plugin never downloads or stores video files.

## Installation

Requires the provider extension hooks in this CrozzTube revision (0.9.13) and
CrozzTube Source Resolver **0.7.2 or newer**. Update the Source Resolver first,
then upload and enable this plugin's ZIP under Administration → Plugins.
ZIP packages must contain `plugin.json` at their root.

Once enabled, Rumble appears automatically in Administration → System Settings
→ Video Import and in the creator's import form. The existing global import
switch and Rumble's provider switch still apply. Disabling the plugin removes
Rumble from the available providers; existing imported records are retained.

## Supported URLs and playback

- Video page: `https://rumble.com/vdmum1-moose-the-dog-helps-girls-dig-a-snow-fort.html`
- Player: `https://rumble.com/embed/vb0ofn/`
- Publisher player: `https://rumble.com/embed/ufe9n.v5pv5f/`
- The `www` hostname and HTTP links are accepted and normalized to HTTPS.
- Channel, user, search and playlist URLs are rejected. Mass import accepts a
  list of individual video URLs; automatic discovery by keyword is not supported.

Rumble oEmbed supplies title, thumbnail, duration, dimensions and the actual
player ID. A watch-page ID must **never** be guessed to be its player ID.
The video page supplies the complete description and actual keywords/displayed
tags. Page requests use a bounded HTTPS session with cookies for ordinary
redirects. The public player metadata supplies available caption languages,
including automatically generated captions. Optional yt-dlp enrichment fills
missing data and chapters. Metadata is cached for ten minutes.

The import form defaults to automatic provider detection. Known disabled
providers stay disabled rather than falling through to the direct importer;
an explicit manual provider/direct selection remains available. The import API
also accepts an omitted provider or `auto`.

Caption discovery is shared with every yt-dlp provider, including YouTube:
manual and automatic WebVTT/SRT tracks are considered, with manual/original
languages preferred. On save, safe public caption URLs are copied into the
existing caption metadata as validated WebVTT (up to 16 languages, 1 MiB per
track, 4 MiB total and a 15-second transfer budget). No video files are copied.
Unavailable/invalid caption files do not block the video import. Stored captions
survive expiry of the original URLs and use the normal protected media routes.
The player lists the imported languages; a collapsible transcript is loaded
only when opened. The transcript comes from the available timed text; the
integration does not synthesize speech recognition when a provider has no text.

The shared Source Resolver attempts native MP4/HLS playback and renews expired
stream URLs. When no native source is available, the official Rumble iframe is
used. Rumble may reject yt-dlp requests with HTTP 403, or restrict individual
videos; the plugin does not bypass restrictions. If oEmbed and yt-dlp cannot
resolve a video page, the import returns an actionable error. An explicit embed
URL can still be imported without metadata. The provider fallback is also
available through the resolver's existing Mirror control.

## Provider extension contract

The backend filter `playhub.video_import.providers` receives the existing
provider definitions. A plugin may add a keyed definition containing the normal
public fields (`label`, `domains`, `help`, `requires_downloader`, `api_key_label`)
and these server-only fields:

- `enabled_by_default`: initial import switch, overridden by stored settings.
- `id_from_url(string $url): ?string`: pure parsing, no network access.
- `embed_url(string $id, string $parent): ?string`: official iframe URL.
- `metadata(string $url): array`: normalized metadata, including `provider_id`.

Callbacks are never exposed by the public settings endpoint. The import
controller validates provider enablement and allowed domains before calling
metadata. Plugin implementations must also validate the exact accepted paths
and hosts. Existing providers retain their built-in adapters.

## Checks

From the project root with the local stack running:

```sh
docker exec crozztube-web php tools/test/php/rumble-provider-checks.php
docker exec crozztube-web php tools/test/php/import-captions-checks.php
node --test tools/test/import-providers.test.mjs
```

The checks fake upstream metadata and queues and roll back database changes.
They cover provider registration, enable/disable behavior, page/player ID
mapping, unsafe URLs, oEmbed/yt-dlp fallback, metadata, mirrors, native sources
and the versioned playback API.

Upstream references:
[yt-dlp Rumble extractor](https://github.com/yt-dlp/yt-dlp/blob/master/yt_dlp/extractor/rumble.py),
[Rumble embedding guide](https://rumble.support/en/help/rumble-basics-how-to-embed-your-video).
