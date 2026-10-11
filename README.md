# PHP Data Bridge

Profiling data for PHP apps, without a package in the app. PHP loads the bridge before the
app (`auto_prepend_file`). The bridge hooks framework methods through the
[OpenTelemetry extension](https://github.com/open-telemetry/opentelemetry-php-instrumentation)
and sends the payload of [nckrtl/laravel-toolbar](https://github.com/nckrtl/laravel-toolbar),
so T3 Code shows it as it shows the package's data:

- HTML pages get `window.__LARAVEL_TOOLBAR_DATA__` and a small script that turns
  `x-toolbar` headers into `laravel-toolbar:update` events.
- XHR, Inertia and Livewire responses, non-HTML responses (JSON APIs), and requests
  that send an `X-Toolbar` header get an `x-toolbar` header. API clients such as Yaak send
  `X-Toolbar` to get the data without a script in an HTML body. Payloads over 32 KB send an
  `x-toolbar-summary` row instead.
- `GET` or `HEAD /_toolbar/requests/{id}` returns earlier requests after the framework boots
  and the bridge checks its environment. The app still handles the request before the bridge
  replaces its response. Disabled apps do not expose stored payloads.
- HTML with a CSP header or meta policy gets telemetry in headers. The bridge does not inject
  a script or change the policy. Native header consumers still work; the page event client is
  not installed on these pages.
- Streams, file downloads, attachments, encoded bodies, HEAD, and bodyless responses are skipped.

The bridge does nothing:

- without the OpenTelemetry extension, or with `PHP_DATA_BRIDGE=off`,
- when the app sets `collect.enabled: false`,
- outside PHP-FPM and the built-in server (so not for the CLI),
- for requests no supported framework handles,
- when Laravel has `nckrtl/laravel-toolbar`, or the framework environment is outside
  `local`, `dev`, `development`, `testing`, and `test`. An unknown environment is off.

Enabled apps install a few bootstrap probes to learn the framework environment. Query,
model, and view callbacks return immediately until the environment is allowed.

The bridge is for development machines, not production servers. The OpenTelemetry extension
turns PHP's JIT off once loaded, even where the bridge does nothing.
The bridge accepts requests across development nodes, including LAN and VPN connections.
Network access is controlled by the host platform, such as Orbit’s private routing and listener
rules. The bridge adds no client-IP restriction or per-user authentication to history.
Run separate OS users/pools for apps that must not share access to history files.

With Orbit, your Mac resolves the app domain to a Router or workload Node on the LAN or
WireGuard network. Caddy routes the request to the app’s PHP-FPM pool. Its Unix socket is
local to the Node; the HTTP client can still be another machine. Telemetry returns in the
normal app response, and history uses the same app domain. No separate bridge port is needed.

Errors in the bridge go to the PHP error log, prefixed `[php-data-bridge]`. They never reach the app.

## What it collects

Core, for any framework:

- Wall time and peak PHP memory (allocator pages and emalloc usage; neither is process RSS).
- Request and response headers, cookies, status and size.
- PHP, OPcache and PHP-FPM pool details.

Laravel adapter:

- Request stages with wall time and allocated memory: bootstrapping, service providers,
  middleware in, controller, view rendering, middleware out, preparing response.
- Route name, URI, action and middleware.
- Queries with redacted bindings, duration, duplicates, slow queries and the app file and line.
- Eloquent models: retrieved, created, updated, deleted.
- Inertia component and props: shared, deferred, merge, scroll and once.
- Livewire updates in the request history.

Symfony adapter:

- Request stages: bootstrapping, booting the kernel, request listeners (routing, security),
  controller, Twig rendering, response listeners, preparing the response.
- Route name and controller; route path only when `_route_path` is already on the request.
- Doctrine DBAL queries with redacted bindings, duration, duplicates and the app file and line.
- Doctrine entities: loaded, scheduled for insert, scheduled for delete.
- The first Twig template; opt-in context capture uses class names for objects.
- Framework details under `framework`. The toolbars show a `laravel` section only, so they
  need a small change to show it.

Not yet: redirect chains, Blade view names, editor links, prop source locations, other frameworks.

## Layout

- `bridge/prepend.php`: the entry point PHP loads.
- `bridge/src/`: the core. `Bridge` registers hooks, `Recorder` keeps what they saw,
  `Payload` builds and sends the data, `Store` keeps earlier requests, `Config` reads the app's settings.
- `bridge/src/Adapters/`: one class per framework, implementing `Adapter`.

## App settings

Installing OpenTelemetry and configuring the bridge's `auto_prepend_file` is the opt-in.
Eligible development apps work without an additional config file or environment flag.
An optional `php-data-bridge.json` next to the app's `composer.json` customizes collection:

```json
{
  "collect": { "slow_query_ms": 50, "ignore_paths": ["/horizon/*", "/up"] },
  "redact": { "headers": ["authorization", "cookie"], "props": ["auth.user.email"] }
}
```

- `collect.enabled`: defaults to `true`; set it to `false` to exclude this app.
  `PHP_DATA_BRIDGE=off` always disables the bridge, even when `collect.enabled` is `true`.
- `collect.slow_query_ms`: marks queries at least this slow (default 100).
- `collect.ignore_paths`: case-sensitive shell patterns; `*` crosses slashes. Checks both the
  raw path and a path decoded once with dot segments and trailing slashes removed. This is
  a work filter, not an access-control list; router normalization can differ.
- `redact.headers`: additional header names to hide. Cookie, authorization, proxy authorization,
  PHP auth password, CSRF/XSRF token and API key headers are always hidden. Cookie values
  are always hidden, including HttpOnly cookies.
- `redact.bindings`, `redact.query_string`, `redact.view_data`: default `true`. Set an individual
  key to `false` to expose that data deliberately on your local app. Query strings are also
  removed from Referer, Location, Content-Location, and redirect targets.
- `redact.props`: additional page props to hide as dot paths when view data is enabled.

SQL text is retained and can contain literal secrets. Custom headers and URL paths may also
contain secrets. Redaction is not a guarantee that a payload contains no sensitive data.
All telemetry responses use `Cache-Control: private, no-store` and drop cache validators.
The recorder retains at most 500 queries and reports `queries.truncated_count`. Normalized
values keep at most 100 entries per array, three array levels and 4096 bytes per string.
History files are capped at 1 MiB, saved atomically with mode 0600 in verified 0700 directories.
`history_available: false` means the payload was not saved. Saving stays synchronous so the
history is ready when the response arrives; occasional pruning runs at shutdown under a lock.
Stage `memory_delta` measures emalloc usage; `memory_real_delta` remains as a compatibility alias.

A `tools` section is for the toolbar that shows the data. The bridge ignores it.

## Install

`install.sh` installs the prepend file for every pool of each selected PHP-FPM version.
That installation enables collection for supported apps that pass the environment
check. Use the off switches above to exclude an app or pool:

```bash
./install.sh --source .            # from this checkout
./install.sh --php 8.5 --dry-run   # one version, show only
./install.sh --uninstall
```

Per version it checks that PHP-FPM loads the OpenTelemetry extension (and installs
`phpX.Y-opentelemetry` with apt when missing, for PHP-FPM only), then writes
`99-php-data-bridge.ini` to PHP-FPM's own ini folder and reloads it. The CLI is not touched.
It skips a version that already has another `auto_prepend_file`. It stops before changing
anything when a selected PHP-FPM version has JIT enabled, in its ini files or a pool: disable
JIT first, or leave that version out with `--php`.

The bridge goes to a unique `/usr/local/share/php-data-bridge/<version>.install-*` directory.
The installer switches `current` atomically after copying it. Earlier trees stay in place for
in-flight requests, including on same-version reinstalls. The ini line is written only after the files are in place and removed before them:
PHP refuses every request when that file is missing.

Without `--source`, the script downloads a release from GitHub over HTTPS. That needs published
releases. It checks SHA-256 with `sha256sum` or macOS `shasum` and rejects unsafe archive paths,
links and special files. The checksum detects corruption; it does not independently authenticate
an artifact if the release account is compromised. Homebrew restarts the exact running formula
that owns the selected FPM binary; this may interrupt requests.

## Testing without a web server

`tools/fcgi.php` sends one request to an FPM socket:

```bash
php tools/fcgi.php /run/php/php8.5-fpm.sock /path/to/app/public /some/path "X-Inertia: true" env:PHP_DATA_BRIDGE:off
```


## Regression tests

The test dependencies stay in `tests/vendor`; the bridge still has no runtime Composer dependency.
Use PHP with OpenTelemetry and SQLite, Composer, Node, Python 3, and Bash:

```bash
composer install --working-dir=tests
php tests/run.php
python3 tests/requests.py
node tests/client.cjs
python3 tests/installer.py
python3 tests/fpm-state.py /path/to/php-fpm
shellcheck install.sh
```

The HTTP tests use a temporary local server and real Laravel, Symfony, Doctrine and Twig classes.
The FPM state test uses an isolated single-worker pool without the extension. Installer tests use
a temporary prefix and mocked services; they never change installed PHP or restart live services.
See [the review verification](code-review/verification.md) for the disposition of all 21 findings.
