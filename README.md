# PHP Data Bridge

Profiling data for PHP apps, without a package in the app. PHP loads the bridge before the
app (`auto_prepend_file`). The bridge hooks framework methods through the
[OpenTelemetry extension](https://github.com/open-telemetry/opentelemetry-php-instrumentation)
and sends the payload of [nckrtl/laravel-toolbar](https://github.com/nckrtl/laravel-toolbar),
so T3 Code shows it as it shows the package's data:

- HTML pages get `window.__LARAVEL_TOOLBAR_DATA__` and a small script that turns
  `x-toolbar` headers into `laravel-toolbar:update` events.
- XHR, Inertia and Livewire responses get an `x-toolbar` header. Payloads over 32 KB send
  an `x-toolbar-summary` row instead.
- `GET /_toolbar/requests/{id}` returns earlier requests. The bridge answers it before the app boots.

The bridge does nothing:

- without the OpenTelemetry extension, or with `PHP_DATA_BRIDGE=off`,
- outside PHP-FPM and the built-in server (so not for the CLI),
- for requests no supported framework handles,
- when the app has `nckrtl/laravel-toolbar`, or runs with `APP_ENV=production`.

Errors in the bridge go to the PHP error log, prefixed `[php-data-bridge]`. They never reach the app.

## What it collects

Core, for any framework:

- Wall time and peak memory (real and allocated).
- Request and response headers, cookies, status and size.
- PHP, OPcache and PHP-FPM pool details.

Laravel adapter:

- Request stages with wall time and allocated memory: bootstrapping, service providers,
  middleware in, controller, view rendering, middleware out, preparing response.
- Route name, URI, action and middleware.
- Queries with bindings, duration, duplicates, slow queries and the app file and line.
- Eloquent models: retrieved, created, updated, deleted.
- Inertia component and props: shared, deferred, merge, scroll and once.
- Livewire updates in the request history.

Not yet: redirect chains, Blade view names, editor links, prop source locations, other frameworks.

## Layout

- `bridge/prepend.php`: the entry point PHP loads.
- `bridge/src/`: the core. `Bridge` registers hooks, `Recorder` keeps what they saw,
  `Payload` builds and sends the data, `Store` keeps earlier requests, `Config` reads the app's settings.
- `bridge/src/Adapters/`: one class per framework, implementing `Adapter`.

## App settings

An optional `php-data-bridge.json` next to the app's `composer.json`:

```json
{
  "collect": { "slow_query_ms": 50, "ignore_paths": ["/horizon/*", "/up"] },
  "redact": { "headers": ["authorization", "cookie"], "props": ["auth.user.email"] }
}
```

- `collect.slow_query_ms`: marks queries at least this slow (default 100).
- `collect.ignore_paths`: request paths the bridge leaves alone, as shell patterns.
- `redact.headers`: header values to hide. `cookie` or `set-cookie` also hides cookie values.
- `redact.props`: page props to hide, as dot paths.

A `tools` section is for the toolbar that shows the data. The bridge ignores it.

## Setup (PHP-FPM, Sury packages)

```bash
sudo apt-get install php8.5-opentelemetry
sudo phpdismod -v 8.5 -s cli opentelemetry
```

Copy `bridge/` to the server, then point PHP-FPM at it, for example in
`/etc/php/8.5/fpm/conf.d/99-php-data-bridge.ini`:

```ini
auto_prepend_file = /usr/local/share/php-data-bridge/bridge/prepend.php
```

Reload PHP-FPM. If that file is missing, PHP refuses every request, so add and remove the
ini line and the files together.

## Testing without a web server

`tools/fcgi.php` sends one request to an FPM socket:

```bash
php tools/fcgi.php /run/php/php8.5-fpm.sock /path/to/app/public /some/path "X-Inertia: true" env:PHP_DATA_BRIDGE:off
```
