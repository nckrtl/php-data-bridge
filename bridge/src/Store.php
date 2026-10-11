<?php

declare(strict_types=1);

namespace PhpDataBridge;

/** Private, bounded request history for a development app. */
final class Store
{
    private const KEEP = 200;
    public const MAX_BYTES = 1024 * 1024;

    public static function directory(): string
    {
        $uid = function_exists('posix_geteuid') ? posix_geteuid() : -1;
        return sys_get_temp_dir().'/php-data-bridge-'.$uid.'/'.hash('sha256', Recorder::$basePath);
    }

    private static function privateDirectory(string $path, bool $create): bool
    {
        if (! function_exists('posix_geteuid')) {
            return false;
        }
        if ($create && ! file_exists($path)) {
            @mkdir($path, 0700);
        }
        clearstatcache(true, $path);
        $stat = @lstat($path);
        return $stat !== false && ($stat['mode'] & 0177777) === 0040700 && $stat['uid'] === posix_geteuid();
    }

    private static function ready(bool $create): bool
    {
        $directory = self::directory();
        return self::privateDirectory(dirname($directory), $create) && self::privateDirectory($directory, $create);
    }

    /** @param array<string, mixed> $payload */
    public static function save(string $id, array $payload): bool
    {
        if (! preg_match('/^[a-f0-9]{16}$/D', $id)) {
            return false;
        }
        $json = json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        if ($json === false || strlen($json) > self::MAX_BYTES || ! self::ready(true)) {
            return false;
        }
        $directory = self::directory();
        $temporary = @tempnam($directory, '.payload-');
        if ($temporary === false) {
            return false;
        }
        try {
            if (! @chmod($temporary, 0600) || @file_put_contents($temporary, $json) !== strlen($json)
                || ! @rename($temporary, $directory.'/'.$id.'.json')) {
                return false;
            }
        } finally {
            if (is_file($temporary)) { @unlink($temporary); }
        }
        // Run maintenance at shutdown, outside the response's send hook.
        if (random_int(1, 50) === 1) {
            register_shutdown_function(static fn () => self::prune($directory));
        }
        return true;
    }

    /** The app boots first so its actual framework environment can authorize history. */
    public static function serveDetails(object $response, bool $allowed): bool
    {
        $path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
        if (! preg_match('#^/_toolbar/requests/([a-f0-9]{16})$#D', $path, $match)) {
            return false;
        }
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $raw = null;
        if ($allowed && Config::allowsRequest() && in_array($method, ['GET', 'HEAD'], true) && self::ready(false)) {
            $file = self::directory().'/'.$match[1].'.json';
            $stat = @lstat($file);
            if ($stat !== false && ($stat['mode'] & 0177777) === 0100600
                && $stat['uid'] === posix_geteuid() && $stat['size'] <= self::MAX_BYTES) {
                $json = @file_get_contents($file);
                $raw = $json === false ? null : json_decode($json, true);
            }
        }
        $response->headers->replace(['Content-Type' => 'application/json', 'Cache-Control' => 'private, no-store']);
        $response->setStatusCode(is_array($raw) ? 200 : 404);
        $response->setContent($method === 'HEAD' ? '' : (is_array($raw)
            ? (string) json_encode(['summary' => $raw['history_row'] ?? null, 'raw' => $raw], JSON_INVALID_UTF8_SUBSTITUTE)
            : '{"message":"Not found"}'));
        return true;
    }

    private static function prune(string $directory): void
    {
        $lock = @fopen($directory.'/prune.lock', 'c');
        if ($lock === false) { return; }
        try {
            if (! flock($lock, LOCK_EX | LOCK_NB)) { return; }
            $files = [];
            foreach (glob($directory.'/*.json') ?: [] as $file) {
                $files[$file] = @filemtime($file) ?: 0;
            }
            asort($files);
            foreach (array_slice(array_keys($files), 0, max(0, count($files) - self::KEEP)) as $file) {
                @unlink($file);
            }
        } finally {
            fclose($lock);
        }
    }
}
