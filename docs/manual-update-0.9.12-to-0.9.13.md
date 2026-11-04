# PlayHub website manual update from 0.9.12 to 0.9.13

Use this guide to update an **existing PlayHub website running 0.9.12**. The accompanying `update_v0.9.13` folder contains the corrected 0.9.13 website code, production PHP dependencies and built frontend rebuilt on 16 October 2026. It does not update Android or iOS applications.

This is a full file overlay, not a list of differences against your customized installation. Test it on a private staging copy first. Keep staging mail, payments, push delivery and scheduled jobs isolated from production.

## Package contents

| Location | Purpose |
| --- | --- |
| `files/` | Website application files to deploy, including `vendor/`, `build/` and database migrations. Paths are relative to the existing website root. |
| `reference/` | Environment template, translations, server and LiveKit examples, default artwork, release notes and the original release inventory. Review and merge these separately. |
| `INSTRUCTIONS.md` | These instructions. |
| `RELEASE-FIXES.md` | Consent, cloud playback, page performance and updater fixes included in this rebuild. |
| `verify-package.cjs` | Optional, read-only package verification using Node.js. |
| `update-manifest.json` | SHA-256 inventory of the supplied files and package preservation rules. |

**Copy the contents of `files/` into the existing website root; do not put the `files` folder itself there.** Keep the update package, backups and temporary extraction folders outside the website's public directory. Do not upload the complete package into a browsable web folder.

This package is for a manual deployment. It is not a signed bundle for Admin System Updates. Keep automatic-update signature verification enabled.

## 1 Prepare and back up the existing site

Confirm the installation is 0.9.12 and that no automatic update is running. In the existing website root, use the hosting terminal or SSH:

```sh
php artisan tinker --execute="dump(app(\App\Updates\VersionRepository::class)->current());"
php artisan tinker --execute="dump(\App\Models\SystemUpdate::query()->whereIn('status', \App\Models\SystemUpdate::ACTIVE_STATUSES)->get(['id', 'status', 'from_version', 'to_version'])->toArray());"
```

The second command should return an empty list. Resolve any interrupted automatic update before copying files; its recovery process can otherwise restore an older release. Do not delete update-history records or run a manual update at the same time as the automatic updater.

Make a complete, restorable backup of the database and current website files. Store the backup privately outside the website root, and verify that it can be restored. Record the existing PHP version, worker services and scheduler configuration.

Preserve these customer files and directories:

| Preserve | Includes |
| --- | --- |
| `.env` and private `.env.*` files | Existing `APP_KEY`, database, storage, mail, authentication, LiveKit and other credentials. |
| `storage/` | Saved Admin configuration, private files, sessions, logs, `storage/app/license.json` and `storage/app/installed.lock`. |
| `uploads/`, `public/uploads/`, custom media directories and cloud storage | Customer videos, images, attachments and recordings. |
| `branding/`, `gifts/`, `stickers/` | Customer artwork and uploaded gift/sticker files. |
| `plugins/`, `plugin-packages/` and any configured custom plugin path | Installed plugins and their data. |
| `livekit/runtime/`, `livekit/status/` and private service files | Live credentials, certificates and runtime configuration. |
| `bootstrap/cache/`, `public/.well-known/` | Existing runtime directory and certificate/domain verification files. Clear generated application caches using Artisan later. |
| SQLite database files, if used | Include the database and any WAL/SHM files in a consistent backup. |
| `lang/`, custom source changes and server configuration | Merge customer translations, themes, code modifications and rewrite rules deliberately. |
| `VERSION` | Keep the installed version until the update succeeds; change it in step 7. |

The overwrite payload excludes the protected customer locations. It still replaces application source and default PHP configuration, so merge any custom code changes before deployment.

**Never run `/install.php`, `php artisan key:generate`, `migrate:fresh`, `migrate:refresh`, `migrate:reset`, `db:wipe` or `db:seed` during this update.** Do not remove the installation lock or replace the customer's `.env` with a template.

## 2 Check requirements and package integrity

Use PHP 8.3 or a later version compatible with the supplied Composer lock and its required extensions. The command-line PHP and website PHP must both meet the requirements. Keep working MySQL, FFmpeg/FFprobe, queue and scheduler configurations. Allow space for the full package, database migration work and backups.

The package already contains production `vendor/` and compiled `build/` files. You do not need Node.js, npm or Composer on the web server when deploying those complete, matching directories.

If Node.js is available, verify the extracted update before uploading. Run this **inside `update_v0.9.13`**:

```sh
node verify-package.cjs
```

Continue only if verification passes. Obtain the package from the trusted publisher; an unsigned checksum inventory detects missing or changed files but does not authenticate a publisher.

## 3 Enter maintenance and pause background work

Schedule a maintenance window and let active uploads, video processing and Live sessions finish. In the **existing website root**, run:

```sh
php artisan down --retry=60
```

Pause the website's scheduler and gracefully stop its supervised queue workers after their current jobs finish. This includes any push worker and automatic-update recovery jobs. Maintenance mode alone does not guarantee that already-running jobs have stopped. Keep the site in maintenance until all later commands succeed.

Once requests and background work have stopped, take the **final consistent database and mutable-file backup** that will be used for rollback. The earlier preparation backup may miss uploads, transactions or other activity completed while work was draining. Do not replace files until this final recovery snapshot is complete.

## 4 Deploy the files

1. Extract the update into a temporary directory outside the public website. Show hidden files in your file manager or FTP client.
2. Back up and move the existing `vendor/` directory outside the website root. Deploy `files/vendor/` as a complete replacement. Do not merge old and new dependency trees. If you maintain custom Composer dependencies, resolve and test their integration on staging instead of overwriting them blindly.
3. Copy the remaining contents of `files/` into the existing website root, preserving relative paths and overwriting matching application files. **Merge directories; do not use mirror/synchronize options that delete destination files.** In particular, copy `bootstrap/app.php` without replacing `bootstrap/cache/`.
4. Copy all supplied `build/` files and `build/manifest.json` together. Keep existing hashed files in the destination `build/assets/` so already-open browser tabs can still load older chunks. Never deploy only the new JavaScript or only the manifest.
5. Merge `reference/lang/` into the customer's `lang/`: retain existing customized translations and add new files and keys from 0.9.13. Do not simply replace a customer-edited language directory.
6. Compare `reference/.htaccess` and `reference/server-configs/` with the site's active server rules. Apply required internal-directory and private-media protections while preserving the actual domain, SSL, PHP socket and custom redirects. Use the supplied Apache rules directly only if the site has no custom rules. The Nginx examples are references, not ready-made replacements for a configured virtual host.
7. Compare `reference/.env.example` with the existing `.env` and add only settings required for features you use. Keep the current `APP_KEY`, `APP_URL`, credentials and storage/provider configuration. Optional native push needs its own credentials and worker; copying this update does not enable working push delivery.
8. Keep existing branding and gifts. `reference/default-assets/` is supplied only for deliberately restoring missing product defaults. Review LiveKit examples only if service configuration needs updating; do not run its installation scripts for an ordinary website update.

Keep `storage/` and `bootstrap/cache/` writable by the correct PHP account, plus any existing writable upload/plugin/translation directories. Avoid blanket `777` permissions. There is no routine requirement to run `storage:link` for this application's root `uploads/` layout.

### Alternative when restoring dependencies from the lockfile

If you cannot deploy the bundled production `vendor/`, restore dependencies from the supplied `composer.lock` in the website root:

```sh
composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader --no-scripts
composer check-platform-reqs --no-dev
```

Do not run `composer update`. Package discovery is performed below. If rebuilding frontend assets yourself, use `npm ci` followed by `npm run build` in a trusted build environment and deploy its complete `build/` output. Do not run `npm update` or expose the Vite development server on production.

## 5 Clear stale caches and migrate the database

Run these commands from the website root with the new application and dependencies:

```sh
php artisan optimize:clear
php artisan package:discover --ansi
php artisan migrate:status
php artisan migrate --force
php artisan migrate:status
```

The final status must show no pending migrations. Laravel uses the installation's migration history to determine what is needed; do not edit that history manually or select migrations only by the version number.

If Artisan cannot start because a removed dependency's provider or class is still referenced, privately back up and remove only the generated `bootstrap/cache/config.php`, `bootstrap/cache/packages.php` and `bootstrap/cache/services.php`, then retry the cache/discovery commands. If your deployment uses custom generated cache paths, handle those corresponding files instead. Preserve the cache directory, its permissions, `bootstrap/app.php` and all customer settings; do not delete the whole `bootstrap/` or `storage/` directory.

The supplied release includes migrations after the original 0.9.12 release for native authentication/version/session state, category and search indexes, upload recovery, push delivery, KLIPY sticker references and user safety. Some alter existing columns or create full-text indexes, so allow enough time and database capacity. If migration fails, keep maintenance enabled, inspect the error and database state, and resolve it before continuing. Do not blindly reset or rerun partially applied DDL.

## 6 Rebuild caches and check the installation

```sh
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan route:list --path=api/health
```

If a command fails, stop and resolve the error. Reload PHP-FPM or clear OPcache through the hosting controls if the server continues serving old PHP code. Use the existing production PHP service configuration.

Confirm the original installation lock, license receipt, Admin settings, uploaded files and private credentials remain present. Verify new frontend assets exist at the paths named in `build/manifest.json`. Check that application logs contain no new startup errors. Keep maintenance active while diagnosing any failure.

## 7 Record version and reopen the site

After the migrations and cache commands succeed, update the installed version using the application's version repository:

```sh
php artisan tinker --execute="app(\App\Updates\VersionRepository::class)->write('0.9.13');"
php artisan tinker --execute="dump(app(\App\Updates\VersionRepository::class)->current());"
```

The result must be `0.9.13`. If Tinker is unavailable, edit the existing root `VERSION` file to contain only `0.9.13` and a newline, then check the reported version after reopening. Do not insert a fabricated completed automatic-update record.

Signal workers to reload, resume the existing supervised workers and scheduler, and exit maintenance:

```sh
php artisan queue:restart
php artisan up
```

Restart any other long-running PHP application services that your installation actually uses. Resume the existing `videos,default` worker and, when enabled, the configured push queue. Keep the scheduler enabled for normal media and Live maintenance. Do not start extra unsupervised workers on top of existing services.

## 8 Verify the website

Open the site's own HTTPS address. `/api/health` should return JSON with `status: "ok"` and `version: "0.9.13"`. For the owner's local development installation the address is `https://app.test/api/health`; customers must use their own canonical domain. This health response alone does not prove database, media or external-provider readiness.

Check these workflows using test-owned accounts and disposable content:

- Existing owner login, Admin settings, site name/logo/accent and customer translations.
- Home, video cards, category rows, Shorts, Watch, seeking and fullscreen on desktop and mobile.
- Existing local/cloud images and authorized private or paid playback; unauthorized viewers must remain denied.
- One upload completing processing through the normal queue, then authorized playback and deletion.
- Comments, playlist creation, notifications and enabled social sign-in methods.
- Configured Live, mail, storage, payment and push integrations where used. Keep the existing credentials; test real delivery separately.
- Browser console/network, Laravel logs, queue failures and scheduler activity for new errors.

In **Admin → Pages**, review Terms of Use and Privacy Policy, enable both pages and select **Publish legal page** for each. Built-in preview text is not an owner publication. The consent checkbox becomes available after real legal pages are published; browser language fallback must resolve those saved documents. The update never accepts terms on a user's behalf.

For S3 and other cloud video storage, browser video and caption requests now stream through the site's authorized `/media/` endpoint instead of redirecting the browser to a bucket. Verify playback and seeking with an existing cloud video and confirm the media response is `200` or ranged `206`, rather than a cross-origin redirect. Keep buckets private and preserve the existing storage credentials. This delivery method uses application-server bandwidth; configure normal PHP/proxy streaming timeouts and capacity for the expected audience.

Future Admin one-click updates require signed publisher packages and a reachable HTTPS repository. See `reference/docs/one-click-update-publishing.md` in this package, or [the publisher guide](one-click-update-publishing.md) in the source documentation. `resources/keys/playhub-update-public.pem` verifies signatures; it cannot sign a release.

Refresh the browser and purge only stale site HTML/application responses in any CDN or proxy. Keep the retained old asset chunks. If essential checks fail, put the site back into maintenance and follow the rollback procedure.

## Rollback

Pause workers and the scheduler and keep maintenance enabled. Restore the backed-up 0.9.12 application, coherent `vendor/`, frontend manifest/assets and `VERSION`. If migrations changed the database, restore the matching pre-update database snapshot using the tested backup procedure; do not assume that deploying old PHP reverses schema changes. Preserve or reconcile any media and customer activity created after the backup before restoring data.

Clear and rebuild caches for the restored release, restart its services and verify the site before reopening it. Do not run a blanket migration rollback or delete customer data to make the old version start.

## Hosting without a terminal

File Manager or FTP can transfer the files, but migrations and cache operations still require PHP command execution. Ask the host to run the commands above through its terminal, a controlled one-time job or support service. Do not expose an unauthenticated web command runner and do not use the installer as an upgrade shortcut.
