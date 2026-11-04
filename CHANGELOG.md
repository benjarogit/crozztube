# Changelog

All notable changes to PlayHub are documented in this file.

## Unreleased

### Changed

- Replaced the local sticker catalog with direct-client KLIPY trending/search and CDN media on the website and React Native app, including shared comment/reply pickers and paid Live Super Stickers.
- Replaced admin sticker upload/edit/delete with KLIPY configuration and connection testing. Added signed selection references, immutable historical media metadata, server-authoritative Live prices and receipt-safe share tracking.
- Removed local sticker imports and installer/package dependencies on `stickers/`. Gifts and historical contribution records remain separate and preserved.

## [0.9.13] - 2026-10-10

### Added

- Added image-only website branding and configurable logo image size.
- Added an administrator setting for the default User or Creator role at signup.
- Added category removal in Manage Content Categories, with existing videos retained and detached from the removed category.
- Added signed same-origin delivery for private cloud avatars, channel banners and other display images, with a bounded local image cache and conditional browser requests.
- Expanded cloud-storage configuration and validation across supported providers, including endpoint, region, public-delivery and private signed-access options where applicable.

### Changed

- Removed forced anonymous CORS mode from Vidstack media elements. Browser CORS remains required for cross-origin HLS playlists and segments, fetch-based resources and receiver playback where applicable.
- Removed extra cloud existence HEAD requests from playback redirects and URL generation; locally signing a playback URL no longer makes a provider API request.
- Normalized same-site direct media import playback URLs to the configured canonical hostname without rewriting the saved import URL.
- Replaced inflated Shorts trending-tag counts with actual view totals from matching listed Shorts, deduplicating each video's tags and excluding suggestion-only tags.

### Fixed

- Fixed mobile comment-delete controls and confirmation dialogs appearing underneath the Shorts overlay.
- Fixed authoritative role persistence in Admin Users and default signup role handling.
- Fixed intermittent early-click playback failures by waiting for provider readiness and making failed-media retries cancellable and position-preserving.
- Fixed private-video media requests losing authorized viewer context while retaining current ownership, account restrictions and paid-content checks.
- Fixed short uploaded videos being classified as standard videos while preserving paid-video, import and replay rules.
- Fixed profile/settings screens constructing local upload URLs instead of using API avatar and cover URLs.
- Fixed query-string and fragment handling in media MIME detection and automatic HLS live/on-demand detection in Watch, persistent and embed players.
- Fixed the mobile sidebar offset when the header is hidden and responsive Content table/summary overlap on smaller screens.
- Fixed the Shorts uploader link so it opens the creator's channel.

### Release notes

- Detailed scope, provider-specific storage notes, validation history, deployment actions and remaining limits are in [Web version update](docs/web-version-update-2026-10-10.md).
- Backblaze account transaction caps still require an account-side allowance appropriate for traffic. This update does not change bucket privacy, account billing, keys or provider CORS rules.
- This entry identifies the updated source version; it does not assert production deployment or new live-cloud acceptance.
- React Native remains version `1.0.0`, with its separate [version history and documentation](react-native-app/docs/version-history-and-documentation.md).

## [0.9.12] - 2026-09-06

### Added
- Added Google IMA HTML5 SDK as a per-ad Player Ads engine alongside the existing Generic VAST engine, including pre-roll, mid-roll, post-roll, Google-managed skipping, dynamic watch-page context, responsive/fullscreen rendering, configurable timeouts, no-fill recovery, navigation cleanup, and duplicate-request protection.
- Added a dedicated Creator Studio Analytics workspace with selectable reporting periods, audience activity charts, period comparisons, CSV export, audience quality metrics, content mix, publishing distribution, and creator-scoped top-content performance.
- Added protected Admin Users wallet credits with amount validation, an optional internal note, and an auditable completed wallet transaction.
- Added admin-managed custom `name` and `property` meta tags with validation, duplicate protection, reserved-tag protection, server rendering, and SPA route synchronization.
- Added an `Up next sidebar videos` website-ad placement for standard watch-page recommendations.
- Added playback progress bars to Shorts and Short indicators to video thumbnails and the Shorts navigation item.
- Added mandatory KNL and Envato license verification before an administrator can start a system update.

### Changed
- Redesigned the mobile bottom navigator as a compact, flat, shadow-free bar with visible `Upload` or `Sign in` center-action text while retaining the configured accent color.
- Changed watch-page Up Next to show six recommendations initially and six more per explicit `Load more` action, removed scroll-triggered loading, capped the recommendation panel to its final item, and changed comments to load additional pages only through `Load more comments`.
- Moved the automatic scroll miniplayer to the bottom-left, reduced it to 260px on desktop and 220px on mobile, delayed activation until at least 90% of the inline player is outside the viewport, and removed its volume slider while retaining click mute/unmute.
- Excluded Shorts from the homepage featured slider, Spotlight, and Trending sections.
- Refined Shorts comment reply controls with inline Reply/Cancel actions, no redundant replying-user avatar, appropriate left padding, and safe long-title wrapping.

### Fixed
- Fixed automatic scroll miniplayer flicker, unexpected dismissal, detached-player playback stops, quality-change pause handling, and overlap with the persistent navigation miniplayer during page transitions.
- Fixed VAST pre-rolls leaving the player invisible by retaining a stable pending-ad surface and ignoring detached player instances.
- Fixed Generic VAST and Google IMA click-throughs leaving an ad paused at its final seconds; content/ad playback now recovers when the viewer returns to the tab.
- Fixed Google IMA initialization, request, VAST, no-fill, media, manager, and timeout failures leaving the player in an indefinite loading state.
- Fixed emoji and sticker pickers rendering beneath the Shorts player.
- Fixed long Shorts titles breaking the Shorts-page layout.
- Fixed update-log text rendering black on the dark log background.
- Removed the Admin Dashboard shortcut from the website left sidebar.
- Fixed manually deployed versions being reported as older completed database updates.

### Release scope
- This clean-install release intentionally excludes separately distributed plugins and the demo-only read-only Admin Panel preview.
- No demo database records, uploaded media, runtime storage, environment configuration, installed plugin files, or plugin ZIP archives are included.

### Modified files
- `app/Http/Controllers/Api/AdminController.php`
- `app/Http/Controllers/Api/VideoController.php`
- `app/Http/Controllers/SpaController.php`
- `app/Http/Controllers/Api/SystemUpdateController.php`
- `app/Licensing/ProductLicenseVerifier.php`
- `app/Models/WebsiteAd.php`
- `app/Support/PlayerAdSettings.php`
- `app/Support/SeoSettings.php`
- `app/Updates/VersionRepository.php`
- `resources/js/googleImaClient.js`
- `resources/js/main.jsx`
- `resources/js/pages/AdminDashboard.jsx`
- `resources/js/styles/admin-dashboard.css`
- `resources/js/styles.css`
- `resources/views/app.blade.php`
- `routes/api.php`
- `tests/Feature/CreatorStudioAnalyticsTest.php`
- `tests/Feature/PaymentSettingsTest.php`
- `tests/Feature/SeoSitemapTest.php`
- `tests/Feature/WebsiteAdsTest.php`
- `tests/Unit/PlayerAdSettingsTest.php`
- `tests/Unit/ProductLicenseVerifierTest.php`
- `tests/js/googleImaClient.test.js`
- `tests/Feature/SystemUpdateApiTest.php`
- `VERSION`
- `package.json`
- `package-lock.json`
- `build/manifest.json`
- `build/assets/embed-player-3tdVvIJS.js`
- `build/assets/embed-player-OfOI0l1Y.css`
- `build/assets/errors-DYr9zTNT.js`
- `build/assets/livekit-client.esm-D8T2e6JJ.js`
- `build/assets/livekitRecordingTemplate-DBv5hY5L.js`
- `build/assets/main-8f90cgek.css`
- `build/assets/main-BekGum4w.js`
- `build/assets/prod-DRGQmvp4.js`
- `build/assets/srt-parser-eMd02cAG.js`
- `build/assets/ssa-parser-CgPZslX4.js`
- `build/assets/video-Cw3XztT4.js`
- `build/assets/video-DNCoRhji.css`
- `build/assets/vidstack-BmcqUMKx-CrIrBYEf.js`
- `build/assets/vidstack-C0fPJIzs-cl_EEhe2.js`
- `build/assets/vidstack-CHcsWfRV-WgP0qFIJ.js`
- `build/assets/vidstack-CQuJTPFJ-DuroxFiP.js`
- `build/assets/vidstack-D2vrrUMs-Dg3goroW.js`
- `build/assets/vidstack-Di0HObvT-B8rHq3Cx.js`
- `build/assets/vidstack-DiaLSJYx-DzHF3OBK.js`
- `build/assets/vidstack-Dy74GavT-LG92pB9m.js`
- `build/assets/vidstack-GkTzay8D-dHdgToQO.js`
- `CHANGELOG.md`

## [0.9.9] - 2026-09-05

### Added
- Added PlayHub Plugin API v1 with trusted PHP service providers, lifecycle hooks, dependency and compatibility checks, migrations, encrypted plugin settings, server-side actions and filters, and namespaced website and Admin Panel pages.
- Added an Admin Plugins workspace for ZIP installation and updates, configuration, diagnostics, enable and disable controls, and package removal.
- Added hardened plugin package handling with traversal, symlink, archive-size, file-count, file-type, and server-control-file protections plus rollback-safe package replacement.
- Added declared plugin CSS, JavaScript, module, image, font, JSON, and WebAssembly assets with a protected delivery route and browser hook runtime.
- Added a transactional system updater with signed manifests, preflight checks, protected paths, checksummed downloads, backups, maintenance mode, database recovery, interruption monitoring, and rollback.
- Added a standalone iframe-safe video player that embeds only the requested video rather than the complete video-details page.
- Added searchable `#hashtag` support and linked `@mention` profile navigation in video comments, community posts, and community comments.
- Added paid community photo sets with protected original media, wallet purchases, platform fees, tax support, permanent buyer access, and seller notifications.
- Added playlist selection from the creator's own playlists during video upload.
- Added a responsive mobile header search and fixed bottom navigator with animated active-item movement, guest login behavior, and Live/Profile availability rules.
- Added selected-currency wallet balances and an Earnings destination to the account menu.
- Added a default website language control to Admin Languages while preserving each visitor's saved language preference.
- Added enable and disable controls for website languages, with public catalog enforcement and default-language safeguards.
- Added community post removal permissions for post authors, community owners, platform moderators, and administrators.
- Added localized Admin Pages management for About, Contact, Developers, FAQ, Help, Refund, Privacy, Terms, and Cookie pages, with repeatable content sections, visibility controls, footer integration, search metadata, and sitemap filtering backed by atomic override storage.
- Added a localized Admin Email Templates workspace for editing, previewing, validating, and resetting every system-generated email across all installed languages, with documented template-specific placeholders and update-safe override storage.
- Added an automatic scroll miniplayer on video watch pages that floats actively playing videos below 50% visibility and restores them inline on pause, end, or return without activating persistent playback.
- Added persistent in-tab video playback across website navigation and reloads, with a responsive minimized Vidstack player, previous/next queue controls, seek, mute, full-player handoff, secure source refresh, and browser Media Session integration.
- Added a deterministic homepage and Shorts recommendation algorithm combining freshness, engagement quality, momentum, daily exploration, viewer affinities, completed-watch suppression, and creator/category diversity.
- Added namespaced Admin workspace registration to Plugin API v1, including authenticated requests, navigation, confirmation and alert dialogs, and deterministic mount cleanup.
- Added wallet-backed creator tips and donations to channel profiles and video details, with creator opt-out controls, contribution limits and fees, idempotent transfers, and creator notifications.
- Added admin-managed paid video sales with creator pricing, wallet purchases, platform commission, permanent buyer access, protected playback links, purchase notifications, and a Purchased library.
- Added a responsive branded HTML email template using the configured website name and accent colors, with plain-text alternatives across SMTP, SendGrid, and native mail delivery.
- Added a confirmed watch-page video deletion action for video creators, moderators, and administrators.
- Added admin-configurable VAT, GST, and Sales Tax for paid video purchases with buyer-visible totals and auditable tax records.
- Added `@mention` autocomplete, keyboard selection, linked mention rendering, and preference-aware notifications for video comments, community posts, and community comments.
- Added immutable signup IP and registration-source tracking with admin-only IP visibility in user management.
- Enabled feature-detected AirPlay and Google Cast controls for on-demand videos and live replays, including Safari media permission and resumable default-receiver sessions.
- Added an Admin Website Configuration control for choosing either a top progress transition that keeps the current page visible until the destination is ready or immediate navigation with destination-native loading placeholders.
- Added an admin withdrawal approval workflow with protected approve and reject decisions, auditable reviewer metadata, creator status notifications, and pending-request alerts for administrators and moderators.
- Added server-rendered Open Graph and X card previews for videos, Shorts, live streams, channels, community posts, and playlists, with original artwork and a branded 1200x630 fallback image.
- Added formatting-preserving video descriptions with Unicode and emoji-safe rendering plus clickable `m:ss` and `h:mm:ss` timestamps that seek the active player.
- Added automatic YouTube-style player chapters for valid timestamped descriptions, with a segmented seek bar, active song titles, chapter navigation, and cover previews powered by Vidstack's native track APIs.
- Added an Admin SEO & Sitemaps workspace with persistent search metadata, canonical origin and verification controls, per-content indexing policy, dynamic robots rules, route-synchronized Open Graph/X/JSON-LD metadata, and paginated database-backed page, video, Short, channel, playlist, image, and live-stream sitemaps.
- Added admin-controlled video chapters with CUE and timestamp-list import, duration-based unnamed segment review, per-video opt-in, validated chapter storage, and native Vidstack timeline playback.
- Added clickable community poll vote counts with a paginated voter dialog showing each voter's public profile and selected option.
- Added automatic YouTube chapter import from native player markers or timestamped descriptions, with editable Step 4 review and explicit opt-out.
- Added safe inline bold, italic, and combined emphasis rendering plus clickable external links in video descriptions while preserving original text layout.
- Added confirmed live-stream deletion to the Admin Live Streams row menu with active session, replay, related record, and media cleanup.
- Added automatic paginated loading near the end of the watch-page Up Next list while retaining the manual Show more control.
- Added provider-aware Shorts detection for imported videos using YouTube's Shorts signal or verified portrait metadata from other providers.
- Added admin-managed publishing access with upload and live role rules, a strict username allowlist, timed per-user restrictions, and profile-complete self-service creator upgrades for new User accounts.
- Added contextual Related and Up Next video recommendations that combine the current video's channel, category, language, and tags with each signed-in viewer's private watch history, likes, saved videos, and subscriptions.
- Added wallet-backed video boost campaigns with daily, per-click, and per-view billing, reserved budgets, paced relevance-aware placement in Home and Trending, qualified event deduplication, creator controls and reporting, promoted labels, and administrator-defined limits.
- Added a Creator Studio earnings cabinet with creator-scoped VAST, tip, video sale, and live revenue transactions, source filters, fees, balances, supporter and content details, pagination, and complete creator notifications for tips, purchases, and channel subscriptions.
- Added persisted desktop drag positioning to the minimized video player with viewport bounds, resize compatibility, keyboard movement, and automatic recovery after viewport changes.
- Added a persistent desktop theater mode to the video watch page with a native player toggle, keyboard controls, expanded playback, and responsive Up Next placement.

### Changed

- Added section navigation to Admin System Settings and changed settings to persist only from explicit Save actions.
- Changed the homepage intro slider to select from a ranked, diverse candidate pool with a random choice that remains stable for 20 minutes instead of changing on every navigation.
- Strengthened signup validation so names contain only Unicode letters and spaces, while usernames may contain supported digits and separators but cannot consist only of numbers or separators.
- Changed the default website theme from dark to light for fresh visitors and new users while preserving saved preferences.
- Redesigned Admin Dashboard statistics and increased compact-interface typography so application text does not render below 11.5px.
- Redesigned the edge-to-edge mobile bottom navigator with a raised central action and sliding active-state transition.
- Updated the mobile header to hide and reveal with the bottom navigator based on scroll direction.
- Updated the account dropdown to use full-width square rows, a more subtle scrollbar, and the selected-currency wallet balance.
- Updated all remaining legacy violet accents to follow the active UI accent color.
- Replaced engagement-event video payouts with completed VAST ad revenue sharing, using VAST CPM metadata or an administrator-defined fallback CPM, a configurable creator percentage, fractional earnings accrual, self-view exclusion, hourly impression deduplication, and idempotent wallet credits.
- Updated the video player context menu to use an 88% translucent black surface with compact corners, refined border and shadow styling, and a full-height accent rail.
- Increased Earnings page content typography for clearer revenue summaries, filters, transaction details, and pagination.
- Refined shared video previews with a minimal white play icon on a black circular background at 75% opacity.
- Refined the sidebar Go Live icon to an 18px base size with the intended visual scale.
- Replaced deployment-specific LiveKit, alert-email, and server-root examples with neutral installation placeholders.

### Fixed

- Fixed embedded-player source initialization and playback outside the PlayHub application shell.
- Fixed persistent miniplayer controls indicating playback after navigation when media was paused or detached.
- Fixed mobile header, player, action, and page layouts overflowing narrow viewports.
- Fixed mobile search icon sizing, responsive input collapse, and right-side alignment before Messages.
- Fixed community and comment mentions opening search instead of the mentioned channel profile.
- Fixed comment hashtags not linking to hashtag search results.
- Fixed video uploads lacking an owned-playlist selector.
- Fixed light-theme surfaces retaining dark native scrollbars.
- Fixed Admin Panel settings saving when a field received focus.
- Fixed Admin System Settings navigation resetting the left sidebar scroll position instead of preserving the active submenu in view.
- Fixed Admin adaptive-select menus rendering an oversized selected-option highlight.
- Restored destination-native loading placeholders for immediate-loading mode, kept the current page visible until completion in top-progress mode, and changed the startup fallback to "Loading...".
- Fixed video details using a default channel avatar when the creator has a custom avatar and showing the verified badge for unverified creators.
- Fixed live stream recordings reserving black replay tiles for connected viewers and invited guests who did not publish audio or video.
- Removed Shorts from the standard video player's Up Next suggestions and autoplay sequence.
- Made the login identifier label follow the enabled email, username, and phone methods, with all three enabled on fresh installations.
- Replaced hardcoded PlayHub branding in emails, SMS messages, public copy, sharing metadata, and visible interface text with the configured website name.
- Added configurable VAST pre-roll, timed mid-roll, and post-roll ad breaks to the watch-page player.
- Prevented Shorts from being configured or treated as paid videos.
- Fixed profile and search pages crashing while creator tip settings are loading.
- Fixed the left sidebar showing public playlists from other creators instead of only the signed-in creator's playlists.
- Fixed S3 video and thumbnail requests reusing cached redirects after their temporary cloud URLs had expired.
- Fixed iPhone playback stalls by prioritizing compatible processed renditions, supporting Safari MP4 suffix byte ranges, preserving direct tap-to-play activation, eagerly loading metadata, and constraining the mobile player to a stable 16:9 layout.
- Kept the current page visible during route loading and replaced it only after the destination content is ready when using the top loading bar.
- Fixed creator profile cover editing so selected images remain local previews until Save and Cancel leaves no uploaded file behind.
- Fixed comment composers, comments, replies, and Studio comment rows showing default avatars instead of user profile avatars.
- Added confirmation before deleting comments or replies from comment panels, Creator Studio, and comment moderation.
- Added confirmation before reporting comments or replies from comment panels and Creator Studio.
- Fixed the Creator Studio Content summary sidebar overlapping the content table on smaller desktop screens.
- Fixed single community images expanding beyond the post layout by constraining their width and viewport height without cropping them.
- Fixed uploads automatically selecting the first category; new local and imported videos now require an explicit category choice from a default "-" option.
- Improved YouTube provider imports to populate description and tags using consent-aware page metadata and structured fallbacks when the Data API response is unavailable.
- Fixed upload and video-edit tag inputs retaining committed tag text after rendering the tag chip.
- Fixed tag-chip remove buttons showing a nested hover block instead of a stable X hover state.
- Fixed upload-form required markers appearing between field labels and character counters instead of beside their labels.
- Removed the Not Interested action from the creator's own video menu.
- Preserved video-description line breaks while filtering hashtag tokens already represented in the separate video tag block.
- Fixed YouTube chapter imports when standard watch pages are rate-limited by using the first-party player metadata fallback and parsing native player markers from current response locations.
- Moved hashtags from imported YouTube descriptions into the Tags field while preserving description formatting and removing empty hashtag-only lines.
- Fixed the watch-page Autoplay alignment and increased the initial Up Next recommendation batch.
- Fixed fractional seconds appearing in duration labels and added a persistent, constrained desktop resize control to the minimized player.
- Fixed minimized-player resizing with window-wide drag tracking, persistent sizing, and a click-to-toggle compact or expanded size.
- Fixed long Creator Studio Content video titles expanding table rows by clamping them to three lines with an ellipsis.
- Fixed theater mode moving Up Next below the player; recommendations now remain in the right sidebar while the main navigation yields more player width.
- Fixed the header hamburger behavior in theater mode so it exits theater and opens the left navigation in one action.
- Fixed the automatic scroll mini-player so it only appears during active playback, suppresses its context menu, and closes without creating a persistent mini-player when navigating away.
- Smoothed automatic scroll mini-player transitions and prevented flicker when scrolling near the activation boundary.
- Fixed the miniplayer buffering indicator remaining visible during playback and pause dismissing the player instead of pausing it, while preserving playback state during the inline-to-mini transition.
- Fixed the Shorts detail player exceeding the viewport while preserving its 9:16 aspect ratio across desktop and mobile screens.
- Removed the LightGallery production-license warning by replacing the unlicensed runtime with PlayHub's native image viewer while preserving gallery controls and responsive navigation.
- Fixed shared video and Shorts previews by adding a centered play indicator to their Open Graph images on WhatsApp and other social platforms.
- Fixed Vidstack pause and navigation teardown races that caused detached-player exceptions on watch pages, Shorts, and the persistent miniplayer.
- Isolated livestream media and sticker test fixtures from installation files so the full test suite runs against temporary storage.

### Removed

- Removed the unlicensed LightGallery dependency and replaced it with PlayHub's native responsive image viewer.
- Removed the mobile header theme switcher.
- Removed the duplicate Go Live action from the header while retaining the sidebar action.
- Removed the sidebar user summary card.
- Removed the obsolete Admin System Settings collapse control.
- Removed the Product license heading and instructional paragraph from setup while preserving license verification.
- Removed automatic demo user and channel creation from the database seeder so new installations contain only the owner created during setup.

### Deprecated

- No public PlayHub APIs are deprecated in v0.9.9.
