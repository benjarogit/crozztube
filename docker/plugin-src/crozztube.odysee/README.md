# Odysee Provider

Installable provider extension for Odysee video pages, permanent links and official embed/download links, including legacy `lbry.tv` links. Registering the plugin adds automatic URL detection and the manual provider option.

Public claim resolution uses Odysee's fixed LBRY API endpoint. The permanent stream name and full claim ID determine the official embed; abbreviated channel/video IDs are resolved before storage. Descriptions, tags, creator, thumbnail, duration and dimensions use claim metadata, supplemented by the shared yt-dlp engine when enabled. Available captions and chapters enter the existing import pipeline. The current yt-dlp LBRY extractor does not expose captions for every video; missing tracks are not generated.

Playback uses the shared Source Resolver where a native stream is available, otherwise the official Odysee iframe. The iframe controls its own captions/audio. No video files are downloaded or stored by this plugin.

Requires the core automatic-provider/caption import support and Source Resolver 0.7.2 or newer. Build with `bash tools/dev/package-plugin.sh crozztube.odysee` and install the resulting ZIP through plugin management.
