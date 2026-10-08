<?php

declare(strict_types=1);

namespace PhpDataBridge;

/**
 * The app's `php-data-bridge.json`, next to composer.json. The bridge reads the `collect`
 * and `redact` settings; display settings (`tools`) are for the toolbar that shows the data.
 */
final class Config
{
    public const FILE = 'php-data-bridge.json';

    public static float $slowQueryMs = 100;

    /** @var list<string> Request paths to leave alone, as fnmatch() patterns. */
    public static array $ignorePaths = [];

    /** @var list<string> Header names whose values are hidden, lowercase. */
    public static array $redactHeaders = [];

    /** @var list<string> Page props whose values are hidden, as dot paths. */
    public static array $redactProps = [];

    public static function load(): void
    {
        $json = @file_get_contents(Recorder::$basePath.'/'.self::FILE);
        $config = $json === false ? null : json_decode($json, true);

        if (! is_array($config)) {
            return;
        }

        self::$slowQueryMs = (float) ($config['collect']['slow_query_ms'] ?? self::$slowQueryMs);
        self::$ignorePaths = self::strings($config['collect']['ignore_paths'] ?? []);
        self::$redactHeaders = array_map('strtolower', self::strings($config['redact']['headers'] ?? []));
        self::$redactProps = self::strings($config['redact']['props'] ?? []);
    }

    public static function ignores(string $path): bool
    {
        foreach (self::$ignorePaths as $pattern) {
            if (fnmatch($pattern, $path)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private static function strings(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
    }
}
