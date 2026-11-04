# Publishing signed PlayHub Admin updates

The rebuilt 0.9.13 website supports the existing **Admin → System Updates** workflow. The publisher prepares a signed release and hosts it on an HTTPS repository; the customer's website checks compatibility, creates backups, installs the release, runs migrations and health checks, and rolls back failures. The manual update folder and fresh-install ZIP are separate distribution formats and cannot be installed as signed Admin packages.

## Required publisher inputs

- The **private RSA signing key matching the public verification key already trusted by customers**. `resources/keys/playhub-update-public.pem` contains a public key and cannot sign releases. Keep the private key outside source snapshots, website roots and distributed ZIP files.
- Immutable previous and new release directories with the correct root `VERSION` files and complete production source/build output. The rebuilt `releases/playhub-web-0.9.13-clean/` is the baseline for subsequent releases based on this rebuild.
- A public HTTPS repository base URL whose TLS certificate customers trust, with an endpoint returning the generated update path for their installed version.
- A release-builder environment with PHP, OpenSSL, ZIP, the locked application dependencies and the supported `playhub:update-package` command. Build here; do not place signing credentials on customer sites.

Keep the rebuilt 0.9.13 baseline unchanged. If an earlier, different 0.9.13 build was distributed, resolve that source difference before publishing a delta against this baseline. A matching version string does not override file checksums, and customized customer files intentionally cause a preflight conflict rather than being overwritten silently.

## Build the next update

For an example next release named **0.9.14**, prepare a separate immutable 0.9.14 directory. Use the existing command on the release builder, replacing the paths and domain below with the publisher's real values:

```sh
php artisan playhub:update-package 0.9.13 0.9.14 --previous=/private/releases/playhub-web-0.9.13-clean --current=/private/releases/playhub-web-0.9.14-clean --output=/private/update-repository --base-url=https://updates.example.com/updates --private-key=/private/signing/playhub-update-private.pem --changelog=/private/releases/0.9.14-changelog.json --composer=none --frontend=prebuilt --post=package_discover,optimize_clear,config_cache,route_cache,view_cache,queue_restart
```

`updates.example.com` and `/private/...` are examples, not configured production destinations. Use `--composer=install` when Composer metadata changes; the builder rejects a changed lockfile without deterministic installation. Prebuilt frontend mode includes the compiled assets and does not require Node/npm on customer servers. Avoid frontend build mode unless customer environments are prepared to run that build.

Create the required changelog JSON with all four arrays, replacing example text with the real release notes:

```json
{
  "new": [],
  "improved": [],
  "fixed": ["Describe the actual fix in the next release."],
  "security": []
}
```

Do not use `--unsigned` for production and do not disable signature verification. Existing Laravel migration files are immutable; introduce new migrations for changes. Review the generated structured changelog and requirements before publication. Release requirements can be supplied through `--requirements` when the default supported versions are insufficient.

The builder generates:

```text
update-repository/
  repository.json
  responses/0.9.13.json
  responses/0.9.14.json
  releases/0.9.14/manifest.json
  releases/0.9.14/update.zip
```

Host the generated public files over HTTPS, preserving the paths and package URLs in signed manifests. Do not host the private signing key or the release-builder filesystem. Keep earlier release edges so older installations can follow a complete upgrade path.

## Serve the version-specific response

The customer's updater calls its configured endpoint with `?version=0.9.13`. The endpoint must return the contents of `responses/0.9.13.json`, with `schema_version`, `product`, `current_version`, `latest_version` and the signed `updates` array. Serving `repository.json` directly is insufficient: it is the publisher's release index, not the customer's response format.

Implement this lookup in the update host's existing application or web-server configuration. Validate the version as a supported semantic version and select only a generated response file inside the repository. Return JSON and a clear error for an unknown version. Avoid caching one version's response for another version; package URLs and manifest signatures must remain unchanged.

## Customer setup and verification

Configure the actual repository endpoint and allowed host in the customer's existing `.env` only when deploying a publisher-approved repository:

```ini
PLAYHUB_UPDATES_ENABLED=true
PLAYHUB_UPDATE_ENDPOINT=https://updates.example.com/updates/latest
PLAYHUB_UPDATE_ALLOWED_HOSTS=updates.example.com
PLAYHUB_UPDATE_SIGNATURE_REQUIRED=true
```

Preserve the trusted public key unless a separately planned key rotation is required. Keep a real supervised asynchronous queue consuming `default`, a retry window longer than the updater job timeout, and the Laravel scheduler running for interrupted-update recovery. Do not use the synchronous queue driver for production updates. The existing environment template supplies a 21,660-second queue retry window. Set `PLAYHUB_PHP_BINARY` to the installed CLI PHP path when it is unavailable through the worker's PATH.

In Admin → System Updates, check for updates, inspect the release notes and preflight, provide the required product license, and start the update. A queued update continues independently of the browser. Verify completion, the reported version, media playback and the site's normal workflows. Do not force an update through checksum, signature, filesystem, disk-space or queue failures.

The 16 October regression run exercised a signed 0.9.13 → 0.9.14 fixture with temporary test credentials, fresh CLI migrations/cache rebuilding, backup preservation, rollback and interrupted recovery. It did not publish a production repository or create a production signature. A production one-click delivery remains dependent on the publisher inputs above.
