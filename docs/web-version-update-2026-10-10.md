# PlayHub web version 0.9.13 update

PlayHub 0.9.13 is the web source update dated 10 October 2026, following 0.9.12 from 6 September 2026. It brings together fixes for cloud media delivery, player startup, Shorts, mobile layouts, website branding, signup roles, and content administration. The version identifies the application source and release metadata; production rollout and customer acceptance remain deployment steps.

The [16 October rebuild](web-0.9.13-hardening-2026-10-16.md) adds fixes for unavailable consent dialogs, S3 redirect CORS failures, repeated cloud metadata work and the signed updater. Use the rebuilt release and manual overlay described in that report.

The release history is in [CHANGELOG.md](../CHANGELOG.md), with the application version in [VERSION](../VERSION) and the frontend package version in [package.json](../package.json). The separate [React Native development history](../react-native-app/docs/version-history-and-documentation.md) covers the native application, device work, and mobile release gates.

## Changes for viewers and creators

### Playback startup and recovery

Early clicks on a video now use the native media element when it can start buffering, or wait for the provider's actual readiness event. This addresses intermittent startup errors caused by requesting Vidstack playback before the player can play. Pending requests have a bounded wait and are cancelled when their player is paused or removed during navigation. A retry can reload failed media and restore its previous position. Browser permission failures and real loading failures still produce an actionable error instead of being hidden or retried indefinitely.

The shared behavior applies to Watch and the standalone embed player through [mediaPlayback.js](../resources/js/mediaPlayback.js). Persistent playback also retains checks for connected player instances during navigation. The implementation does not guarantee playback when the origin is unavailable, a signed link has expired, the format is unsupported, or browser autoplay permission is denied.

Vidstack instances in the website and embed player omit the `crossOrigin` and `crossorigin` props. This avoids unnecessarily enabling anonymous CORS for ordinary browser media playback. Browser security rules still apply: HLS or DASH fetched by a JavaScript provider, remote manifests and segments, captions, or external hosts may require suitable origin headers. Removing a player attribute does not disable browser CORS. [Player source](../resources/js/main.jsx), [embed source](../resources/js/embed-player.jsx), and [CORS regression coverage](../tests/js/vidstackCors.test.js).

### Direct HLS imports and correct source types

A direct import hosted on the site's bare or `www` hostname now receives a playback URL using the canonical origin configured by `APP_URL`. For the reported `jahkno.tv` to `www.jahkno.tv` redirect, this lets the playlist begin at the canonical origin and lets relative segment URLs resolve there. The saved original import URL remains intact. Other hosts, unrelated subdomains, credentials in URLs, and unrelated explicit ports are excluded from this rewrite.

Media MIME detection reads the URL path separately from its query string and fragment, so a signed `.m3u8?…` source remains HLS. Watch, the persistent player, and embeds allow the HLS or DASH provider to determine whether a manifest is live, DVR, or on demand; ordinary video files remain on demand. Direct imports are serialized as their actual media source rather than as a local upload route. These changes are in [VideoImportSettings](../app/Support/VideoImportSettings.php), [Video](../app/Models/Video.php), [VideoResource](../app/Http/Resources/VideoResource.php), and [videoSource.js](../resources/js/videoSource.js).

Imported adaptive streams and application-generated renditions are separate capabilities. The upload processing pipeline produces progressive MP4 renditions; this update does not add automatic HLS or DASH transcoding, DRM, or HDR packaging.

### Private video delivery

Private and paid video media retain resource-specific authorization and expiring playback URLs. Authorized owners or administrators can receive a signed viewer claim for media requests that cannot attach the website's bearer token. Delivery checks the claimed viewer's current access, account restrictions, and private-profile ownership before releasing media. A warm image cache does not bypass those checks.

Cloud video delivery authorizes the application request and then redirects to a bounded provider-signed URL. Protected redirects use private, non-storable cache headers and do not extend an incoming signed application's expiry. Private objects do not fall back to a public CDN link if signing is unavailable. Local delivery keeps byte-range support for seeking. [MediaController](../app/Http/Controllers/MediaController.php), [VideoSales](../app/Support/VideoSales.php), and [cloud pipeline coverage](../tests/Feature/CloudMediaPipelineTest.php).

### Shorts classification and discovery

An ordinary uploaded video with a measured duration from 1 through 60 seconds is classified as a Short, including the reported 22-second upload and older records whose form saved the default standard-video flag. Processing verifies duration again. Automatically classified uploads do not require portrait dimensions; the explicit Upload Short flow still requires a portrait ratio from 0.45 through 0.8 and a maximum duration of 60 seconds.

Shorts have incompatible video ads and chapters disabled. Existing sale-enabled clips explicitly classified as standard videos remain standard to preserve buyer access. Provider imports keep their provider classification, and live replays remain replays. Discovery scopes and returned resources use the same classification rules. [Classification implementation](../app/Models/Video.php), [processing](../app/Support/CompleteVideoProcessing.php), and [22-second regression case](../tests/Feature/VideoShortClassificationTest.php).

The Shorts trending-tag sidebar now sums actual `views_count` values from videos in the loaded feed. Repeated, case-varied, or `#`-prefixed occurrences of the same tag on one video count once. Totals are ranked by views, with no fabricated multiplier or fallback tags. These are totals for the loaded feed, not a new site-wide analytics aggregation. [Tag aggregation](../resources/js/shortsTrendingTags.js) and [frontend coverage](../tests/js/customerMediaFrontend.test.js).

Shorts uploader avatars and names open the uploader's channel through the shared profile-navigation rules. The follow control remains its own action, and entries without a valid profile destination are disabled. The links have keyboard focus styling and narrow-screen layout support. [Uploader implementation](../resources/js/main.jsx), [styles](../resources/js/styles/shorts-uploader.css), and [browser coverage](../tests/Frontend/shorts-uploader.test.mjs).

### Comments and mobile layout

Mobile comment action menus render in the document body as scrollable sheets, keeping the Delete Comment action reachable when surrounding content clips or stacks above the menu. Delete and report confirmations also render in the document body, above the Shorts player and its overlays. Delete remains conditional on the permissions supplied for the comment; the confirmation explains that replies are removed with their parent. [Comment surfaces and confirmations](../resources/js/main.jsx) and [layering styles](../resources/js/styles.css).

Opening or dragging the mobile sidebar restores the header to its visible position and aligns the drawer beneath the 64-pixel top bar. Its height follows the dynamic viewport with safe-area padding, addressing the gap left when the scroll-hidden header and drawer used different positions. Small-screen headers and information pages also wrap long content within the viewport. [Responsive layout](../resources/js/styles/responsive-layout.css).

Creator Studio content tables reserve enough width before placing the summary rail alongside them. Intermediate layouts scroll the table within its container, and narrow screens turn rows into labeled cards while retaining selection, metrics, and row actions. The Admin content view has corresponding mobile selection and table treatment. These changes address both content-column overlap and narrow-screen control overflow. [Content layout styles](../resources/js/styles/content-responsive.css), [whole-page layout coverage](../tests/Frontend/studio-content-layout.test.mjs), and [narrow-screen coverage](../tests/Frontend/content-responsive.test.mjs).

## Website branding and administration

### Image logos and signup roles

Website Configuration accepts an empty logo text, a Show logo text switch, and a logo image size from 16 to 64 pixels. Administrators can present an image-only logo without restoring default text when saving another branding field. Public website configuration supplies the same values to the interface. [GeneralSettings](../app/Support/GeneralSettings.php) and [configuration coverage](../tests/Feature/Api/WebsiteConfigurationTest.php).

The Default signup role control offers User or Creator. The server applies the selected value to new local accounts and new social accounts, rejects staff roles, and ignores attempts by a registration client to select its own role. Returning social accounts and existing accounts keep their saved roles. Upload and Live access continue to depend on the separately configured publishing rules, restrictions, and allowlist. [AuthSettings](../app/Support/AuthSettings.php) and [registration implementation](../app/Http/Controllers/Api/AuthController.php).

Admin role changes now save canonical role values and make user rows, team rows, filters, and effective permissions agree. Setting a channel owner to User no longer displays Creator merely because the channel exists. Demotion clears legacy administrator flags while preserving unrelated preferences; both Edit User and Change Role follow the same behavior. Self-demotion safeguards remain in place. [Admin role implementation](../app/Http/Controllers/Api/AdminController.php) and [role regression coverage](../tests/Feature/Api/AdminUserRoleTest.php).

### Category deletion

Manage Categories supports confirmed deletion, including an empty category or the last category. The operation removes the category and marks its existing videos Uncategorized in one database transaction. It preserves the videos, media, engagement, and visibility. Unauthorized accounts cannot delete categories, and a failed category deletion rolls back the detachment. Removing every category leaves creators needing a new category before completing an upload that requires one. [Admin category implementation](../app/Http/Controllers/Api/AdminController.php), [API coverage](../tests/Feature/Api/AdminContentCategoryTest.php), and [dialog coverage](../tests/Frontend/admin-content-categories.test.mjs).

## Cloud storage and the 9 October customer fixes

### Private cloud avatars and banners

The reported Backblaze avatars and banners failed because browsers received unsigned object URLs for a private bucket and the object requests returned 401. For cloud storage without an explicitly configured public base URL, display assets now use the application's signed `/media/images` route. User Settings and channel profiles prefer the API's `avatar_url` and `cover_url`, preserving the complete signature rather than rebuilding an unsigned `/uploads` path. Local preview blobs still take priority while editing.

The route accepts only approved raster image paths under display-asset directories such as avatars, covers, branding, logos, banners, gifts, and stickers. It validates the signature before reading its cache. Protected video thumbnails and community, comment, and message images keep their own access routes. Display links are generated with a six-hour lifetime. An explicit public base URL remains an administrator's choice for intentionally public delivery. [MediaStorage](../app/Support/MediaStorage.php), [MediaController](../app/Http/Controllers/MediaController.php), and [cloud delivery coverage](../tests/Feature/CloudMediaDeliveryTest.php).

### Fewer Backblaze Class B operations

Cloud URL generation and video redirects no longer issue an extra object-existence HEAD request before signing. Signing a URL is local and uses zero Backblaze requests. A cold cloud-image cache fill performs one authenticated GET without separate existence, size, or MIME HEAD requests; MIME and image validity are checked from the downloaded bytes. Warm images use zero provider operations, including conditional public responses that return 304 through ETag or Last-Modified validation.

The private cache defaults to a 30-day maximum age, a 1 GiB storage target, and a 20 MiB maximum individual image. Daily scheduler pruning and opportunistic pruning when the hourly marker is due remove expired entries and then older used entries toward the target. Active locks can defer removal, so 1 GiB is a pruning target rather than a hard instantaneous disk ceiling. Oversized, malformed, HTML, and SVG objects are rejected. Successful application overwrites and deletions invalidate the corresponding cache entries; failed remote operations keep the previous cache usable. Changes made directly in the provider are not an application invalidation event and may remain cached until expiry or deliberate cache maintenance. [MediaImageCache](../app/Support/MediaImageCache.php), [scheduler](../routes/console.php), and [cache coverage](../tests/Feature/MediaImageCacheTest.php).

The customer must increase the exhausted Backblaze account's Class B transaction cap to restore uncached object reads. Cached images can remain available during a cap failure, but the application cannot download an uncached object when the provider refuses it. This update does not change billing, keys, bucket privacy, or provider CORS policy. Reducing extra operations does not remove normal costs for video or image reads.

### Supported storage configuration

Storage configuration is shared by media uploads, processing, playback, and deletion. The provider settings, registered adapters, and regression cases support the following combinations. Each deployed account still needs its own connection and playback acceptance checks.

| Provider | Supported configuration | Operational limits and requirements |
| --- | --- | --- |
| Default or Local | The configured PlayHub video disk; the standard public disk points to the application `uploads` directory. | Needs writable local media and staging paths, disk headroom, and protected delivery routes for private or paid content. Switching providers does not migrate existing objects. |
| Amazon S3 and compatible services | Access key and secret, optional session token, or the AWS default credential chain; bucket, region, optional endpoint and public base URL, selectable path-style addressing. | Uses the registered S3 adapter. Defaults to omitting ACL headers for bucket-policy storage. R2-style endpoints are configurable, but endpoint support is not proof that every compatible service has been accepted live. |
| Google Cloud Storage | HMAC access key and secret, bucket, S3/XML-compatible endpoint, region, path-style flag, and optional public base URL. | Uses S3 interoperability, not a Google JSON service-account file. Uniform-access storage uses omitted object ACLs. Private playback needs credentials that can generate the adapter's signed URLs. |
| Backblaze B2 | S3-compatible key ID and application key, bucket name, S3 endpoint and region, addressing and ACL flags, and optional public base URL. | An endpoint's B2 region is inferred when omitted; a conflicting configured region is rejected. A native bucket ID alone is insufficient for the S3 path. Account transaction caps and read/write/delete permissions still apply. |
| Azure Blob Storage | Account name and shared key with container and optional endpoint, or a validated account-key connection string; optional public base URL and object prefix. | Uses the Azure Blob adapter with a private container. SAS-only connection strings can authorize backend operations but cannot mint private temporary URLs in this adapter. Use shared-key signing for the current protected redirect path. Development-storage support is for a corresponding local emulator, not production acceptance. |

The common records prefix is normalized and applied once. Endpoint and public-base fields require HTTP or HTTPS URLs without embedded credentials, queries, or fragments. S3-compatible adapters stream reads, use bounded timeouts and retries, and request optional checksums only when required. The default ACL omission can be disabled for a service that needs legacy ACL behavior. [Provider configuration](../app/Support/MediaStorage.php), [S3 registration](../app/Providers/CloudStorageServiceProvider.php), [Azure validation](../app/Support/AzureStorageConfiguration.php), and [dependency declarations](../composer.json).

Admin storage responses mask saved secrets and return configured-state indicators. Blank secret fields preserve existing values; explicit secret clearing is validated so it cannot leave active credentials incomplete. Partial saves retain other settings, and replacing a configuration rebuilds the cached disk connection. The connection test uses the submitted configuration to write, verify, read, and delete a uniquely named test object; it checks cleanup and sanitizes errors. Testing is a real provider operation and does not silently save the tested settings. [Admin settings coverage](../tests/Feature/Api/AdminStorageSettingsTest.php).

Cloud capacity shown in the interface is administrator-configured capacity, not a queried provider quota or live free-space inventory. Membership storage quotas are application policy rather than provider-side limits. Changing a bucket, prefix, or provider requires an explicit object migration plan; existing database paths do not copy their media automatically.

## Shared platform capabilities

The current website retains the features delivered in 0.9.9 and 0.9.12: persistent and scroll miniplayers, standalone embeds, captions and chapters, provider imports, creator analytics and CSV exports, VAST and Google IMA advertising, paid videos and community photo sets, wallet tips, video boosts, creator earnings, withdrawal review, configurable SEO and localized pages and email templates, plugin management, and the transactional updater. Those are the preceding release baseline, not newly introduced features attributed to 0.9.13. See [the earlier changelog entries](../CHANGELOG.md).

The shared Laravel backend now also exposes the first-party `/api/v1` contract for native clients, including bootstrap capabilities, native authentication and session refresh, TV device authorization, content and playback sessions, resumable uploads, progress, and notifications. The existing web application continues using its versionless API. Native direct-to-cloud multipart upload is not implemented. [API client guide](api-v1.md) and [OpenAPI contract](openapi/playhub-v1.yaml).

Current source also includes FCM and APNs senders with a durable delivery outbox, queue dispatch, bounded retry, recovery, and registration/preference checks. Delivery defaults to disabled and bootstrap enables the capability only when the platform's credentials and required tables are ready. A separate push worker must consume the configured queue, which defaults to `push`; the ordinary `videos,default` worker alone does not consume it. Registration or source implementation does not establish actual device delivery. Earlier API/readiness prose that describes push sending as absent predates this implementation. [Push delivery service](../app/Services/Push/PushDeliveryService.php), [push configuration](../config/push.php), and [current feature gates](../app/Services/ApplicationFeatureService.php).

Web Live includes creator setup, webcam or RTMP source selection, viewers, chat, reactions, moderation, guests, polls, contributions, and recording/replay integration. Reachable LiveKit services, ingress for RTMP, egress for recording, queue workers, and scheduled reconciliation are operational dependencies. The 9 October connected acceptance records Android Live PASS and web-to-mobile Live parity COMPLETE on Samsung SM-G998B running Android 15 against the local `https://app.test/api/v1` backend and separate web accounts. This supersedes the blocked 3–4 October results retained in older milestone history. It covers real media in both directions, supported interaction and moderation flows, token revocation, recording, and physical replay playback. [Current Android acceptance](live-android-acceptance.md) and [Live parity](live-web-mobile-parity.md).

That pass drove focused corrections for starting an unchanged saved schedule after its due time, deterministic chat order for equal timestamps, stale ended-summary errors, and reconciliation after abnormal host loss. Its scope remains local physical-device acceptance with development tooling. It does not certify a production release, iOS, all Android GPUs, locked camera FPS, or release-build memory behavior. TURN UDP passed; TURN TCP/TLS were not configured, and optional RTMP ingress was not rerun. A development reload during capture left an old helper thread until the signed-out app was restarted; normal end, leave, and background cleanup passed. Production service setup remains covered separately in [server installation](live-server-installation.md).

## Validation status

The supplied 9 October customer-fix record reports 61 isolated PHP regression checks without a customer database or live B2 access, a successful Vite production build, and browser checks for avatars and banners, a page containing 13 avatars, direct HLS Watch and embed playback, and a private B2 Short seeking to 15 seconds without observed browser errors. Those are prior scoped results, not a new production audit or a fresh release-wide test run on 10 October.

The repository contains focused coverage for playback readiness, source inference, player CORS props, customer image URLs and tag totals, Shorts classification, roles, branding, cloud adapters and media lifecycle, image caching, category deletion, and responsive pages. Passing isolated or simulated-provider tests does not establish the credentials, transaction cap, CORS, scheduler, or playback behavior of every customer account.

Production acceptance should exercise both authorized and unauthorized private-video requests, a cold and warm display-image request, a conditional image response, direct HLS Watch and embed playback at the canonical hostname, Shorts upload/classification/seek behavior, a real role change followed by refresh, category deletion with a disposable category, and mobile comment menus, confirmations, drawer opening, and content cards. Use test-owned content and accounts for mutating checks.

## Deployment and customer actions

For a step-by-step update of an existing 0.9.12 installation, follow [the manual update instructions](manual-update-0.9.12-to-0.9.13.md). The accompanying `update_to_0.9.13/` folder separates deployable files from customer configuration and reference material.

1. Back up the database, media, saved administrative configuration, and the existing deployable release. Preserve environment secrets and customer storage settings through the update.
2. Deploy matching PHP source, locked Composer dependencies, frontend release metadata, and migrations on the supported PHP 8.3 or newer runtime. The current frontend declares React 19, Vite 7, and Vidstack 1.15; install from the lockfile using a Node runtime supported by the locked toolchain.
3. Build and deploy the complete frontend output and manifest together with `npm ci` and `npm run build`. The configured output is `build/` with a `/build/` base. The Vite configuration retains older chunks because open tabs can still request them; avoid removing those chunks during a live handover without a retention strategy. [Build configuration](../vite.config.js).
4. Apply required database migrations through the normal deployment process, clear or rebuild application caches as appropriate, and restart supervised queue workers after their active jobs finish. Keep the `videos,default` worker and the Laravel scheduler running; the image-cache daily prune needs the scheduler. If native push is enabled, supervise a worker that also consumes its configured push queue. [Worker and scheduler setup](live-server-installation.md#windows-worker-and-scheduler).
5. Ensure the application can write its private image-cache and media staging directories with sufficient local disk space. Keep private cache files outside public web delivery. Preserve protected video routes; a publicly reachable storage path must not bypass private or paid access.
6. Set `APP_URL` to the site's actual canonical HTTPS origin and confirm direct-import playlists and segments load there. Accept remote HLS or DASH origins individually where needed; do not broaden CORS or make a private bucket public to address the reported image issue.
7. In the customer's Backblaze account, increase the exhausted Class B cap, then check an uncached image and private video. Leave keys, billing controls beyond the intended cap change, bucket privacy, and provider CORS untouched unless a separately authorized change is required.
8. Review the supported provider fields in Admin storage settings and run the connection test only for the intended account. Then verify upload, processing, authorized playback, seeking, and deletion with disposable media; a connection-test success alone does not accept the whole media lifecycle.
9. Complete the deployment acceptance checks above and record the actual customer deployment date and result. Native signing, store submission, push delivery, iOS acceptance, and production Live service validation remain governed by their separate release checklists; the local 9 October Android Live pass does not replace them.

Rollback should restore a coherent application release, its frontend manifest/assets, and the corresponding database state using the deployment's tested recovery process. Preserve customer media and administrative settings; do not use provider switching, key rotation, bucket publication, or indiscriminate cache deletion as a substitute for diagnosing a rollout failure.
