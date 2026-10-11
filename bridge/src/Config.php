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
    public const DEFAULT_HEADERS = ['cookie', 'set-cookie', 'authorization', 'proxy-authorization', 'php-auth-pw', 'x-csrf-token', 'x-xsrf-token', 'x-api-key'];

    public static array $redactHeaders = self::DEFAULT_HEADERS;
    public static bool $enabled = true;
    public static bool $redactBindings = true;
    public static bool $redactQueryString = true;
    public static bool $redactViewData = true;

    /** @var list<string> Page props whose values are hidden, as dot paths. */
    public static array $redactProps = [];

    public static function load(): void
    {
        // Installing the extension and prepend script is the opt-in. Apps may opt out.
        self::$enabled = true;
        self::$slowQueryMs = 100;
        self::$ignorePaths = self::$redactProps = [];
        self::$redactHeaders = self::DEFAULT_HEADERS;
        self::$redactBindings = self::$redactQueryString = self::$redactViewData = true;
        $json = @file_get_contents(Recorder::$basePath.'/'.self::FILE);
        $config = $json === false ? null : json_decode($json, true);

        if (! is_array($config)) {
            return;
        }

        self::$enabled = ($config['collect']['enabled'] ?? self::$enabled) === true;
        self::$redactBindings = ($config['redact']['bindings'] ?? true) !== false;
        self::$redactQueryString = ($config['redact']['query_string'] ?? true) !== false;
        self::$redactViewData = ($config['redact']['view_data'] ?? true) !== false;
        self::$slowQueryMs = (float) ($config['collect']['slow_query_ms'] ?? self::$slowQueryMs);
        self::$ignorePaths = self::strings($config['collect']['ignore_paths'] ?? []);
        self::$redactHeaders = array_values(array_unique(array_merge(self::DEFAULT_HEADERS, array_map('strtolower', self::strings($config['redact']['headers'] ?? [])))));
        self::$redactProps = self::strings($config['redact']['props'] ?? []);
    }

    public static function ignores(string $path): bool
    {
        $segments = [];
        foreach (explode('/', rawurldecode($path)) as $segment) {
            if ($segment === '..') {
                array_pop($segments);
            } elseif ($segment !== '' && $segment !== '.') {
                $segments[] = $segment;
            }
        }
        $normalized = '/'.implode('/', $segments);
        foreach (self::$ignorePaths as $pattern) {
            if (fnmatch($pattern, $path) || fnmatch(rtrim($pattern, '/') ?: '/', $normalized)) {
                return true;
            }
        }

        return false;
    }

    public static function allowsEnvironment(?string $environment): bool
    {
        return self::allowsRequest() && in_array($environment, ['local', 'dev', 'development', 'testing', 'test'], true);
    }

    public static function allowsRequest(): bool
    {
        // Development apps may run on another node. Network access belongs to the host.
        return self::$enabled && getenv('PHP_DATA_BRIDGE') !== 'off';
    }

    /** @return list<string> */
    private static function strings(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
    }
}
