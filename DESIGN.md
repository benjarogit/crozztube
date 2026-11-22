# CrozzTube interface contract

Use the configured product name, logo, font, accent palette and light/dark tokens. This is an existing video product, not a marketing landing page. Media screens let the previews lead; settings and administration use restrained, familiar controls.

- Navigation B: docked sidebar at 1280 CSS pixels and above, overlay drawer below. Retain the desktop collapse preference. Viewer bottom navigation remains through 860px. Administration has its own destinations with the same docking threshold.
- `responsiveLayout.js` owns navigation mode. Shells expose `data-navigation-mode`. The sidebar's measured/configured width and content inset agree; drawer state never overwrites the desktop preference.
- Content containers own column changes. Video grids have 240px minimum tracks or one full-width track on narrower screens. Content max-width is 1680px. Use 4/8px spacing increments and existing type tokens with readable rem sizes.
- Mobile home feature: preview, title limited to two lines, primary watch action. Omit settled empty trend rails. Controls and labels must survive long text and theme changes.
- Settings use a labeled compact section selector below 900px content width. Management lists turn into cards below 1000px content width. Comparison tables may scroll locally while identity/actions stay reachable.
- Modals and drawers have explicit focus, Escape, focus return, background isolation and viewport-bounded scrolling. Touch actions are at least 44px; reduced motion keeps clear state feedback.
- Admin connection tests, including external media services, use the shared AdminAlertDialog through alertAction. Dialog actions and settings actions share button typography, geometry and states. Editable fields show focus around the complete rounded field wrapper; nested inputs never add a second square outline. Keep configured accent contrast readable in hover and pressed states.
- Preserve mounted forms, media elements and their state through responsive changes. Resize must not reload playback or discard inputs.
- The sidebar uses the explicit compact SiteFooter variant: retain logo, description, configured links, language and both copyright texts in wrapping single-column content. Never put the full-page footer's 260px grid track inside the sidebar. Visible sidebar contents belong in overflow checks.
- Image selection shows a readable local preview and Waiting/Uploading/Ready/Error state with Retry/Remove actions. Disable publishing until every selected file is ready; never silently discard failed images. All attachment surfaces share ordered browser fallback and a neutral placeholder.

Impeccable skill 4.8.1, engine 0.1.19 is vendored under `.agents/skills/impeccable`. Pinned sources, local customizations and engine checksums are recorded in `harness/impeccable.lock.json`. Use audit/adapt/harden/polish playbooks against rendered evidence. Local engine caches and screenshot artifacts are not project source.

## Viewer refinement, 2026-11-06

- Home discovery order is Continue watching → Discover videos → category shelves → Shorts → Discover creators. Hide settled empty sections; generic catalog results must not claim personal recommendations or artificial ranks. Preserve configured ad slots and their sequence.
- Shared video/Shorts cards use real navigation links with separate channel/action buttons. Keep 16:9 video thumbnails, two-line titles and reserved action space. Hover/focus may reveal controls, but never load/play a stream. Playback begins through deliberate navigation or player controls; the hero's poster carousel is not a video preview.
- Continue watching displays remaining time only from valid duration and saved position seconds. Unknown duration uses a whole translated percentage message. Creator identity and subscription controls wrap rather than squeeze names.
- Watch DOM order is video/information → Up Next → comments. Desktop places Up Next beside the first column; resize changes CSS placement, never mounts another player/composer. Show explicit queue boundaries where a queue precedes catalog suggestions. Creator row keeps channel identity, Subscribe and Support/tip when enabled. Desktop/tablet actions are Like · Save · Share · Clip · Mirror · More; mobile adds Comments in a 3×2 grid. Save opens Watch later plus playlists; More keeps owner/admin actions, Report and Not interested.
- Existing chapter cues also appear in an optional disclosure below the description. Names, captions, track labels, tags and provider descriptions remain author content. No fake audio-language labels or empty track selectors; preserve the existing transcript search.
- Accent-filled viewer actions choose black or white text from the configured color's luminance; the platform uses the paired blue accent in both themes. Small secondary action labels use the foreground or link token.
- Watch action menus render in a body portal. Desktop placement uses the free area below the sticky header and constrains long menus with internal scrolling; touch retains the existing sheet. First/last actions, Escape, focus return and clicks inside the portal remain reachable. Search result tabs use the selected surface token, with a separate keyboard focus indicator.
- The navigation scroll lock belongs to the document root. While a drawer is open, clip body overflow rather than creating a second body scroll container with `hidden`; otherwise a previously scrolled sticky header moves above the viewport while the fixed drawer remains at the topbar inset. Closing the drawer preserves the prior page position, and its own link/footer region remains scrollable.
- Hero playback uses a light high-contrast action over posters and an inverted foreground surface on compact panel layouts. The paired blue accent is the hover/focus feedback. Spotlight actions are named spans inside their whole-card button; their style must not match every span, especially duration badges. Browse rows expose the same whole-row hover/focus feedback, with no stream previews or layout changes.
- Duration badges sit inside thumbnail bounds with small consistent insets, opaque dark contrast and tabular numerals. Compact rows retain smaller insets/type; thumbnail text styles must not override white badge text. Hero carousel duration stays separately in its controls.

## Small action consistency, 2026-11-09

- Studio heading links use one trailing arrow; the existing heading-link CSS supplies it for Recent Comments, without a second arrow in the label.
- Admin pagination and shared primary action buttons use the configured flat accent and its calculated foreground. Hover, pressed, keyboard focus and disabled states retain their meaning in both themes; pagination reaches 44px on coarse pointers.
- Watch sharing and link copying belong to the Share button's labeled menu. Save opens one picker for Watch later and playlists and returns focus to Save. Clip opens from the toolbar; its dialog mirrors the mounted player locally without server media proxying. More keeps only rare actions; owner/admin actions appear directly in the action menu without a second Manage view. Keep existing permission checks and destructive confirmation. Closing returns focus to the actual Share, Save, Clip, Mirror or More trigger. Menu changes and resizing preserve the mounted player and comment draft.

## Viewer quality correction, 2026-11-13

- Light and dark keep the same geometry, action order and semantic roles. Shared hover surfaces mix a small amount of foreground into the panel; selected surfaces mix the configured accent into that same panel. Text remains the foreground color. Blue is the platform action/indicator accent; links use a separately contrasted role. Respect administrator palette overrides.
- Mirror/original-source switching is a toolbar pill in its own reserved host, not a menu row. On mobile it takes a full-width row of its own. The source-resolver plugin contributes to that host and retains its error/recovery banner. The player and draft remain mounted while opening menus and resizing.
- A channel owner sees a plain Your channel label rather than a full-width disabled Subscribe button. Viewer subscription controls remain functional.
- Continue-watching management lives in a compact native disclosure with 44px controls, Escape/focus return and visible failures. The card prioritizes its title, channel, remaining time and progress.
- Time marks expose public like/dislike counts and changeable/removable votes. A creator may vote once on their own mark; that vote counts normally in week/season ranking and is final. Copying a private note shares only its position, never its text. A position view sits alongside ranking, long notes wrap, and the transcript stays an optional disclosure that never replaces the player.
- Shared accessibility work includes associated form labels, named community actions and one sorting value/chevron. Clip visibility explains the external Odysee-link limitation before submission.
- This is corrective work explicitly requested while step 8 is active. It does not accept series/seasons or start steps 9–12. Browser tests use isolated read-only identities, controlled vote responses, strict HTTPS and bounded media traffic. Backend vote and access checks use a rolled-back transaction.

## Paired WoltLab color reference, 2026-11-13

- Approved direction: the local WoltLab Suite at https://benjaro.localhost:8085/ supplies the neutral/blue family. Keep both modes and default to System. This supersedes the earlier gold action palette. Keep the logo, type, content and component geometry.
- Light: canvas #fafafa, panel #ffffff, navigation #ecf1f7, text #3a3a3d. Dark: canvas #1a1d21, panel #222529, navigation #1e2734, text #d1d2d3. Elevated surfaces, inputs, borders, links, focus and status colors have distinct paired roles in InterfacePalette. Dark means anthracite, not a navy or black replacement skin.
- WoltLab is a reference, not an exact stylesheet copy. Slightly stronger muted text, control edges and links keep small labels readable. Primary actions use #1a73b8/light and #01579b/dark; hover uses a darker paired blue. Links use a brighter dark-mode blue rather than the button fill. Body/secondary text targets 4.5:1; focus and input edges target 3:1. Automated pairs are selected checks, not a claim of complete WCAG conformance.
- Both the viewer and administration consume these roles. Menus, dialogs and account settings must not introduce hardcoded black surfaces; media itself retains its black backdrop. Shared actions calculate separate normal/hover foregrounds so optional account accents stay readable.
- New visitors follow the operating system, including changes while the page is open. Explicit saved device theme choices remain. Accounts without an explicit accent follow the platform; preserve deliberate account palettes. Choosing Default returns to the platform pairing.
- Legacy settings migration replaces only values matching the prior shipped defaults and carries a palette version into subsequent saves. Preserve custom role colors, radii, administrator copy and account preferences. The approved platform default changes to System once; a later administrator choice remains valid.
- This color correction leaves feature step 8 open. It does not add or accept series/seasons or advance the feature plan.

## Viewer refinement, 2026-11-16

- The base radius stays 4px: media, control and overlay surfaces use `--radius`, `--radius-lg` and `--radius-media`; pills, chips, badges and tags use `--radius-pill` at 2px. Surfaces nested inside an already rounded surface step inward with `--radius-inner` (`calc(var(--radius) - 1px)`) and `--radius-inner-2` (`- 2px`), the same pattern WoltLab uses for its global border radius. The tokens derive from the administrator value, so a changed base radius still produces consistent inner corners; `GeneralSettings::migrateRadiusScale()` rewrites only previously shipped defaults, so administrator-authored radii survive.
- Share and copy feedback is a transient snackbar, never an inline notice inside the page flow. The toolbar keeps its geometry while the message appears.
- Time-mark overlays stay compact: small type, short padding, at most two lines, and the timestamp in its own `time` element.
- The time-mark composer previews the real mounted player through the shared clip preview session, including audio, and restores the prior playback state when it closes. The saved position is captured when the dialog opens.
- Reporting a video, comment or profile always asks for one reason from a fixed list per type and an optional description capped at 500 characters. The server validates both again; legacy rows without a reason remain readable in admin.
- Interaction copy is authored in English through `t()` and reviewed per locale. Reference packs live in `tools/i18n/batches/`.
- This corrective work is explicitly requested while step 8 is active. It does not accept series/seasons or advance steps 9–12.

## Captcha and bot protection, 2026-11-16

- Six providers are registered: simple image, question, Google reCAPTCHA v2, Google reCAPTCHA v3, hCaptcha and Cloudflare Turnstile. The server rotates the enabled and configured providers by weight; local providers start at 1, external providers at 2. Disabled or incomplete providers are skipped instead of weakening the challenge.
- Registration and login each choose a scope: `off`, `risk` or `always`. Risk adds failed logins, failed checks, a filled honeypot, a submission faster than 1.2 seconds and a missing user agent, compared against the configured threshold.
- `POST /api/captcha/challenge` returns only the provider the server selected plus a one-time, server-bound token. Verification never trusts a client-sent provider, and a token cannot be reused or moved to another context. reCAPTCHA v3 runs invisibly; a low score escalates the next challenge to a visible provider on the server.
- External providers are contacted only while they are enabled and carry both a site key and a secret. They receive the visitor IP and the challenge response for verification. The simple image and question checks stay on this server. The privacy page discloses both cases.
- Native clients keep the legacy single-provider endpoints and understand only the image captcha and reCAPTCHA v2; the admin UI states this limit. If a scope requires a challenge but no usable provider is configured, the server fails open rather than locking every visitor out.
- Challenge UI copy is authored in English through `t()` and reviewed per locale; the reference batch is `tools/i18n/batches/captcha-challenge.json`.
- This work is requested while step 8 is active. It does not accept series/seasons or advance later plan steps.

## Series and seasons, 2026-11-16

- A series is an existing playlist with `kind = series`, not a parallel content type. Privacy, collaboration, ordering, votes and covers stay on the playlist; season and episode metadata lives on the `playlist_video` pivot (`season_number`, `episode_number`, `episode_title`, `episode_description`, `aired_at`). Converting a playlist keeps its videos and only adds structure.
- Episode numbers are authored per season. An explicit number always wins and is never rewritten. A missing number is synthesized from the current pivot position while skipping numbers that are already taken, so an editor can leave numbering empty without creating duplicates. The stored `sort_order` is rewritten to the effective episode order, and both the series page and the watch payload read that order.
- Every episode keeps its video title; the optional episode title is shown first and never replaces the video itself. Season headings appear once per group, and episode labels read `S1 E2` in the list, the watch chip and the next-episode card.
- `GET /api/playlists/{playlist}/next-episode` and the watch payload resolve the next and previous episode on the server through `VideoAccessService::canView()`. Private, scheduled, unlisted-when-forbidden, unapproved or otherwise blocked episodes are skipped instead of dead-ending playback. The owner and administrators keep their normal access; anonymous viewers get the first accessible episode.
- Playback priority is a pure client rule (`resources/js/seriesPlayback.js`): the personal queue wins, then the next episode, then the rest of the playlist, then discovery. Autoplay remains an explicit switch; while it is off, nothing is suggested automatically. A playlist without series metadata keeps its existing playlist behaviour.
- The playlist dialog switches format between playlist and series, exposes the series status (ongoing/completed) and lets the owner set season, episode and episode title per selected video. Copy is authored in English through `t()` and reviewed per locale; the reference batch is `tools/i18n/batches/series-and-seasons.json`.
- `tools/test/step-08-series.sh` runs the migration, `tools/test/php/series-checks.php` (schema, numbering, persisted order, resource, access-aware next/previous episode and routes, with all database writes rolled back) and the Node tests for the playback priority.

## Home and series refinement, 2026-11-16

- This is a bounded viewer pass on the existing tokens and components: same `--radius`/`--radius-lg`/`--radius-media` (4px) and `--radius-pill` (2px), same blue/neutral palette, same light/dark pairing, no new UI library, no second navigation. `resources/js/styles/viewer-experience.css` keeps the overrides; `main.jsx` keeps the component structure.
- The home page opens with one feature: a single-column hero with a two-line title clamp, one primary action and a ghost "Save for later" next to it. The spotlight stack and the side trending panel are gone; the hero remains part of Discover, so its video is not repeated as a shelf card.
- `Weiterschauen` follows the hero and now renders as stacked cards: preview, title, creator and remaining time, with the progress bar on the preview and the action menu as an overlay on it. One video appears once on the page.
- Nested surfaces (playlist format rows, episode rows, picker rows, the preview card and search suggestions) use `--radius-inner`/`--radius-inner-2` so an inner corner sits one/two pixels tighter than its container instead of repeating the outer 4px.
- Shelf order is Hero, Continue watching, Trending now, Discover videos, categories. Trending excludes the hero video and anything already in Continue watching; Discover excludes the hero, Trending and Continue watching. A category shelf needs at least four videos; empty or thin sections are not rendered at all.
- Sections are separated by a heading with a bottom rule and spacing, not by a bordered panel around every row. This removes the extra frame on the start page without changing content order, ad slots or feature switches.
- Creator suggestions exclude the signed-in user's own channel and disappear when no other channel remains; an empty "Discover creators" shelf is not rendered. The spec and fixtures record that condition instead of assuming the shelf always exists.
- Cards: the comment/like overlay was removed from the preview; duration, live badge and watch progress stay. Views and upload date remain in the meta line. The same compact duration badge and `progress-line` component are reused across grid, continue, trending and series rows instead of a second style or progress bar.
- Navigation stays one sidebar. It gains a `Library` group with Watch Later and Liked Videos. The existing playlist block below the groups is the single playlists entry: its heading links to `/playlists`, individual rows open `/playlists?id=…`, and the `+` opens the create dialog. Content, Analytics and Earnings are marked `studioOnly`, so they appear only while the path is under `/studio`; Creator Studio stays the main entry. The group label reuses the shipped translated `Library` key instead of inventing "Your library".
- Playlist meta separators belong to the following item (`span + span::before`), so a wrapped line never ends in a dangling bullet. Series episode rows keep duration and progress on a second line instead of clipping them.
- Series detail: season chips (`All`, `Season N`) filter the visible rows only. The primary action reads `Continue S1 E2` when `/continue-watching?limit=50` has an unfinished episode in the visible season, otherwise `Play all`. Episode rows show duration and `{percent}% watched`, with the progress bar in the thumbnail; episode titles keep two lines. `playAll` and `shuffle` use the visible season, while `playVideo` keeps the full series queue.
- Watch view: the next-episode card stays adjacent to the single autoplay switch, one switch only. Alignment and truncation changed; queue priority, access rules and the player itself did not.
- Verified with the existing UI checks plus before/after screenshots in light/dark, de/en and mobile/desktop. `tools/test/ui/layout-shots.mjs` covers home, video cards, navigation, series detail and watch; `tools/test/ui/design-shots.mjs` compares the pre-refinement and current start page; `tools/test/ui/series-shots.mjs` covers the series/watch variants. Screenshots stay outside the repository.
- This refinement is requested while step 8 is active. It does not accept series/seasons and does not advance later plan steps.

## Share and card consistency

- The watch toolbar offers direct link copying when native sharing is unavailable. Otherwise Share opens the existing menu with system sharing and link copying. A cancelled system share stays silent. A fallback message confirms copying only after the clipboard accepts the link.
- Catalog, home resume, Watch Later and live video grids share the same 240px minimum track and 24px by 16px row and column gaps. Cards share title typography. Compact lists, horizontal shelves and portrait Shorts keep their own layout.

## Shared snackbar

Transient notifications use one application snackbar with four tones, at most three items, a close button and hover or focus pause. Errors last longer and use alert semantics. The stack is centered and clears the mobile navigation. Form validation and actionable recovery messages remain next to the affected fields or controls.

## Radius scale

The current scale supersedes the previous flat corners. Small badges and compact controls start at 6px, buttons and menus at 8px, and larger cards, media and dialogs at 12px. Avatars, pills and progress bars use the full radius. Existing radius settings remain compatible through aliases. The settings upgrade changes shipped defaults and preserves nondefault values. Inset corners subtract the inset from the outer radius, with a zero minimum where the inset can exceed it. The channel avatar uses the same single-pixel neutral frame as other surfaces. Focus and stacked avatar outlines remain distinct.
