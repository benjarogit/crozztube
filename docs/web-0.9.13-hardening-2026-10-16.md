# PlayHub website 0.9.13 rebuild on 16 October 2026

This rebuild fixes the reported consent dialog, S3 video playback and page-opening delays, and hardens future signed Admin updates. It retains website version 0.9.13. Android/iOS source and customer credentials are unchanged.

## Consent and legal publication

The disabled Accept button was a real unavailable-policy state: built-in Terms/Privacy previews had not been saved as owner publications. The Admin editor also disabled saving unchanged built-in text, so an owner could not publish reviewed defaults without making an artificial edit.

- Admin → Pages now offers **Publish legal page** for reviewed, unchanged Terms and Privacy content. Unsaved legal previews are labeled **Review required**.
- An unavailable policy no longer opens a disabled consent dialog automatically at every login. A blocked publishing action still explains what needs administrator attention, with a Pages link for administrators and working browse/refresh controls.
- Real published legal content now falls back across installed languages consistently. An incomplete unused translation does not invalidate a complete published document in another installed language.
- Current-version checked acceptance remains explicit and server validated. Missing or disabled legal publications cannot create receipts; no user is accepted automatically.

After updating, the owner must review, enable and publish both legal pages. This package contains application fixes, not a fabricated legal approval or customer consent record.

## Cloud playback and page performance

The customer's console showed a video request redirected from the website's `/media/videos/.../stream/high` endpoint to S3, where the browser rejected it because the response lacked `Access-Control-Allow-Origin`. Thumbnails used a different, same-origin delivery path, so they still worked.

Cloud video and captions now stay on the website origin. The application authorizes each request, opens one streamed provider GET, forwards byte ranges and returns the proper 200/206/416 response. It does not load the whole video into PHP memory or expose provider XML errors and signed URLs to viewers. S3-compatible providers and Azure use their existing signing/configuration; private/paid checks, local playback and direct native receiver URLs remain intact.

Storage usage previously performed an existence request plus a size request for every cloud object, and nested author resources repeated private account/storage calculations while rendering video feeds. The rebuild removes the duplicate metadata call, caches immutable object sizes, seeds/refreshes the size cache on writes, invalidates it on deletion, and omits private account details from nested video authors. Cold legacy metadata may still require one provider size read per unknown object; warm reads avoid those repeated provider calls.

Website cloud streaming uses application-server bandwidth and connections. Keep adequate PHP/proxy streaming capacity. Actual customer AWS playback must be checked after deployment; the local regression run used controlled provider responses and did not modify customer buckets, permissions, keys or CORS rules.

## Signed Admin updater

The rebuild fixes optional Reverb restart handling, CLI PHP paths on Windows, overly restrictive Laravel patch requirements, and use of freshly bootstrapped PHP processes after new files/dependencies are installed. File operations now reject directory targets and Windows path aliases that could bypass protected customer paths. The interrupted-update recovery schedule explicitly runs in maintenance mode, so an abandoned installer cannot prevent its own scheduled recovery.

The signed future-update regression covers a 0.9.13 → 0.9.14 fixture, migrations, route/config caches, customer-file preservation, corrupt/unsigned packages, rollback and interrupted recovery. Prebuilt updates were exercised with Composer, Node and npm absent from PATH. Signature verification remains required.

The supplied `playhub-update-public.pem` can only verify signatures. Publishing a real Admin update requires its matching private key, immutable release baselines and an operational HTTPS update repository. See [the publisher guide](one-click-update-publishing.md). The regenerated manual/fresh-install ZIPs are not signed Admin bundles.

## Deployment files

- `releases/playhub-web-0.9.13-clean/` and `playhub-web-0.9.13-clean.zip`: rebuilt fresh-install website with production vendor and frontend assets.
- `update_v0.9.13/` and `releases/update_v0.9.13-manual.zip`: existing-site manual overlay, separate reference files, instructions and SHA-256 inventory.
- [Manual update instructions](manual-update-0.9.12-to-0.9.13.md): backups, maintenance, file deployment, migrations, version commit, acceptance and rollback.

Preserve the customer's database, `.env`, `APP_KEY`, storage/settings/license files, uploads, custom branding, gifts, plugins and Live runtime. Customer language edits and server rules are reference/merge steps. Do not run the installer or database reset tools on an existing site.

## Validation

- Consent backend and localization: 28 tests, 276 assertions.
- Consent/controller and real Chrome administrator publication: 19 tests passed.
- Cloud/media, private playback, direct imports and native receiver regressions: 102 tests, 1,436 assertions.
- Signed updater harness: nine groups, 157 assertions, plus its PHPUnit wrapper. The final recovery-schedule regression executes the real scheduled recovery command while the fixture is in maintenance mode.
- JavaScript unit/regression tests: 28 passed. Two obsolete Shorts-sidebar assertions were updated to the current API-derived viewer.
- Full Laravel suite: 631 tests, 11,502 assertions passed using an isolated development dependency directory and SQLite test databases.
- Clean release packaging regressions: 127 checks passed.
- Production Vite build passed; existing large-chunk warnings remain.

Final package inventories are checked as part of release assembly. An existing push retry test's fractional-clock assertion was stabilized by freezing the clock at second precision; no push-delivery production code was changed.
