<?php

// PHP Data Bridge: collects toolbar data for PHP apps without a toolbar package. PHP loads
// this file before the app (auto_prepend_file). It hooks framework methods through the
// OpenTelemetry extension and sends the payload nckrtl/laravel-toolbar sends:
// `window.__LARAVEL_TOOLBAR_DATA__` in HTML pages and an `x-toolbar` header on XHR responses.
// T3 Code reads both. Without the extension, or with PHP_DATA_BRIDGE=off, it does nothing.

declare(strict_types=1);

if (PHP_SAPI !== 'fpm-fcgi' && PHP_SAPI !== 'cli-server') {
    return;
}

if (! function_exists('OpenTelemetry\Instrumentation\hook') || getenv('PHP_DATA_BRIDGE') === 'off') {
    return;
}

define('PHP_DATA_BRIDGE_VERSION', trim((string) @file_get_contents(__DIR__.'/VERSION')) ?: 'dev');

require_once __DIR__.'/src/Adapter.php';
require_once __DIR__.'/src/Adapters/Laravel.php';
require_once __DIR__.'/src/Bridge.php';
require_once __DIR__.'/src/Config.php';
require_once __DIR__.'/src/Payload.php';
require_once __DIR__.'/src/Recorder.php';
require_once __DIR__.'/src/Store.php';

PhpDataBridge\Recorder::start();
PhpDataBridge\Config::load();

if (PhpDataBridge\Config::ignores((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH))) {
    return;
}

// The history endpoint the toolbar fetches: answered here, before the app boots.
if (PhpDataBridge\Store::serveDetails()) {
    exit;
}

PhpDataBridge\Bridge::register();
