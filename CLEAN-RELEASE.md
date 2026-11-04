# PlayHub clean release handoff

Website **0.9.13** was rebuilt on 16 October 2026 with consent, cloud playback, performance and updater fixes. The separate mobile **1.0.0** handoff was prepared on 15 October 2026. These are source distributions, not customer installation data or signed production binaries.

## Release files

- `releases/playhub-web-0.9.13-clean.zip` and its matching extracted folder: website, built frontend and locked production PHP dependencies.
- `update_v0.9.13/` and `releases/update_v0.9.13-manual.zip`: manual existing-site overlay with instructions and checksums. These are not signed Admin update bundles.
- `releases/playhub-mobile-1.0.0-clean.zip` and its matching extracted folder: standalone mobile source, native module sources, build tools and OpenAPI specification.
- `releases/playhub-android-native-inputs-20261015.zip`: separately preserved, verified Android native build dependencies. Extract its contents into the mobile project root before a production Android build. SHA-256: `4a917aa7b3eed8131eb03e0d38502af891f98dffa8b20bed63f0d81819998bf9`.

The website's `release-manifest.json` and mobile's `SHA256-MANIFEST.json` contain SHA-256 file inventories; both packages include a clean-source report. The native archive includes its own inventory and instructions; all entries were hash-checked and a fresh extraction passed the normal Release coverage validator. It is a build dependency, not production approval.

## Cleanup scope

The owner confirmed that the local database is already empty. No SQL reset or independent database verification was performed because the local `.env` and its credentials were absent. No local sticker seed data is installed: stickers use KLIPY, configured through the website admin panel.

Local uploaded media, installation settings, Firebase client files, generated signing files, local certificates, logs, test artifacts, dependency directories and build caches are removed by the scoped cleanup. Mobile production API, Expo/EAS ownership, Firebase profiles and license configuration are blank. Product branding, icons, gifts, source code, build recipes and the website's built frontend are retained. The updater's `resources/keys/playhub-update-public.pem` is an essential **public verification key**, not a private signing credential, and is intentionally retained.

Cleanup does not reset customer websites, remote cloud buckets, global SDK/Gradle/Docker caches, installed phone data or credentials held outside this workspace. Deleting a local credential does not revoke it at its provider. EAS-managed signing credentials remain in the owner's account. Deleted local data has no recovery backup in these packages.

## Website installation and existing-site updates

For a fresh installation, extract the website ZIP into a new directory. Configure PHP 8.3 or later, MySQL, FFmpeg and the supported web server using `server-configs` and the website documentation. The supplied production `vendor` and built frontend allow installation without restoring development dependencies. If needed, restore PHP dependencies with `composer install --no-dev --optimize-autoloader`.

Open `/install.php`, supply your own database and PlayHub license, and create the first owner account. Installation generates its own application key and receipt; no shared account/password is bundled. Configure KLIPY and any storage, mail or push providers privately. Keep a Firebase server service-account key outside public files and source distributions.

For an existing customer's update, back up and preserve their database, `.env`, uploads, storage, branding, plugins and live runtime. Apply pending migrations through the normal deployment procedure. **Do not run local cleanup/reset tools or `migrate:fresh` on a customer site.** These unsigned fresh-install packages are not signed automatic-update bundles and do not replace updater signature verification.

For the manual **0.9.12 to 0.9.13** workflow, use [the manual update guide](docs/manual-update-0.9.12-to-0.9.13.md) and the separate `update_v0.9.13/` folder. Its `files/` directory contains the website overwrite payload; `reference/` contains material to review and merge. Some migrations alter existing columns or indexes, so inspect migration status and use the complete deployment and rollback steps in the guide.

## Mobile installation and development

The extracted mobile folder is the project root. Install Node matching `package.json` and the Android build toolchain, then run `npm ci`. Configure `app.settings.json` and, if needed, `.env` from `.env.example`. The default Android emulator API is `http://10.0.2.2:8000/api/v1`; physical phones need a reachable backend. A newly emptied website must be installed before it can serve the app.

Run `npm run config:check`, both TypeScript checks, lint and tests. Generate Android with `npm run prebuild:android`, then use `npm run android` for a development client. Expo Go lacks required native modules. Generated native projects, dependencies and caches are deliberately absent and recreated by installation/build commands.

## Production Android build

Configure a reachable public HTTPS V1 API, your own Expo owner/EAS project, package identifier/build counters and matching Android Firebase client. Configure the build-only PlayHub license in private owner settings or `PLAYHUB_BUILD_LICENSE_KEY`; see `docs/build-licensing.md`. Supply signing through the supported private build setup. No owner API/license key, private signing key or certificate is bundled.

Extract the separate native-input ZIP into the mobile project root, then select and validate it:

```powershell
$env:PLAYHUB_ANDROID_NATIVE_OVERLAY_BUNDLE='native-release-inputs/accepted-20261014'
npm run android:native-overlay:check
npm run config:production:check
npm run release:api:check
```

The existing production EAS profiles select that same native-input directory. Use the documented production AAB/APK build commands after all checks pass. Keep the same signing identity when updating an already published app.

The WebRTC 16 KB native-input blocker is resolved, but **no shipping APK/AAB is approved by this cleanup**. Matching backend deployment, actual signed-artifact alignment/signing checks, release-mode playback/push/memory/device acceptance and publisher review remain required. Google Play builds retain the consumption-only purchase policy. See `docs/android-production-status-2026-10-15.md` in the mobile source for historical evidence and remaining gates. iOS requires separate Apple configuration, signing and acceptance.
