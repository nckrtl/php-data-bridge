# Review verification

Checked against the full-tree review in `t3code-263a7a34/code-review/README.md`.
The review changes were implemented in this worktree. Preflight and the subsequent authorized Beast deployment are recorded below.

| Finding | Result |
| --- | --- |
| 01 — worker state | **Rejected for the supported SAPIs.** PHP resets userland classes, statics, constants and `require_once` state between FPM and built-in-server requests. An isolated one-worker FPM test and repeated HTTP tests confirm this, including after `exit`. Calling prepend twice within a single PHP request would not reproduce FPM request lifetime. Corrected the misleading comment and made recorder/config initialization explicit for tests. Persistent application servers remain outside the supported SAPIs. |
| 02 — sensitive defaults | **Fixed.** Mandatory sensitive-header and cookie redaction; bindings, URL query strings and view data hidden by default. Custom header names extend the defaults. SQL literals and arbitrary application fields can still contain secrets. |
| 03 — history access | **Restricted to enabled development apps.** The installed bridge checks app/pool opt-outs, actual framework environment and GET/HEAD before file access. Client-IP restrictions are intentionally omitted to support development nodes reached over LAN or VPN. History is resolved on response send after app boot, rather than in prepend. No separate token or session protocol was added; existing native consumers remain compatible. Network admission belongs to the host platform; the bridge does not add per-user history authentication. |
| 04 — environment | **Fixed.** Both adapters use the same development allowlist; missing Symfony kernel and unknown environments deny collection. Enabling collection never overrides a disallowed environment. |
| 05 — caching | **Fixed.** Telemetry responses are private/no-store and lose ETag, Last-Modified and Expires. Injection updates Content-Length. |
| 06 — temp storage | **Fixed.** Per-UID root; root and app directory must be real, owned by the effective UID and exactly 0700. Atomic 0600 files and checked reads. History is unavailable without POSIX ownership support. Apps under the same OS user remain in the same trust boundary. |
| 07 — installer paths | **Fixed with qualification.** Strict version grammar, archive path/type validation before extraction, no links/special files, HTTPS-only redirects. The review overstates GNU tar's default traversal behavior. A checksum beside an archive detects corruption, not a compromised publisher; independent signing requires a release trust/distribution design and was not added. |
| 08 — disabled hooks | **Fixed.** Bootstrap probes determine eligibility before expensive callbacks; disabled query/model/view hooks do no work. Queries and backtraces stop at 500 stored queries, with a dropped count. Laravel's environment becomes reliable after LoadConfiguration, not at the start of Kernel::handle. |
| 09 — body size | **Partly valid; fixed supported issues.** Standard HttpFoundation getContent returns an existing string; PHP copy-on-write means the cast does not itself create the alleged full extra copy. Prefer Content-Length, skip streams/files/attachments/bodyless or encoded responses, and avoid injecting into HTML over 1 MiB. |
| 10 — storage cost | **Mitigated.** 1 MiB file cap, atomic publication, one mtime lookup per file, nonblocking prune lock and shutdown pruning. Header size is checked before base64 encoding. Saving remains synchronous so immediately requested history exists; shutdown still consumes worker time. |
| 11 — application side effects | **Fixed.** Shared bounded value normalization never casts arbitrary Stringable objects. Symfony does not load the router collection; absent route paths remain null. Optional Laravel middleware and Inertia metadata failures do not discard the core payload. |
| 12 — reinstall gap | **Fixed.** Every install gets a new complete directory. The current symlink is atomically renamed; old directories remain available. Copy-failure tests confirm the old target stays live. |
| 13 — macOS checksum | **Fixed.** shasum fallback with strict digest validation; missing tools or mismatch fail installation. |
| 14 — Homebrew service | **Fixed.** Match formula name and binary identity, then restart that service as the invoking user. No guessed master PID is signaled. Homebrew restart can still interrupt requests, and is documented. |
| 15 — CSP | **Fixed by header fallback.** For header or meta CSP, preserve the body and policy and send telemetry in headers. No generic Symfony nonce attribute exists that the bridge can safely assume. Native consumers work; page event interception is not installed on these pages. |
| 16 — bindings | **Fixed.** Shared normalization preserves names, dates and scalar types. Duplicate detection runs before output redaction. Doctrine itself can turn named placeholders into positional parameters before its post hook. |
| 17 — ignored paths | **Addressed.** Match both raw and once-decoded normalized paths, remove dot segments and trailing slashes. Keep case-sensitive behavior and document wildcard semantics and the lack of an access-control guarantee. |
| 18 — all sites enabled | **Per-app opt-in rejected.** Installing the extension and prepend script is the explicit opt-in. Collection defaults on for supported apps that pass the environment check. `collect.enabled: false` opts an app out; `PHP_DATA_BRIDGE=off` disables the bridge regardless of app config. |
| 19 — foreign headers | **Fixed.** Fetch and XHR check the final response origin, skip the history path, and cap header decoding at 32 KiB. |
| 20 — regression coverage | **Added.** PHP boundary tests, real extension HTTP tests with Laravel/Symfony/Doctrine/Twig, isolated FPM lifetime test, client tests and sandboxed installer tests. Full deployed applications and live service restarts were not tested. |
| 21 — memory label | **Fixed compatibly.** Emit `memory_delta` and retain the old alias. Neither memory_get_usage(true) nor its peak variant is process RSS; documentation now distinguishes allocator pages from emalloc usage. |

Validation passed on PHP 8.5.8: PHP syntax, PHP boundary tests, real HTTP hook tests, isolated FPM request-state test, JavaScript client tests, installer tests, Bash syntax and ShellCheck. Test dependencies resolved to Laravel 12.69.3, Symfony HttpKernel/HttpFoundation 7.4.20, Doctrine DBAL 4.5.0 and Twig 3.30.0.


## Orbit network verification

The loopback requirement was removed to support Orbit's multi-node development setup. Unit
checks cover LAN, VPN, IPv6 and absent client addresses. Laravel/Symfony HTTP tests simulate
a non-loopback `REMOTE_ADDR` before prepend runs and verify both telemetry and history access;
production denial and explicit opt-outs still pass.

Read-only checks on the running setup confirmed that `php-data-bridge-demo.test` resolves on
the Mac to Beast at `192.168.6.20`. Beast's live Caddy site binds `192.168.6.20` and `10.44.0.7`,
serves `/fast/apps/php-data-bridge-demo/default/public`, and uses the FPM Unix socket
`/run/php/orbit-app-instance-386.sock`. A request from the Mac returned HTTP 200, Symfony
`dev` telemetry with client IP `192.168.1.180`, and three queries. Fetching that request's
history through the same domain returned HTTP 200 with the matching request ID.

That live check exercised Beast's existing bridge 0.2.0, not this modified worktree. No
remote installation, Caddy configuration, FPM configuration, or service was changed.

## Beast pre-deploy checks

Prepared version 0.3.0. A regression test exposed a remaining coexistence bug: the bridge
could replace the history response of a Laravel app that already had the toolbar package.
The send hook now stands aside for that package, including its history endpoint; the new
regression test passes.

The final candidate was copied into a temporary directory on Beast and exercised through
isolated, single-worker FPM pools against its existing Symfony 8.1.8 demo and Laravel 13.24.0
app. Both PHP 8.4.24 and 8.5.9 passed repeated requests, non-loopback client addressing,
three captured queries per request, default binding redaction and matching history responses.
The candidate prepend path was checked in the telemetry. No bridge errors were logged.
The installer regression suite also passed on Beast's Linux host with a temporary prefix.
Temporary pools were stopped; installed services and the live bridge symlink were unchanged.

Both installed PHP 8.4 and 8.5 FPM configurations reference the shared bridge `current`
symlink, so deployment must account for both. The current rollback target is
`/usr/local/share/php-data-bridge/0.2.0`. History uses a new directory layout, so previous
history is not migrated. Bindings and page data are redacted by default; their config flags
can explicitly restore collection when wanted.


## Beast deployment — 2026-10-11

Deployed version 0.3.0 from this worktree after user approval. The source archive SHA-256 is
`e7fc90ffb923325bb985f7160530f1ac0e19e412313c94c71034fd32546edb1c`.
The archive checksum was verified on Beast before installation; the installed bridge tree
was then compared with the uploaded tree and matched exactly.

- Current target: `/usr/local/share/php-data-bridge/0.3.0.install-dirpo3FC`.
- Rollback target retained: `/usr/local/share/php-data-bridge/0.2.0`.
- PHP 8.4 and 8.5 FPM configuration checks passed; both services reloaded and remained active.
- Requests through the live PHP 8.4.24 and 8.5.9 pools returned bridge 0.3.0 with three queries
  and saved history. Symfony's normal info/debug FastCGI output was not treated as a bridge error.
- From the Mac, the Symfony demo `/` and Laravel training app `/pin` returned HTTP 200,
  bridge 0.3.0, three queries, `Cache-Control: no-store, private`, and matching HTTP 200
  history responses. Laravel `/up` also passed with its expected zero queries.
- Ordinary HTML requests to both apps contained the 0.3.0 payload with cookie values and
  view data redacted. Header requests passed separately.
- The archive checksum record is saved as `SOURCE_ARCHIVE_SHA256SUMS` in the new release.

No Caddy routing changes were needed. Rollback consists of atomically repointing `current`
to the retained 0.2.0 directory and reloading both FPM services. Deployment staging files
were removed after verification.
