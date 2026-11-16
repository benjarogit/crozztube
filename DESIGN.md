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

- The radius scale is flat: media, control and overlay surfaces use `--radius`, `--radius-lg` and `--radius-media` at 4px; pills, chips, badges and tags use `--radius-pill` at 2px. `GeneralSettings::migrateRadiusScale()` rewrites only previously shipped defaults, so administrator-authored radii survive.
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
