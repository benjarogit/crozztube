# CrozzTube product backlog

Status: 12 November 2026
Owner: Benny
Purpose: single maintained product backlog for viewer, catalogue, source-resilience and community ideas. This file records decisions, priorities and harness mapping. It does **not** replace the sequential execution plan.

## Binding sources

| Source | Role |
|---|---|
| [`AGENTS.md`](../AGENTS.md) | Implementation contract |
| [`harness/plan.json`](../harness/plan.json) | Approved sequential steps and acceptance gates |
| [`harness/progress.json`](../harness/progress.json) | Current step status and acceptance evidence |
| [`PRODUCT.md`](../PRODUCT.md) | Product and safety profile |
| [`DESIGN.md`](../DESIGN.md) | Interface contract |
| This file | Product ideas, competitive decisions and later gates |

Rules:

- Only `harness/plan.json` / `harness/progress.json` may start or complete implementation steps.
- Ideas enter the harness only when their step is begun and concrete checks exist.
- No later feature may start while the current step is open.
- Video bytes never pass through CrozzTube/PHP. Generic local video-file uploads stay disabled.
- Old handoffs and wiki pages are operational history. They must not invent a second roadmap.

## Status model

| Status | Meaning |
|---|---|
| `done` | Accepted and published in the harness, or shipped as verified intermediate work inside an active step |
| `active` | Current harness step |
| `planned` | Approved harness step not yet started |
| `idea` | Confirmed backlog item not yet a harness step |
| `deferred` | Intentionally postponed |
| `excluded` | Explicit non-goal |

Priorities:

| Priority | Meaning |
|---|---|
| P0 | Required for the current or next harness step |
| P1 | High viewer value after the current sequence |
| P2 | Valuable, but after P1 foundations |
| P3 | Later research or optional polish |

## Hard product boundaries

- Streaming/import only. No video-file upload workflow as product surface.
- No Shorts-first product model.
- No pure popularity/viewer-count ranking as the only discovery signal.
- No overloaded multi-navigation / hero-carousel home.
- No login wall for reading public pages.
- No ad-like interruptions or aggressive consent overlays as product pattern.
- No download/hoster aggregation, DNS-bypass guidance or unclear rights chains from pirate catalogue sites.
- No CrozzTube screen-share, media proxy, rehost or party recording of provider video/audio.
- Downloads of videos for offline viewing remain excluded even if a PWA ships.

## Roles and content authority

Confirmed decision:

- Only admins and specially authorised **curators** may create, import, publish, replace sources for or remove platform videos/playlists.
- Curators receive content rights, not user/system administration.
- Normal users and creators may only **recommend** video, playlist and replacement URLs. Recommendations never publish content by themselves.
- Recommendations are visible only to the submitter and curator/admin.
- Recommendation statuses: submitted, in review, needs information, accepted, partially accepted, rejected. Short reasons are required.
- Every recommendation requires hCaptcha plus an accessible alternative and privacy review.
- Limits: 10 new recommendations/day, max 20 open, one active replacement recommendation per video, URL/content dedupe, cooldown and report abuse.
- Personal playlists may collect only already published CrozzTube videos.
- Shared personal playlists use Owner / Editor / Viewer. Only the owner manages members and privacy. No import rights.
- Platform playlists and personal playlists remain separate kinds. Imported external playlists become editorial platform catalogues with durable source attribution. Recommenders may opt in to public credit.
- One audited curator approval is enough. Sensitive, disputed or self-owned cases escalate to a second curator or admin.

## Current harness sequence

Active branch expectation remains the approved feature branch when implementing steps. Publication still requires checks, commit and confirmed push.

| Step | Title | Status | Notes |
|---|---|---|---|
| 1 | Ausgangsstand und Harness | `done` | Accepted |
| 2 | Oracle staging, queue and Odysee transfer | `done` | Accepted |
| 3 | Full clip workflow | `done` | Accepted; browser-local H.264/AAC, 5–60 s, collections |
| 4 | Timemarks, share, votes and cleanup | `active` | Checkpoint already includes watch-action reorder and clip preview |
| 5 | Searchable transcript | `planned` | Enrich with shared time context |
| 6 | Continue watching management | `planned` | Enrich with guest local history + login sync consent |
| 7 | Shared playlists | `planned` | Personal collaboration only; no import rights |
| 8 | Series and seasons | `planned` | Playlist-based; order/title/publish time first |
| 9 | Sleep timer | `planned` | |
| 10 | Personal caption style | `planned` | |
| 11 | Explain and control recommendations | `planned` | Enrich with chronological/random feeds |
| 12 | Conscious live redirects | `planned` | Enrich with live metadata and collapsible chat |

### Already shipped inside steps 3–4

| Item | Status | Evidence |
|---|---|---|
| Prioritised watch action bar | `done` | Desktop: Like, Save, Share, Clip, More; mobile 3×2 including comments |
| Unified Save dialog | `done` | Watch Later + playlists together |
| Browser-local clip preview and single timeline | `done` | Preview click play/pause; handles + whole-range drag; no server video path |

## Competitive benchmark

Public, anonymous desktop review on 12 November 2026. Confidence is lower wherever login, consent or ads hide surfaces.

### YouTube

Take:

- Mature player shortcuts and transcript with time jumps
- Clear save/share/transcript actions
- Rich recommendation cards with duration, channel, age and live/new signals

Do not take:

- Ad interruptions
- Empty anonymous home that depends on history
- Opaque recommendation logic

### Rumble

Take:

- Explicit library destinations (history, watch later)
- Compact action strip
- Visible card metadata and live badges

Do not take:

- Dense multi-navigation and topic overload
- Login walls for basic reading

### Twitch

Take:

- Immediate live metadata (category, language, viewers, duration)
- Collapsible chat beside the player
- Named recommendation reasons where present

Do not take:

- Three simultaneous dominant columns
- Aggressive signup/ad overlays

### Gronkh.tv

Take:

- Chapters for long VODs
- Replay chat and time-linked comments
- Explicit reversible autoplay choice
- Feature settings and focus/cinema modes

Do not take:

- Humorous/unclear consent copy
- Unlabelled counters

### Kick

Take:

- Clear Live / Categories / Clips browse tabs
- Language filter and compact live cards
- Clip cards with duration, views, creator and age

Do not take:

- Pure viewer-count ranking
- Uncurated clip flood
- Aggressive consent surfaces

### SerienStream / AniWorld

Take:

- Newest episodes rail
- A–Z catalogue plus calendar, genres, random find and wish/request surfaces
- Explicit season/episode labels when the catalogue owns the metadata

Do not take:

- Download/hoster aggregation
- DNS circumvention guidance
- Unclear rights chains

### Anime-Loads

Take:

- Clear media-type separation: series, movie, OVA, special, bonus, web
- Status/year organisation

Do not take:

- Release/download aggregation model
- Adult category as default discovery path

### Crunchyroll

Take:

- Release calendar and simulcast-style “new episode” discovery
- Genre catalogue navigation
- Language/subtitle awareness as catalogue metadata

Do not take:

- Paywall-first landing
- Store/games distraction as core watch UX
- Assuming CrozzTube can invent season/episode numbers the way a licensed catalogue can

## Decision catalogue

### Enrich existing harness steps

| ID | Decision | Priority | Maps to | Status |
|---|---|---|---|---|
| E1 | Shared time context for chapters, transcript hits, notes and comments | P0 | Steps 4–5 | `idea` until step work begins |
| E2 | Public timemarks remain step 4; time-linked comments and moderated opt-in replay chat are separate later topics | P0/P1 | Step 4 + later | `active` / `idea` |
| E3 | Guest continue-watching locally; login shows preview and explicit merge/keep/discard | P0 | Step 6 | `idea` |
| E4 | Recommendation reasons, exclusions, personalisation off, plus chronological and random feeds | P0 | Step 11 | `idea` |
| E5 | Live metadata and collapsible chat in addition to conscious live redirects | P1 | Step 12 + later live work | `idea` |
| E6 | Shared personal playlists: Owner/Editor/Viewer; no import rights | P0 | Step 7 | `planned` |
| E7 | Series remain playlist-based; use order, title and publish time first; no invented season/episode fields | P0 | Step 8 | `planned` |

### Discovery, player comfort and accessibility

| ID | Decision | Priority | Status |
|---|---|---|---|
| D1 | Clear Live / VOD / Clips areas plus filters for language, category, duration and status | P1 | `idea` |
| D2 | Faceted catalogue with A–Z plus genre, year, type, status, duration and confirmed main language | P1 | `idea` |
| D3 | Progressive unified search across videos, channels, playlists, clips; later transcript/timemarks | P1 | `idea` |
| D4 | Calm home rails: continue watching, subscriptions, new, live, random find, plus labelled editorial collections | P1 | `idea` |
| D5 | Player comfort pack: shortcuts + help, cinema/focus mode, collapsible side panes, reversible autoplay | P1 | `idea` |
| D6 | Accessibility is a required gate on every UI step: keyboard, focus, names, contrast, target size | P0 | `idea` / cross-cutting |
| D7 | Release calendar and opt-in reminders from real scheduled CrozzTube items / confirmed updates | P1 | `idea` |
| D8 | Episode missing/wrong reports, series wishes and spoiler-bound episode discussion stay separate | P1 | `idea` |
| D9 | Intro/recap/credits as start–end segments; community proposes, curator publishes; player skips only confirmed segments | P1 | `idea` |
| D10 | Spoiler protection uses watch progress; later episodes and marked spoilers stay revealable | P1 | `idea` |
| D11 | Age rating + controlled content warnings at editorial approval; Steam-like age gate; no ID uploads | P1 | `idea` |
| D12 | 18+ only if globally enabled and curator-reviewed; hidden from default search/recommendations; always behind age gate | P1 | `idea` |
| D13 | Structured work relations (sequel/OVA/spin-off graph) deferred until reliable data exists | P3 | `deferred` |

### Import, sync and source resilience

| ID | Decision | Priority | Status |
|---|---|---|---|
| S1 | Core owns connector contract, queue, preview, status and sync; provider playlist logic is a plugin capability | P1 | `idea` |
| S2 | Support YouTube, Odysee, BitChute via metadata engine; Rumble via maintainable connector that degrades visibly | P1 | `idea` |
| S3 | Users recommend playlist URLs; only curator/admin imports after preview/selection | P1 | `idea` |
| S4 | Batch size 25, max 250 selected items per import; keep successes; keep failed first-import items internal | P1 | `idea` |
| S5 | Retries: 1 min, 15 min, 2 h; then manual | P1 | `idea` |
| S6 | Shared night budget: one worker, ≤5 playlist checks + ≤10 source probes per 5-minute run, 60 s runtime, ≤200 external requests/night | P1 | `idea` |
| S7 | Playlists at most daily with backoff to 3–7 days; stable videos weekly; real playback failures trigger one bundled check | P1 | `idea` |
| S8 | Manual “check now” uses same idempotent job: single-flight, 15 min cooldown | P1 | `idea` |
| S9 | New external playlist items become curator drafts; never auto-publish. Remove/reorder need confirmation | P1 | `idea` |
| S10 | External playlist removal of a still-reachable video is a playlist sync choice, not “unavailable” | P1 | `idea` |
| S11 | Same model later for channel recommendations and curator-reviewed RSS/Atom feeds | P2 | `idea` |
| S12 | Confirmed unavailable videos become repairable tombstones outside discovery/autoplay | P1 | `idea` |
| S13 | Unavailable threshold: explicit 404/410/deleted + 10 min control check, or three failures across ≥24 h | P1 | `idea` |
| S14 | Public states: original missing (+ suggest), source under review, reviewed community replacement active. Internally separate replacement vs rights/abuse cases | P1 | `idea` |
| S15 | Replacement URLs may be any public URL after HTTPS/SSRF/redirect/metadata checks; never bypass private/rights blocks | P1 | `idea` |
| S16 | One account one vote; votes only rank; curator/admin final approval using multi-signal identity review | P1 | `idea` |
| S17 | One active replacement plus reviewed fallbacks; curator consciously switches and rechecks time data | P1 | `idea` |
| S18 | Rights/abuse dispute pauses playback with neutral “under review”; ACP review with evidence, appeal and audited decision | P1 | `idea` |
| S19 | Durable provenance on community replacements; public credit is opt-in | P1 | `idea` |
| S20 | Time-bound data stays source-versioned; mismatched replacements keep original times with warning and require confirmed remap; no proportional rescale | P1 | `idea` |
| S21 | Compact watch-page source status + expandable non-sensitive history | P1 | `idea` |
| S22 | Official mirror source sets distinct from community replacements; user provider preference with transparent fallback | P1 | `idea` |

### Notifications, privacy and rollout

| ID | Decision | Priority | Status |
|---|---|---|---|
| N1 | Per playlist/series notification preferences; default bundled in-app; email/calendar opt-in; timezone visible | P1 | `idea` |
| N2 | Retention: technical raw logs 30 days; recommendations/decisions 2 years; rights cases 3 years; public provenance permanent | P1 | `idea` |
| N3 | Feature gates with curator pilot before broad release | P0 | `idea` |
| N4 | Provider contact notice before loading external players; respect preference; avoid unnecessary preload | P1 | `idea` |

### Later confirmed product ideas

| ID | Decision | Priority | Status |
|---|---|---|---|
| L1 | Verified cross-provider creator identity links | P2 | `idea` |
| L2 | One follow relationship with per-provider filters/notifications | P2 | `idea` |
| L3 | Verified creator claim / correction / removal process | P2 | `idea` |
| L4 | Case appeal centre with status, deadline and independent second review | P2 | `idea` |
| L5 | Versioned community caption/transcript corrections | P2 | `idea` |
| L6 | Personal data export/import for playlists, follows, history and settings | P2 | `idea` |
| L7 | Incognito watch session | P2 | `idea` |
| L8 | Family profiles with PIN, max age and blocked categories/creators | P2 | `idea` |
| L9 | Verified accessibility media metadata (captions, transcript, AD, sign language, easy language) | P2 | `idea` |
| L10 | Labelled editorial home collections with rationale, editor, dates | P2 | `idea` |
| L11 | Curator-verified search aliases; original title remains primary | P2 | `idea` |
| L12 | Device handoff via short-lived QR/code; position only, no media bytes | P2 | `idea` |
| L13 | Structured playback quality reports with sparse diagnostics and status | P2 | `idea` |
| L14 | Public meaningful curation history for source/metadata changes | P2 | `idea` |
| L15 | Signed, retryable webhooks with secret rotation and delivery log | P3 | `idea` |
| L16 | Installable PWA with offline metadata/lists; never cache video | P3 | `idea` |

### Watch parties and Discord

Operational requirements that must exist before implementation: [`discord-watch-party-requirements.md`](discord-watch-party-requirements.md). No Discord application, token or live test exists yet.

| ID | Decision | Priority | Status |
|---|---|---|---|
| W1 | Provider-neutral watch parties: CrozzTube syncs room state only | P2 | `idea` later gate complex |
| W2 | Mandatory Discord plugin: OAuth-linked account, temporary voice channel + text thread | P2 | `idea` |
| W3 | Public listed / private invite; capacity 25; required conversation language; optional 180-char info | P2 | `idea` |
| W4 | Host/co-host controls and moderation; member pause/seek requests; host leave transfers | P2 | `idea` |
| W5 | Late join shows current state and requires consent before sync seek | P2 | `idea` |
| W6 | Unsupported players use ready-state, 5 s countdown, host position and manual resync; Discord buttons + optional audio countdown | P2 | `idea` |
| W7 | Party ends at actual video end; pauses extend it; Discord afterglow warns at 30 min and disconnects/deletes at 60 min | P2 | `idea` |
| W8 | No recording of video/audio/chat; only necessary moderation/system events | P2 | `idea` |
| W9 | Discord Activity is a separate later feasibility study, not a prerequisite | P3 | `deferred` research |

## Suggested later harness candidates after step 12

These are not started and must not jump the queue:

1. Catalogue discovery filters, unified search and editorial home rails
2. External recommendation intake + curator import for videos/playlists/channels/feeds
3. Source health, tombstones, community replacements and official mirror sets
4. Time-linked comments, moderated replay chat and skip segments
5. Accessibility media metadata, family controls, incognito and data portability
6. Watch parties with Discord plugin
7. Signed webhooks and metadata-only PWA

When a candidate becomes a harness step, define concrete checks, acceptance evidence, feature gate and pilot scope first.

## Operational defaults (binding until changed by admin after measured load)

| Parameter | Value |
|---|---|
| Import batch | 25 items |
| Max selected items per import | 250 |
| Recommendation rate | 10/day, 20 open |
| Manual check cooldown | 15 minutes |
| Night external request budget | 200 |
| Probe run | ≤5 playlist + ≤10 source checks / 5 min, 60 s |
| Retry schedule | 1 min → 15 min → 2 h |
| Unavailable confirmation | 404/410/deleted + 10 min recheck, or 3 failures / ≥24 h |
| Retention | 30 d / 2 y / 3 y / permanent provenance |
| Captcha | hCaptcha + accessible alternative |
| Watch-party capacity | 25 (admin-configurable; pilot may be smaller) |
| Discord afterglow | warn 30 min, force-clean 60 min |

## Explicit exclusions

- Shorts-first product
- Popularity-only ranking
- Hero-carousel / multi-dominant navigation homes
- Login-to-read
- Ad interruptions as UX
- Download/hoster aggregation and DNS bypass patterns
- Auto season/episode inference from titles
- Automatic rights-bypass replacements
- Proportional remapping of timestamps across mismatched replacements
- CrozzTube-hosted live streaming or screen share
- Offline video downloads
- Storing ID documents for age checks

## Document maintenance

- Update this file when a product decision changes, a harness step starts/finishes, or a competitive review adds durable patterns.
- Keep German product language for Benny-facing summaries if needed; keep this backlog in English for durable agent use and code-adjacent docs.
- After meaningful backlog edits: review only documentation paths, verify links/whitespace, then checkpoint/publish according to harness rules when in an implementation turn.
)
