# PHP Data Bridge

PHP Data Bridge helps you see what happens during a request to your Laravel or Symfony app.
It records database queries, request timing, memory use, and framework details so you can
inspect them in a tool such as T3 Code.

You install it on the machine that runs PHP. You do not need to add a Composer package to
each app or change the app's code. Once installed, it works with supported development apps
on that machine, including apps you open from another computer over a LAN or VPN.

The bridge collects the data. T3 Code or another compatible client displays it; the bridge
does not add its own toolbar interface to your app.

## Install it

Run these commands **on the machine that serves your PHP apps**. For example, if Orbit runs
your apps on Beast, install the bridge on Beast, even if you browse those apps from your Mac.

```bash
git clone https://github.com/nckrtl/php-data-bridge.git
cd php-data-bridge

# Preview the installation for PHP 8.5.
./install.sh --source . --php 8.5 --dry-run

# Install it for PHP 8.5.
./install.sh --source . --php 8.5
```

The installer needs permission to change PHP's configuration and uses `sudo` when needed.
It checks for the [OpenTelemetry PHP extension](https://github.com/open-telemetry/opentelemetry-php-instrumentation),
which lets the bridge observe framework calls. On supported apt-based systems, the installer
can install the extension for you. Otherwise, it tells you to install it with PECL or PIE.

To install for every PHP-FPM version the installer finds, leave out `--php`:

```bash
./install.sh --source .
```

**Installation is the opt-in.** You do not need to create a config file in each app or set
`PHP_DATA_BRIDGE=on`. All pools for the selected PHP version load the bridge, but the bridge
only collects data from supported apps running in a development environment.

### What gets added to PHP's configuration?

The installer creates `99-php-data-bridge.ini` in PHP-FPM's configuration folder. On a
Debian-style PHP 8.5 installation, that is `/etc/php/8.5/fpm/conf.d/99-php-data-bridge.ini`:

```ini
; Load PHP Data Bridge before each app request.
auto_prepend_file = /usr/local/share/php-data-bridge/current/bridge/prepend.php
```

That is the bridge setting. It tells PHP which file to run before the app's own entry point.
The `current` path points to the active bridge installation, so the setting can stay the
same when the bridge is updated.

OpenTelemetry must also be enabled in PHP-FPM, usually in a separate extension INI file:

```ini
extension = opentelemetry.so
```

The extension's package installer may already have added that line. Do not add it a second
time. The bridge installer checks whether the extension is loaded before configuring the
bridge; its `99-php-data-bridge.ini` contains only the prepend setting shown above.

If you configure PHP by hand, these are the settings you need after installing the extension
and placing the bridge files at that path. Reload PHP-FPM to apply them. Use its configuration
folder, not CLI PHP's, and do not replace an existing `auto_prepend_file` used by another tool.

### Which apps will it profile?

The bridge supports **Laravel and Symfony**. It runs with PHP-FPM and PHP's built-in web
server. The installer configures PHP-FPM; it does not change CLI PHP or configure the
built-in server for you.

The framework must report one of these environment names:

```text
local, dev, development, testing, test
```

These names are case-sensitive. Environments such as `production`, `prod`, and `staging`
are excluded. If the bridge cannot determine the environment, it does not collect data.
It waits for the framework's configuration before recording queries, models, or views.

Laravel apps that already use [nckrtl/laravel-toolbar](https://github.com/nckrtl/laravel-toolbar)
keep using that package. The bridge leaves their profiling and history responses alone.

WordPress and other frameworks are not supported yet. Installing the bridge does not add
profiling support for them.

### Turn it off for an app

Create `php-data-bridge.json` beside the app's `composer.json`:

```json
{
  "collect": {
    "enabled": false
  }
}
```

You can also set `PHP_DATA_BRIDGE=off` in the PHP server or FPM pool environment. This always
turns the bridge off, even if an app's config says `enabled: true`. Set this in the server
configuration, rather than relying on the app's `.env`: the bridge starts before the app
loads that file.

### Remove it

```bash
./install.sh --uninstall
```

The installer removes the PHP configuration before removing the bridge files. If you select
only one PHP version with `--php`, it keeps the shared bridge files because other versions
may still use them. The OpenTelemetry extension stays installed; remove it separately if
nothing else needs it.

## Use it

Open a supported development app in T3 Code. The bridge includes profiling data with the
app's normal responses, and T3 Code reads that data using the same format as the Laravel
toolbar package.

For an API client such as Yaak, add this request header:

```http
X-Toolbar: 1
```

For example:

```bash
curl -i -H 'X-Toolbar: 1' https://your-app.test/
```

This asks the bridge to put the profiling data in response headers. It also works for HTML
pages, so an API client can inspect a page without the bridge adding a script to its body.

### Apps on another machine

Your browser and PHP do not need to run on the same machine. There is no localhost-only
restriction, and you do not need to open a separate port for the bridge.

With Orbit, your Mac resolves the app's domain to a Router or the machine serving the app.
Caddy then sends the request to the app's PHP-FPM pool. Profiling data comes back with the
response. Requests for earlier profiling records use that same app domain.

A `.test` address can therefore point to another machine on your LAN or WireGuard network.
The fact that Caddy talks to PHP through a local Unix socket does not make the original
browser request local.

Orbit's routing and network rules control who can reach the app. The bridge does not add
its own client-IP filter or a separate user login for request history.

## What you can inspect

For both frameworks, the bridge records how long a request took, how much PHP memory it
used, the response status and size, and request and response headers. It also includes
information about PHP, its extensions, OPcache, and the FPM pool.

Sensitive values are hidden by default. For example, you can see a cookie's name and
settings, but not its value. The [settings below](#choose-what-to-collect) explain how to
show query values or page data when you need them.

### Laravel

You can inspect:

- **Time spent in each part of the request:** application startup, service providers,
  middleware, controller code, view rendering, and response preparation.
- **The matched route:** its name, URI, controller action, and middleware.
- **Database queries:** SQL, duration, slow and duplicate queries, and the app file and line
  that made the call. Query parameter values are hidden by default.
- **Eloquent activity:** counts of models retrieved, created, updated, and deleted.
- **Inertia pages:** the component and information about shared, deferred, merged, scroll,
  and once props. Prop values are hidden by default.
- **Livewire requests:** identified in the request history.

### Symfony

You can inspect:

- **Time spent in each part of the request:** application and kernel startup, request
  listeners such as routing and security, controller code, Twig rendering, response
  listeners, and response preparation.
- **The matched route and controller.** The route path is included only when the app has
  already put it in the request's `_route_path` attribute. The bridge does not load the
  whole routing table to find it.
- **Doctrine DBAL queries:** SQL, duration, slow and duplicate queries, and the app file and
  line that made the call. Query parameter values are hidden by default.
- **Doctrine entity activity:** entities loaded or scheduled for insertion or deletion.
- **Twig rendering:** the first template rendered. You can also enable collection of the
  values passed to that template.

Symfony's framework details are available in the data under `framework`. A client that
only reads the package's `laravel` section needs to support this field to display them.

The bridge does not yet show redirect chains, Blade view names, editor links, or the source
location of individual page props.

## Choose what to collect

Settings are optional. Add a `php-data-bridge.json` file beside an app's `composer.json` when
you want to change the defaults for that app.

For example, this marks queries taking at least 50 milliseconds as slow and skips requests
to `/up` and paths under `/horizon/`:

```json
{
  "collect": {
    "slow_query_ms": 50,
    "ignore_paths": ["/horizon/*", "/up"]
  }
}
```

| Setting | Default | What it does |
| --- | --- | --- |
| `collect.enabled` | `true` | Set to `false` to turn the bridge off for this app. |
| `collect.slow_query_ms` | `100` | Queries taking at least this many milliseconds are marked as slow. |
| `collect.ignore_paths` | `[]` | Skip requests whose paths match these patterns. |
| `redact.headers` | `[]` | Hide additional headers, on top of the headers always hidden by the bridge. |
| `redact.bindings` | `true` | Hide the parameter values passed to database queries. |
| `redact.query_string` | `true` | Remove the part of a URL after `?` from the recorded request URI and related URL fields. |
| `redact.view_data` | `true` | Hide Inertia prop values and Twig template data. |
| `redact.props` | `[]` | Hide selected fields when view data collection is enabled. Use names such as `auth.user.email`. |

In the `redact` settings, **`true` means hidden; `false` means visible**.

### Show query values and page data

If you need those values while debugging, you can enable them and still hide selected
fields. This example shows query parameters and page data, but hides `auth.user.email`
and a custom header:

```json
{
  "redact": {
    "bindings": false,
    "view_data": false,
    "props": ["auth.user.email"],
    "headers": ["x-internal-token"]
  }
}
```

Cookie values are always hidden, including HttpOnly cookies. The bridge also always hides
these headers:

```text
cookie, set-cookie, authorization, proxy-authorization,
php-auth-pw, x-csrf-token, x-xsrf-token, x-api-key
```

Your `redact.headers` list adds to this list; it does not replace it. When query strings are
hidden, the bridge also removes them from Referer, Location, Content-Location, and recorded
redirect targets.

The bridge still records SQL text. A value written directly into SQL can therefore appear
in the data, even when query parameters are hidden. Custom headers and URL paths can also
contain sensitive information. The defaults reduce what is exposed; they cannot recognize
every secret an app might use.

### How ignored paths are matched

Path patterns are case-sensitive, and `*` can match across slashes. For example,
`/horizon/*` matches both `/horizon/jobs` and `/horizon/jobs/123`.

The bridge checks the original path and a cleaned-up version. It decodes URL escapes once,
resolves `.` and `..` path segments, and removes trailing slashes. This lets a rule such as
`/admin` also match `/admin/`.

Use these rules to skip profiling work, not to protect a route. Your app's router may
interpret a URL differently, and the app remains responsible for access control.

A `tools` section, if present, belongs to the client displaying the data. The bridge ignores it.

## How the data reaches your tools

This section is useful if you are building a client or checking why profiling data is not
showing up.

For a normal HTML page, the bridge inserts a small script before `</body>`. That script makes
the data available as `window.__LARAVEL_TOOLBAR_DATA__`. It also watches later fetch and XHR
responses and sends a `laravel-toolbar:update` event when they contain profiling data.
Only responses from the page's own origin are accepted; history requests are excluded.

XHR, Inertia, Livewire, API responses, and requests with `X-Toolbar` receive a base64-encoded
JSON payload in the `x-toolbar` response header. If it would exceed 32 KiB, the bridge sends
a smaller `x-toolbar-summary` header instead. The client can then request the full record
from:

```http
GET /_toolbar/requests/{id}
```

`HEAD` is also supported. The app boots and handles the request first, which lets the bridge
check the framework's actual environment before it serves a stored record. If the bridge
is disabled for the app, it does not expose its stored records.

### Pages with a Content Security Policy

If a page has a Content Security Policy (CSP) in a response header or a meta tag, the bridge
leaves the page body and policy alone. It sends the data in headers instead of inserting
an inline script. Clients that read response headers can still use the data, but the bridge
does not install its page event listener on that page.

The bridge also uses headers when an HTML page has no closing `</body>` tag or is too large
to modify. It does not add profiling data to streams, file downloads, attachments,
already-encoded response bodies, HEAD requests, or responses without a body.

### Caching and request history

Profiling data belongs to one request, so responses carrying it use
`Cache-Control: private, no-store`. The bridge also removes cache validators such as ETag
and Last-Modified, along with Expires, so a cache does not reuse a response with old profiling
data. This means the bridge changes caching behavior while it is enabled.

Each history record is saved as a JSON file in a separate directory for the app, under the
system's temporary directory. The file is written before the response is sent, so a client
can fetch it as soon as it receives the response. Old records are periodically pruned at
PHP shutdown.

History directories must belong to the PHP process's OS user and have mode `0700`. Files
have mode `0600`, so other OS users cannot read them. History storage needs PHP's POSIX
extension to check ownership. Apps running as the same OS user share that user's access;
use separate users and pools when those apps must be isolated from each other.

### Size limits

The bridge limits how much it records to avoid turning a large request into an even larger
profiling payload:

| Data | Limit |
| --- | --- |
| Recorded queries | 500 per request. `queries.truncated_count` reports how many were omitted. |
| Normalized query values and view data | 100 entries per array, three array levels, and 4096 bytes per string. Arbitrary objects are represented by their type rather than converted to strings. |
| One history file | 1 MiB. `history_available: false` means the record could not be saved. |
| HTML injection | Pages or encoded profiling payloads over 1 MiB use headers instead. |
| Full response header payload | 32 KiB; larger payloads use a summary. |

Memory figures describe PHP's memory use, not the total memory of the operating-system
process. The payload distinguishes memory used by PHP values from the larger blocks held
by PHP's allocator. For individual request stages, `memory_delta` reports the change in PHP
value memory, also called emalloc usage. `memory_real_delta` remains as an older name for
that same measurement so existing clients keep working.

## Installation details

The installer adds `99-php-data-bridge.ini` to each selected PHP-FPM version's configuration
folder. That file sets PHP's `auto_prepend_file`, which tells PHP to load the bridge before
the app. A PHP version that already uses a different prepend file is skipped.

The OpenTelemetry extension disables PHP's just-in-time compiler (JIT) when loaded, even
for requests the bridge does not profile. The installer therefore stops if a selected
PHP-FPM version has JIT enabled, including in a pool configuration. Disable JIT first or
select a different PHP version with `--php`.

Bridge files live in a new directory for each installation:

```text
/usr/local/share/php-data-bridge/<version>.install-*/
```

The `current` symlink points to the active installation. The installer copies the new files
completely before switching that link in one step. It keeps earlier installations so
requests already in progress can finish using them. Reinstalling the same version follows
the same process.

Do not delete the active bridge directory while PHP still points to it. PHP needs the
prepend file to serve requests. The installer accounts for this by installing files before
adding the PHP setting, and removing the setting before deleting files during uninstall.

On systemd, the installer reloads the selected PHP-FPM services. On Homebrew, it restarts
the running PHP formula that owns the selected binary; that restart may interrupt requests.

If you omit `--source`, the installer tries to download a published GitHub release. That
requires a release archive to exist; a Git checkout alone is not enough. Downloads use
HTTPS and are checked against a SHA-256 checksum with `sha256sum` or macOS `shasum`. The
installer also rejects unsafe archive paths, links, and special files. The checksum checks
that the download matches the published archive; it is not an independent signature from
the publisher.

## Troubleshooting

If no profiling data appears, check these points:

1. The PHP-FPM version serving the app must load OpenTelemetry and the bridge's prepend file.
2. The app must be Laravel or Symfony and use one of the development environment names
   listed above. Normal CLI commands are not profiled.
3. Neither `collect.enabled: false` nor `PHP_DATA_BRIDGE=off` should be set.
4. If Laravel already has the toolbar package, look for that package's data instead.
5. Check the response type. Downloads and streams are skipped; pages with CSP use headers.
6. Look in PHP's error log for entries beginning with `[php-data-bridge]`. Errors in hook
   callbacks are caught and logged there.

### Send a request directly to PHP-FPM

For server-side troubleshooting, `tools/fcgi.php` sends a request to an FPM socket without
using a web server:

```bash
php tools/fcgi.php /run/php/php8.5-fpm.sock /path/to/app/public /some/path "X-Toolbar: 1"
```

Replace the socket and app path with those used by your installation. You can pass other
request headers in the same way, such as `"X-Inertia: true"`. Add
`env:PHP_DATA_BRIDGE:off` to compare the result with profiling disabled.

## Working on the bridge

The bridge itself has no runtime Composer dependencies. The test suite installs its own
dependencies in `tests/vendor`.

To run the tests, you need PHP with OpenTelemetry and SQLite, Composer, Node, Python 3,
Bash, and ShellCheck. The separate FPM test also needs a PHP-FPM binary.

```bash
composer install --working-dir=tests

# Settings, redaction, response data, and history storage.
php tests/run.php

# Real HTTP requests through Laravel, Symfony, Doctrine, and Twig.
python3 tests/requests.py

# The browser-side fetch and XHR integration.
node tests/client.cjs

# Installer behavior, using temporary files and mocked services.
python3 tests/installer.py

# Request state in an isolated, single-worker FPM pool.
python3 tests/fpm-state.py /path/to/php-fpm

# Shell script checks.
shellcheck install.sh
```

The HTTP tests start a temporary local server. The FPM state test starts an isolated pool
without the OpenTelemetry extension. Installer tests use a temporary install directory and
mocked services; they do not change your installed PHP or restart live services.

The main files are:

| Location | Purpose |
| --- | --- |
| `bridge/prepend.php` | Starts the bridge before the app runs. |
| `bridge/src/Bridge.php` | Registers the framework hooks and catches errors in their callbacks. |
| `bridge/src/Recorder.php` | Keeps the data collected during one request. |
| `bridge/src/Payload.php` | Builds the profiling data and adds it to the response. |
| `bridge/src/Store.php` | Saves and serves request history. |
| `bridge/src/Config.php` | Reads the app's optional settings. |
| `bridge/src/Adapters/` | Laravel and Symfony support. Each adapter implements `Adapter`. |

The [review verification](code-review/verification.md) records the findings from the code
review, the changes made, and the checks run before and after deployment to Beast.
