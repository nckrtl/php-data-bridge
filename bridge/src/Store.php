<?php

declare(strict_types=1);

namespace PhpDataBridge;

/**
 * Keeps each request's payload as a JSON file, per app, so the T3 Code panel can load
 * earlier requests from `/_toolbar/requests/{id}`, as it does with the package.
 */
final class Store
{
    private const KEEP = 200;

    public static function directory(): string
    {
        return sys_get_temp_dir().'/php-data-bridge/'.md5(Recorder::$basePath);
    }

    /** @param array<string, mixed> $payload */
    public static function save(string $id, array $payload): void
    {
        $directory = self::directory();

        if (! is_dir($directory) && ! @mkdir($directory, 0700, true) && ! is_dir($directory)) {
            return;
        }

        $json = json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);

        if ($json !== false) {
            @file_put_contents($directory.'/'.$id.'.json', $json, LOCK_EX);
        }

        if (random_int(1, 50) === 1) {
            self::prune($directory);
        }
    }

    /** Answers `GET /_toolbar/requests/{id}`. Returns false for any other request. */
    public static function serveDetails(): bool
    {
        $path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);

        if (! preg_match('#^/_toolbar/requests/([a-f0-9]{16})$#', $path, $match)) {
            return false;
        }

        $json = @file_get_contents(self::directory().'/'.$match[1].'.json');
        $raw = $json === false ? null : json_decode($json, true);

        header('Content-Type: application/json');
        header('Cache-Control: no-store');

        if (! is_array($raw)) {
            http_response_code(404);
            echo '{"message":"Not found"}';

            return true;
        }

        echo json_encode(['summary' => $raw['history_row'] ?? null, 'raw' => $raw], JSON_INVALID_UTF8_SUBSTITUTE);

        return true;
    }

    private static function prune(string $directory): void
    {
        $files = glob($directory.'/*.json') ?: [];

        if (count($files) <= self::KEEP) {
            return;
        }

        usort($files, fn (string $a, string $b): int => filemtime($a) <=> filemtime($b));

        foreach (array_slice($files, 0, count($files) - self::KEEP) as $file) {
            @unlink($file);
        }
    }
}
