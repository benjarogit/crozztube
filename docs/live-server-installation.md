# PlayHub Live server installation and operations

PlayHub's existing Laravel backend owns Live authorization, room lifecycle, chat, moderation, contributions, recording and replay processing. LiveKit carries the live media. A working HTTPS API or WebSocket alone does not prove camera, microphone, TURN relay, accounting or replay acceptance.

For the current local Android validation, keep `EXPO_PUBLIC_API_BASE_URL=https://app.test/api/v1` and the configured signaling URL `wss://app.test/livekit`. The local development CA is accepted only by the existing debug configuration. This is not a publicly deployable Google Play production endpoint. Production needs owner-selected public DNS and trusted HTTPS/WSS; do not replace the local API as part of infrastructure setup.

## Required and optional services

| Component | Required for | Current implementation |
| --- | --- | --- |
| Laravel/PHP, application database and HTTPS web server | All PlayHub Live workflows and token issuance | Existing PlayHub installation |
| LiveKit core | Camera/microphone/screen-share live rooms | `livekit/livekit-server:v1.13.3` |
| Reachable RTC TCP/UDP ports and correct advertised address | Actual media transport | Ports from generated LiveKit settings |
| Redis | This generated core/ingress/egress deployment | Existing authenticated Redis; an additional Redis container is not required when the configured server is reachable |
| Embedded TURN | Relay connectivity where direct ICE fails | UDP or TLS according to actual settings; do not claim unconfigured TCP/TLS support |
| Laravel queue worker on `videos,default` | Live thumbnails and recording-to-replay processing, other queued app work | Existing queue connection; locally `database` |
| Laravel scheduler | Stale-source reconciliation, scheduled polls, replay finalization/recovery and retention | `routes/console.php` |
| LiveKit Egress and writable output storage | Live thumbnails, room recording and replay source artifacts | `livekit/egress:v1.13.0`; optional for unrecorded camera hosting |
| LiveKit Ingress | Web/native control of external RTMP/OBS publishing | `livekit/ingress:v1.5.0`; optional for camera/microphone hosting |
| FFmpeg/ffprobe available to PHP worker | Processing finalized recording into normal replay media | Existing `MediaBinaries` configuration; Windows or Linux binaries |

Egress uses more memory/CPU than signaling. Start only core initially on a constrained development computer. The installer/manager's existing full-service actions can also start ingress and egress; do not invoke those blindly when only core is intended. Do not launch a second emulator merely to prove independent sessions: the existing Pixel plus a desktop PlayHub browser with a different authenticated account is a valid Android/Web acceptance pair.

## Configuration and bridge networking

Use PlayHub's authenticated web Admin Live settings to configure the existing installation and regenerate its runtime files. Runtime files are under `livekit/runtime/` and excluded by `livekit/.gitignore`; keys, secrets and Redis credentials must remain server-side. Do not copy them into `EXPO_PUBLIC_*`, a mobile bundle, a committed Compose file, a screenshot or a diagnostic report.

The generated Compose file is `livekit/runtime/docker-compose.yml`. The committed `livekit/docker-compose.yml` provides the deployment definition. All Live containers use the normal `livekit` bridge network. **No Docker Desktop host networking or `network_mode: host` is required or permitted for this workflow.**

Core's signaling port is published to host loopback. Host nginx proxies that published port. Ingress and egress reach core using `ws://livekit:<signal_port>` on the bridge. Container access to host Redis uses `host.docker.internal` plus `host-gateway`; this is infrastructure connectivity and does not change the Android API URL. The host Redis listener and ACL/firewall must allow that connection. A Redis listener bound only to host loopback may still be inaccessible from Docker on some platforms; verify authenticated server access rather than disabling Redis authentication/protected mode.

The local Redis server is version 5 and supports password-only `AUTH`. The generator omits the canonical `default` username while preserving the configured password and database, so this server does not receive the unsupported two-argument ACL form of `AUTH`. A configured custom Redis username remains intact and requires a Redis server that supports named ACL users. This compatibility adjustment does not disable authentication.

| Traffic | Actual configured field | Exposure |
| --- | --- | --- |
| HTTPS/WSS | Existing nginx HTTPS listener, locally 443/TCP | Clients connect to `app.test`; HTTPS terminates at nginx |
| LiveKit HTTP/API/WebSocket | `signal_port`, locally 7880/TCP | `127.0.0.1:<signal_port>` only; proxy from host nginx |
| RTC TCP | `rtc_tcp_port`, locally 7881/TCP | Publish and allow from intended participants |
| RTC UDP | `rtc_udp_port_start`–`rtc_udp_port_end` | Publish the **actual** configured range and allow it in host/network firewalls |
| UDP mux, if explicitly configured | Advanced `rtc.udp_port` | Replaces the range for LiveKit media; keep Compose in agreement |
| TURN UDP | `turn_udp_port`, locally 3478/UDP when enabled | Publish and allow; this is not RTC TCP |
| TURN relay UDP | Effective advanced `turn.relay_range_start`–`relay_range_end` | Publish and allow separately from the TURN listener and RTC range; relay allocations use these sockets |
| TURN TLS, if enabled | `turn_tls_port`, domain/certificate settings | Publish configured TCP listener; currently disabled locally |
| RTMP ingress | `rtmp_port`, locally 1936/TCP | Publish only when using an external encoder |
| Egress health | Existing 7981/TCP backend contract | Host loopback only; Laravel checks this listener |
| Redis | Actual `redis_host`/`redis_port`, locally 6379/TCP | Private service access; never expose unauthenticated Redis publicly |

The repository default UDP range is 50000–60000. The local acceptance environment may use a narrower range through Admin settings to avoid publishing thousands of Docker Desktop ports; inspect the generated settings rather than substituting example numbers. Keep `rtc.node_ip`/`use_external_ip` appropriate to the installation. A Docker private IP or unreachable LAN IP advertised to participants produces signaling success followed by media failure. Ordinary nginx HTTP proxying does not forward RTC UDP. [LiveKit's port reference](https://docs.livekit.io/transport/self-hosting/ports-firewall/) explains the separate transports; [Docker's publishing reference](https://docs.docker.com/engine/network/port-publishing/) describes bridge port mapping.

The current constrained local deployment uses RTC UDP **50000–50063**, TURN relay UDP **50064–50127**, UDP listener **3478**, RTC TCP **7881** and host-private signaling **7880**. These are actual Admin-generated values, not production defaults. The generator and health verifier apply the same effective merge: basic fields take precedence, advanced UDP mux/additional listeners remain, and overlapping UDP sockets need one matching published mapping. If either explicit relay bound is unset/zero, the pinned server/generator fallback is 30000–40000, or 30000–30002 with development mode enabled; the repository's populated advanced default is 1024–30000. A listener-only mapping is insufficient. Firewall and restricted-peer allowlists must match the intended actual media peers; never permit arbitrary private peers solely to make a relay test pass.

After generating the runtime file, validate and start core from the PlayHub project root:

```powershell
docker compose -f livekit/runtime/docker-compose.yml config --quiet
docker compose -f livekit/runtime/docker-compose.yml up -d livekit
```

For configured optional workflows, start only the needed service:

```powershell
docker compose -f livekit/runtime/docker-compose.yml up -d egress
docker compose -f livekit/runtime/docker-compose.yml up -d ingress
```

No credentials belong in those commands. Verify containers remain running without increasing restart counts and that configuration/key mounts are readable. Do not treat a cached Docker-status JSON file as current proof. The Linux `livekit/install.sh` and `playhub-livekit-manager@.service` automate a full installation/trigger manager; they are not Windows service installers. On Windows, use the generated Compose definition directly and the existing Admin diagnostics; stale manager status must be distinguished from the current container state.

### Reproduced Windows Docker Desktop issues

Docker Desktop presents the Windows bind-mounted `keys.yaml` with a Linux mode equivalent to 0777. LiveKit correctly rejects that key file because it requires private permissions. The entrypoint copies the existing server key file into the container's private `/tmp` tmpfs with mode 0600 and passes that private copy to LiveKit. The source stays read-only; no key is generated or logged. Do not weaken LiveKit's permission check or make host credentials world-writable. Restrict the Windows source file ACL to the application/service owner as well.

Active individual-file Docker bind mounts can also prevent Laravel from atomically replacing runtime config/entrypoint files on Windows, producing a configuration-save error. Stop only this project's Live containers before saving new Admin configuration, then recreate the configured services:

```powershell
docker compose -f livekit/runtime/docker-compose.yml stop egress ingress livekit
# Save the intended configuration through the existing authenticated PlayHub Admin UI/API.
docker compose -f livekit/runtime/docker-compose.yml up -d --force-recreate livekit
```

Restart optional egress/ingress separately when required and resources permit. Stopping core interrupts current Lives, so schedule configuration maintenance accordingly. Do not stop unrelated containers, replace atomic writes with unsafe in-place credential writes, or disable file-permission protection. A read-only directory bind for the Live runtime configuration is a possible future way to avoid individual-file rename locks; it must preserve configured container paths and separately mounted writable logs/output. This document does not claim that alternative has been validated.

### Disk capacity and recovery

The local acceptance computer ran out of space on the drive containing Docker Desktop's Linux disk while downloading services; only about 30 MB remained and the daemon reported a read-only filesystem. The pinned Egress image download initially appeared complete, but its startup/account/binary files were empty. Space was subsequently recovered and the affected owned image repaired before the development trust image was built. A downloaded image cannot prove a healthy service or writable recording storage; require both integrity and capacity checks after any recurrence.

Provide at least 10 GB of free host-drive headroom for this local recovery, plus capacity for Docker image extraction, the Linux disk and expected recordings. The exact production allowance depends on retained media, concurrency and the customer's backup policy. Free known owner-selected files or use Docker Desktop's supported **Settings → Resources → Advanced → Disk image location** workflow to move its data to a drive with enough space. Preserve existing images, containers, volumes and customer data; the move may require a controlled Docker Desktop restart.

Do not reset Docker Desktop, delete its VHDX/WSL distribution, run broad image/system/volume prune commands, or remove customer recordings to clear the blocker. Stop new image downloads and recording attempts while the drive is full. If unrelated containers or active Lives exist, arrange their maintenance window before restarting Docker. After the owner restores capacity, verify host free space, daemon readiness and writable filesystem state, then validate/recreate only this project's needed services and rerun the read-only health verifier. Recording still needs a real finalized artifact and replay test.

## Existing nginx WSS route

Include `livekit/nginx-app-test-location.conf` inside the existing `app.test` nginx server for local acceptance, adapting its upstream **only if the actual configured signal port differs**. It already defines `/livekit` and `/livekit/`; do not add a second competing location or replace Laravel/backend routing. It sets HTTP/1.1, Upgrade, Connection and forwarded headers, and disables join-URL access logging. Runtime files are denied, including alternate-case paths on Windows.

Validate nginx's current configuration before reloading. Keep existing local certificates. A 502 means the proxy could not reach its upstream; trace configured route → published host port → container listener. A matched direct/proxied 401 authentication response proves that an unauthenticated upgrade request reaches LiveKit, not that a host/viewer token or media connection has passed. With the Laravel admission guard enabled, missing credentials instead receive 403 before reaching core; this is expected and requires a real PlayHub-generated grant to prove the protected route's upstream handshake. Direct core signaling must stay host-private.

The included guard uses nginx's `auth_request` module and the private `/__playhub_livekit_admission` FastCGI subrequest to Laravel's `/api/livekit/admission`. It covers `/livekit/rtc`, `/rtc/validate`, `/rtc/v1` and `/rtc/v1/validate` beneath the configured LiveKit prefix; server TWIRP APIs remain separate. The action returns only admission success/denial, validates the signed JWT and current stream/account/stage authorization, and checks server-held identity versions so cached kicked/revoked grants remain unusable after a server token refresh. The local nginx build includes `http_auth_request_module`. On other installations, verify that module and adapt the existing PHP upstream/document root appropriately; never expose a bypass route to the core join endpoint. Verify these actions with cached-token rejoin tests through deployed nginx, not merely Laravel tests.

When a signaling request includes both a query token and a Bearer token, they must match. Nginx supplies the exact admitted credential as upstream `Authorization`, so LiveKit cannot select a different token from the request body. The Windows include disables access logs and sends error logs to `NUL`, because upstream errors can otherwise contain a token-bearing request URI. On Unix, change that sink to `/dev/null` alongside the installation's PHP upstream and document-root changes; never enable logging of signaling query tokens.

## Windows worker and scheduler

From the project root, use the existing worker helper and the scheduler helper:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File tools/start-queue-worker.ps1
powershell -NoProfile -ExecutionPolicy Bypass -File tools/start-scheduler.ps1
```

Both accept `-PhpBinary` for the actual PHP executable and `-Foreground` for supervised terminal operation. Background helpers launch hidden and write process IDs and stdout/stderr under `storage/app` and `storage/logs`. Both launch new processes with the absolute project `artisan` path and verify the executable, that path and the expected worker/scheduler command before accepting a stored PID. The queue helper preserves the existing `videos,default` queue arguments. Do not stop an arbitrary process solely because its PID appears in a file.

A legacy queue process launched with relative `artisan` cannot be independently proved to belong to this project from that command line after PID reuse. The updated helper refuses to treat such a live PHP worker as verified and refuses to spawn a duplicate. Verify it through the original supervised launch and arrange a controlled restart only after its current job finishes. Do not kill an active FFmpeg/customer media job merely to obtain a new absolute-path process. The current local worker has been independently verified with its absolute project path and expected queue arguments.

These helpers are local development processes, not automatic startup services. For a customer installation, supervise/restart workers and schedule execution under an appropriate service account. On Windows, configure a project-specific Task Scheduler task or service according to the customer's operational policy; the helpers do not modify global tasks. Schedule `php <absolute-project-artisan> schedule:run` every minute, or supervise one project `schedule:work` process. Verify the task's actual run history and a safe scheduled fixture's effect, not merely task registration.

On Linux, use the existing installer/systemd worker or another process supervisor and Laravel's cron convention:

```cron
* * * * * cd /absolute/playhub/project && /absolute/php artisan schedule:run >> /dev/null 2>&1
```

The local worker timeout is 21600 seconds and `DB_QUEUE_RETRY_AFTER=21660` currently exceeds it. Keep the configured connection's retry interval greater than the longest job timeout; the Redis connection's repository fallback is only 90 seconds unless overridden. This avoids a second worker reserving a long media-processing job while the first still runs. Windows CLI process timeouts and actual fixture completion still need operational validation.

### Live jobs and actual scheduling behavior

| Work | Producer/queue or schedule | Required evidence |
| --- | --- | --- |
| Live thumbnail | `GenerateLiveStreamThumbnail`, `videos`, dispatched after activation | Worker executes; artifact or a clear service-unavailable result |
| Ended recording finalization | `FinalizeLiveStreamReplay`, `videos`, dispatched on End and recovered each minute by `finalize-live-stream-replays` | Stable finalized MP4, replay record and follow-up processing |
| Replay transcoding/notifications | Existing `ProcessVideoUpload`, `videos` | Media ready, real duration/renditions, intended replay notification |
| Poll scheduling/closing | `activate-scheduled-live-polls`, every minute | Due poll becomes active only while its stream is live; closes when due |
| Stale webcam/RTMP publishers and pending room cleanup | `reconcile-live-stream-publishers`, every minute | Actual missing-source grace/outage policy; no permanent ghost stream |
| Expired replay deletion | `prune-expired-live-replays`, hourly | Fixture retention expires and its own artifacts/record are removed |

A scheduled Live appears in Upcoming with its configured time. The scheduler does **not** invent camera/microphone publishing or automatically start a creator's phone. Start eligibility follows the actual product flow; recording begins only after a real publisher is detected. Current Live invitations/moderation notifications are written to the backend notification model, and moderator email is sent by the existing request path. There is no implemented Live reminder producer to claim as deployed.

## Read-only health verifier

Node 22 or newer and access to Docker's local CLI are sufficient:

```powershell
node tools/live-health.cjs --json
```

For this local certificate, supply the trusted development root CA without disabling TLS verification:

```powershell
node tools/live-health.cjs --ca-file react-native-app/assets/certificates/playhub-local-acceptance-ca.cer --json
```

`--ca-file` accepts PEM or DER X.509; `--docker-binary` accepts the installed CLI path and `--root` accepts another PlayHub project directory. The default timeout is bounded at three seconds per probe. The verifier reads the actual generated runtime settings, emits only operational fields, never starts/stops services, never processes jobs, and never prints config bodies, credential values, tokens, raw Docker inspect output or HTTP error bodies.

Checks include running/restart observation, actual published ports (including every separately configured TURN relay UDP socket), core `OK`, direct versus proxied unauthenticated WebSocket response, RTC TCP, Redis listener, a matching read-only UDP STUN response, configured TURN TLS handshake, optional egress/ingress listeners, and sanitized project PHP process observations. A valid STUN reply is UDP listener evidence, **not** TURN allocation/authentication or selected relay-media evidence. The explicit relay-publishing result also leaves authenticated allocation and actual selected relay media unverified. A process observation is **not** proof a job completed. Cron/Task Scheduler execution must be checked separately.

Windows process checks require readable CIM command lines to prove the absolute project `artisan` path and `videos,default` queue arguments. An unavailable inventory or unreadable PHP command line is reported as unverified visibility, rather than an absent worker; inspect the existing service/helper with appropriate read access. Do not start a duplicate worker to clear such a warning. The verifier never emits the inspected command lines.

The existing Admin diagnostics can additionally be probed by setting an ephemeral `PLAYHUB_LIVE_HEALTH_ADMIN_TOKEN` in the current process environment through secure local tooling. Never paste it into chat, a command-line argument or a committed file. The script then calls the existing admin-only `/api/admin/livekit-status` and `/api/admin/livekit-test` endpoints; the latter uses Laravel's server-held credentials for authenticated `ListRooms`. `--admin-base` changes only the diagnostics API origin/path, not the mobile API. Clear the ephemeral environment variable afterward. The verifier does not log in or issue media tokens.

Statuses are `PASS`, `FAIL`, `WARN`, `NOT_CONFIGURED` and `UNVERIFIED`. A required operational failure exits 1. A successful operational report still leaves `connected acceptance` explicitly unverified; it is never the final Android acceptance decision. Run its regression suite with `node --test tools/live-health.test.cjs`.

## Connected acceptance, TURN and revocation

Use the Pixel and a separate PlayHub web account/session to prove both Android Host → Web Viewer and Web Host → Android Viewer. Observe real video/audio and selected ICE candidate; verify chat, reactions, independent viewer count, guest acceptance/media/removal, moderation, polls, background cleanup, reconnect and End against the same backend stream. Compare test-only gift/tip receipt, one wallet debit and creator net earnings; do not spend real money or delete legitimate transactions.

For TURN, record UDP/TCP/TLS separately. Use an authenticated relay-only diagnostic or a network path that requires relay and inspect the selected `relay` candidate. A listener, populated ICE settings or signaling success cannot mark relay acceptance PASS. LiveKit's embedded TURN uses signaling-linked authentication; never publish reusable TURN credentials in docs. [LiveKit's deployment guide](https://docs.livekit.io/transport/self-hosting/deployment/) describes this authentication and TLS configuration.

Laravel rejects fresh grants after bans, removal/revocation and End, and server room APIs disconnect/unpublish connected participants. Self-hosted LiveKit does not invalidate already-issued JWTs when `RemoveParticipant`/`UpdateParticipant` runs; refreshed credentials can outlive the original brief issuance TTL. The signaling admission guard enforces current Laravel authorization and identity versions before a cached token can rejoin. A room deletion plus auto-create could otherwise recreate an ended room. Do not describe Cloud-only `revoke_token_ts` as a self-hosted revocation solution. Test cached-token rejoin after kick, ban, guest-publish revocation and End through the actual deployed nginx route; keep physical revocation acceptance unverified until those checks pass. Backend server-action failures must be reported/retried rather than treated as successful connected enforcement. [LiveKit's token lifecycle reference](https://docs.livekit.io/frontends/reference/tokens-grants/) documents this limitation.

## Recording and replay acceptance

PlayHub uses LiveKit Room Composite Egress, its existing `/livekit-recording-template`, and the mounted `uploads/live-streams` output directory. Egress must resolve/reach the app template and signaling origin with trusted TLS, connect to configured Redis, write the actual output, and have sufficient CPU/memory. Private runtime keys must remain unreadable from the public web server.

### Development Egress DNS and local CA trust

The optional local acceptance overlay is prepared under `.cache/live-acceptance/egress-trust/`, separate from generated runtime settings and production deployment files. Its build context contains only the Dockerfile, support scripts and the existing **public** development CA certificate. It never contains the CA private key, Laravel `.env`, LiveKit secrets, Redis credentials or recording tokens. Keep this tooling ignored and never publish its development image.

The preparation helper `.cache/live-acceptance/prepare-egress-trust.ps1` validates the accepted CA fingerprint and validity dates, converts the repository DER certificate to PEM, and writes the isolated Compose overlay. It does not download, build or start anything. The overlay adds only `app.test:host-gateway` for Egress and a separate derived development image. Android continues to use exactly `https://app.test/api/v1`; core/Redis/output mounts remain the Admin-generated configuration.

The derived image retains `livekit/egress:v1.13.0` as its base and installs `libnss3-tools` inside that image to import the CA normally into the OS certificate bundle and Egress's Chrome NSS databases. Since Chromium M146 uses `$HOME/.local/share/pki/nssdb` while an existing `$HOME/.pki/nssdb` takes precedence, both image-owned databases receive SSL server-CA trust (`C,,`). No certificate-error flags, hostname-verification bypass or release trust changes are introduced. The final container user stays `egress`; a small preflight checks readable configuration and an exclusive temporary `/out` write, then executes the image's original `/entrypoint.sh`. [Chromium's Linux certificate documentation](https://chromium.googlesource.com/chromium/src/+/HEAD/docs/linux/cert_management.md) describes these NSS paths and trust flags.

Before building, confirm the cached base is intact: the `egress` account must exist and `/entrypoint.sh`, `/bin/egress` and `/tini` must be nonempty executables. A reproduced disk-full incident left those files empty even though Docker reported a cached image ID. The Dockerfile rejects that corruption before installing dependencies. Repair only the affected owned image/cache after storage recovery; do not recreate its account, run Egress as root, reset Docker Desktop, or prune unrelated images/volumes to conceal the failure. The inspected image size is about 1.28 GiB uncompressed; reserve additional pull/extraction/build and recording headroom.

After the base-image integrity and disk-capacity checks, the owner can review the overlay and perform the isolated build/start:

```powershell
powershell -NoProfile -File .cache/live-acceptance/prepare-egress-trust.ps1
docker compose -f livekit/runtime/docker-compose.yml -f .cache/live-acceptance/egress-trust/compose.egress-local-ca.yml config --quiet
docker compose -f livekit/runtime/docker-compose.yml -f .cache/live-acceptance/egress-trust/compose.egress-local-ca.yml build egress
docker compose -f livekit/runtime/docker-compose.yml -f .cache/live-acceptance/egress-trust/compose.egress-local-ca.yml up -d --no-deps egress
```

The build requires ordinary package-repository access for the isolated NSS tools. The existing media output directory must permit the original Egress account/group to create artifacts and the actual Laravel queue worker to read them; use deliberate directory ownership/ACLs, not recursive world-write permissions. Then verify trusted HTTPS to the actual template inside Egress, recording media/start/end and the finished artifact. Health, DNS resolution and imported certificates alone are insufficient acceptance evidence.

Egress appends a recording token to the template query. Keep that route's access/error logging disabled and send `Cache-Control: private, no-store` plus `Referrer-Policy: no-referrer`. Do not print raw Egress/Chrome log lines: browser startup and errors can include token-bearing URLs. Diagnostic output should contain only categorical counts/statuses such as certificate failure, name-resolution failure, `START_RECORDING`, `END_RECORDING` and recording completion/failure.

The trusted local image was subsequently built and started by the owner. The operational snapshot at **2026-10-02 22:12:46 UTC** observed core and Egress running with zero restarts, expected core HTTP `OK`, all configured RTC/listener/relay mappings present, a matching UDP STUN response, host-loopback Egress health 7981, the absolute-project `videos,default` queue worker and project scheduler. An earlier queue warning was a verifier regex-escaping defect, now covered by generated PowerShell regression tests; the worker was independently confirmed running. Overall health stayed `WARN`: the unauthenticated WSS denial requires a real authorized grant and selected media paths remain unverified by this tool. The owner's separate Egress template preflight reported HTTP 200 with TLS verification result 0, and a real queued Live thumbnail completed. These operational observations do not substitute for the finished recording artifact/replay or final connected acceptance ledger.

For a short safe fixture: confirm actual recording begins after source publication, End the stream, confirm finalized artifact exists and is stable, allow `FinalizeLiveStreamReplay`/`ProcessVideoUpload` to produce a ready replay, then play/seek it on Pixel using the frozen `PlayHubPlayer`. Database `recording` flags or the 7981 health listener are insufficient. Clean fixture-owned stream/chat/polls/users/media and test financial entries with existing fixture tooling; preserve genuine records and transactions.

Ingress is a separate RTMP/OBS workflow. Configure the existing owner-only ingress fields and confirm real encoder publication before marking the stream live. Failure to run optional ingress does not by itself block native mobile camera-host acceptance; report RTMP separately.

The owner subsequently started the actual pinned `livekit/ingress:v1.5.0` service on the generated bridge network with RTMP port 1936 and observed zero restarts. The configured web RTMP base is `rtmp://app.test:1936/live`. This is service evidence only; the encoder-to-Ingress-to-independent-viewer flow has **not yet been accepted**.

The ignored `.cache/live-acceptance/rtmp-encoder.cjs` helper prepares a bounded synthetic test using the configured Windows FFmpeg executable. Its default mode performs no requests or encoding. Explicit execution through the existing environment-only fixture wrapper requires `--execute --stream-id <owned-fixture-id>` after the owner has created the RTMP stream through the actual web UI and recorded its ID in the current isolated ledger. It verifies the fixture host, current studio stream, source and service availability before requesting the real owner-only `POST /api/live-streams/{id}/rtmp-ingress` with no request body. It never creates a stream or marks one live.

The returned URL/key remain in memory and reach only the directly spawned FFmpeg child's output argument; no shell is used. Protect local process-inspection access because an authorized OS user can inspect transient process arguments. No credentials are written to a file, printed or inherited as acceptance environment variables by FFmpeg. Raw process output is suppressed; only fixed event names and whitelisted numeric progress are emitted. The source is real-time 854×480 at 30 fps with 440 Hz audio, H.264 at 500 kbps and AAC at 96 kbps for 30 seconds. A 40-second deadline or cancellation stops only that owned child. No media file is created by this encoder; any server recording follows the actual configured Live workflow. Successful FFmpeg completion still requires server-confirmed ingress publication and independent viewer video/audio acceptance.
