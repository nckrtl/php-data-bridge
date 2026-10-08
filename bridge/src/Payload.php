<?php

declare(strict_types=1);

namespace PhpDataBridge;

/**
 * Builds the toolbar payload (the shape nckrtl/laravel-toolbar sends and T3 Code reads) and
 * adds it to the response: in the page for HTML, in an `x-toolbar` header for XHR.
 */
final class Payload
{
    /** Bigger header payloads go out as a summary row; the toolbar loads the rest on demand. */
    private const MAX_HEADER_BYTES = 32 * 1024;

    private const REDACTED = '[redacted]';

    public static function attach(object $request, object $response): void
    {
        $id = bin2hex(random_bytes(8));
        $data = self::redact(self::build($id, $request, $response));
        $data['history_row'] = self::historyRow($id, $request, $data);

        Store::save($id, $data);

        if (self::isXhr($request)) {
            self::attachHeader($response, $data, $request);
        } else {
            self::injectHtml($response, $data);
        }
    }

    /** @return array<string, mixed> */
    private static function build(string $id, object $request, object $response): array
    {
        $content = (string) $response->getContent();
        $wallMs = (Recorder::$points['send']['time'] - Recorder::$points['start']['time']) * 1000;

        $data = [
            'request_id' => $id,
            'data_bridge' => ['version' => PHP_DATA_BRIDGE_VERSION],
            'profiler' => [
                'total_wall_time' => self::ms($wallMs),
                'total_real_memory' => self::bytes(memory_get_peak_usage(true)),
                'total_allocated_memory' => self::bytes(memory_get_peak_usage()),
                'stages' => self::stages((Recorder::$adapter)::stages()),
            ],
            'request' => [
                'method' => $request->getMethod(),
                'uri' => $request->getRequestUri(),
                'ip_address' => $request->getClientIp(),
                'headers' => $request->headers->all(),
            ],
            'response' => [
                'status_code' => $response->getStatusCode(),
                'headers' => $response->headers->all(),
                'size' => self::bytes(strlen($content)),
                'content_type' => $response->headers->get('Content-Type'),
                'redirect_to' => $response->isRedirect() ? $response->headers->get('Location') : null,
                'cookies' => array_map(static fn ($cookie) => [
                    'name' => $cookie->getName(),
                    'value' => $cookie->getValue(),
                    'path' => $cookie->getPath(),
                    'domain' => $cookie->getDomain(),
                    'same_site' => $cookie->getSameSite(),
                    'secure' => $cookie->isSecure(),
                    'http_only' => $cookie->isHttpOnly(),
                    'expires' => $cookie->getExpiresTime(),
                ], $response->headers->getCookies()),
            ],
            'queries' => self::queries(),
            'models' => array_map(
                static fn (string $class, array $counts) => ['model' => $class] + $counts,
                array_keys(Recorder::$models),
                Recorder::$models,
            ),
            'php' => self::php(),
        ];

        return (Recorder::$adapter)::contribute($data, $request, $response);
    }

    /**
     * @param  array<string, array{0: string, 1: string}|null>  $definitions
     * @return list<array<string, mixed>>
     */
    private static function stages(array $definitions): array
    {
        $points = [];

        foreach (array_keys($definitions) as $name) {
            if (isset(Recorder::$points[$name])) {
                $points[] = [$name, Recorder::$points[$name]];
            }
        }

        if (count($points) < 2) {
            return [];
        }

        $total = max(end($points)[1]['time'] - $points[0][1]['time'], 1e-9);
        $stages = [];

        foreach ($points as $index => [$name, $point]) {
            $next = $points[$index + 1][1] ?? null;

            if ($next === null || $definitions[$name] === null) {
                continue;
            }

            [$label, $color] = $definitions[$name];
            $seconds = $next['time'] - $point['time'];

            $stages[] = [
                'label' => $label,
                'color' => $color,
                'wall_time' => ['percentage' => $seconds / $total * 100, 'measurement' => self::ms($seconds * 1000)],
                // Allocated memory (memory_get_usage()); real usage moves in 2 MB chunks.
                'memory_real_delta' => ['measurement' => self::bytes($next['memory'] - $point['memory'])],
            ];
        }

        return $stages;
    }

    /** @return array<string, mixed> */
    private static function queries(): array
    {
        $total = array_sum(array_column(Recorder::$queries, 'duration'));
        $seen = [];
        $offset = 0.0;
        $queries = [];
        $databases = [];

        foreach (Recorder::$queries as $query) {
            $key = $query['sql'].'|'.json_encode($query['bindings']);
            $share = $total > 0 ? $query['duration'] / $total : 0;

            $queries[] = [
                'sql' => $query['sql'],
                'bindings' => $query['bindings'],
                'duration' => round($query['duration'], 3),
                'is_duplicate' => isset($seen[$key]),
                'is_slow' => $query['duration'] >= Config::$slowQueryMs,
                'percentage' => $share,
                'offset' => $offset,
                'file' => $query['file'],
                'line' => $query['line'],
            ];

            $seen[$key] = true;
            $offset += $share;
            $databases[$query['connection']] = ['name' => $query['connection'], 'driver' => $query['driver']];
        }

        return ['totalTime' => round($total, 3), 'databases' => array_values($databases), 'queries' => $queries];
    }

    /** @return array<string, mixed> */
    private static function php(): array
    {
        $opcache = function_exists('opcache_get_status') ? (@opcache_get_status(false) ?: null) : null;
        $fpm = function_exists('fpm_get_status') ? (@fpm_get_status() ?: null) : null;
        $settings = ['display_errors', 'error_reporting', 'upload_max_filesize', 'post_max_size', 'opcache.validate_timestamps', 'auto_prepend_file'];

        return [
            'version' => PHP_VERSION,
            'memory_limit' => ini_get('memory_limit'),
            'max_execution_time' => ini_get('max_execution_time'),
            'sapi' => PHP_SAPI,
            'settings' => array_combine(
                $settings,
                array_map(static fn (string $name) => ini_get($name) === false ? null : (string) ini_get($name), $settings),
            ),
            'opcache' => $opcache === null ? null : [
                'enabled' => (bool) ($opcache['opcache_enabled'] ?? false),
                'memory_used' => $opcache['memory_usage']['used_memory'] ?? null,
                'memory_free' => $opcache['memory_usage']['free_memory'] ?? null,
                'hit_rate' => $opcache['opcache_statistics']['opcache_hit_rate'] ?? null,
                'cached_scripts' => $opcache['opcache_statistics']['num_cached_scripts'] ?? null,
            ],
            'extensions' => get_loaded_extensions(),
            'fpm' => $fpm === null ? null : [
                'pool' => $fpm['pool'] ?? null,
                'process_manager' => $fpm['process-manager'] ?? null,
                'start_since' => $fpm['start-since'] ?? null,
                'accepted_conn' => $fpm['accepted-conn'] ?? null,
                'listen_queue' => $fpm['listen-queue'] ?? null,
                'max_listen_queue' => $fpm['max-listen-queue'] ?? null,
                'idle_processes' => $fpm['idle-processes'] ?? null,
                'active_processes' => $fpm['active-processes'] ?? null,
                'total_processes' => $fpm['total-processes'] ?? null,
                'max_active_processes' => $fpm['max-active-processes'] ?? null,
                'max_children_reached' => $fpm['max-children-reached'] ?? null,
                'slow_requests' => $fpm['slow-requests'] ?? null,
            ],
        ];
    }

    /**
     * Hides the header and prop values the app's config names, before they leave PHP.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function redact(array $data): array
    {
        foreach (['request', 'response'] as $part) {
            foreach (Config::$redactHeaders as $name) {
                if (isset($data[$part]['headers'][$name])) {
                    $data[$part]['headers'][$name] = [self::REDACTED];
                }
            }
        }

        if (in_array('set-cookie', Config::$redactHeaders, true) || in_array('cookie', Config::$redactHeaders, true)) {
            foreach ($data['response']['cookies'] as $index => $cookie) {
                $data['response']['cookies'][$index]['value'] = self::REDACTED;
            }
        }

        foreach (Config::$redactProps as $path) {
            if (is_array($data['request']['view_data'] ?? null)) {
                self::redactPath($data['request']['view_data'], explode('.', $path));
            }
        }

        return $data;
    }

    /** @param list<string> $keys */
    private static function redactPath(array &$values, array $keys): void
    {
        $key = array_shift($keys);

        if ($key === null || ! array_key_exists($key, $values)) {
            return;
        }

        if ($keys === []) {
            $values[$key] = self::REDACTED;
        } elseif (is_array($values[$key])) {
            self::redactPath($values[$key], $keys);
        }
    }

    /** @return array<string, mixed> */
    private static function historyRow(string $id, object $request, array $data): array
    {
        $type = match (true) {
            ($data['request']['is_inertia'] ?? false) === true => 'Inertia',
            $request->headers->has('X-Livewire') => 'Livewire',
            default => null,
        };

        return [
            'id' => $id,
            'is_xhr' => self::isXhr($request),
            'method' => $data['request']['method'],
            'uri' => $data['request']['uri'],
            'name' => $data['request']['route_name'] ?? null,
            'action' => $data['request']['controller_action'] ?? null,
            'status_code' => $data['response']['status_code'],
            'size' => $data['response']['size']['formattedValue'],
            'duration' => $data['profiler']['total_wall_time']['formattedValue'],
            'response_type' => $type,
            'follow_up' => $request->headers->has('X-Inertia-Partial-Data') ? 'partial' : null,
        ];
    }

    /** Requests the page makes itself: Inertia visits, Livewire updates and other XHR. */
    private static function isXhr(object $request): bool
    {
        return $request->isXmlHttpRequest()
            || $request->headers->get('X-Inertia') === 'true'
            || $request->headers->has('X-Livewire');
    }

    private static function attachHeader(object $response, array $data, object $request): void
    {
        // A full Inertia visit makes a new page: like the package, start the history over.
        if ($data['history_row']['response_type'] === 'Inertia' && $data['history_row']['follow_up'] === null && $request->isMethod('GET')) {
            $data['selected_request_id'] = $data['request_id'];
            $data['request_history'] = [$data['history_row']];
        }

        $encoded = base64_encode((string) json_encode($data, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR));

        if (strlen($encoded) > self::MAX_HEADER_BYTES) {
            $response->headers->set('x-toolbar-summary', base64_encode((string) json_encode([
                'request_id' => $data['request_id'],
                'history_row' => $data['history_row'],
            ])));

            return;
        }

        $response->headers->set('x-toolbar', $encoded);
    }

    private static function injectHtml(object $response, array $data): void
    {
        $type = (string) $response->headers->get('Content-Type');
        $content = $response->getContent();

        if (($type !== '' && ! str_contains($type, 'html')) || ! is_string($content)) {
            return;
        }

        $position = strripos($content, '</body>');

        if ($position === false) {
            return;
        }

        $data['selected_request_id'] = $data['request_id'];
        $data['request_history'] = [$data['history_row']];

        $json = json_encode($data, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        $script = '<script>window.__LARAVEL_TOOLBAR_DATA__='.$json.';'.file_get_contents(__DIR__.'/../client.js').'</script>';

        $content = substr($content, 0, $position).$script.substr($content, $position);
        $response->setContent($content);
        $response->headers->set('Content-Length', (string) strlen($content));
    }

    /** @return array{value: float, unit: string, formattedValue: string} */
    private static function ms(float $value): array
    {
        $formatted = $value >= 100 ? round($value).'ms' : number_format($value, $value >= 10 ? 1 : 2).'ms';

        return ['value' => round($value, 3), 'unit' => 'ms', 'formattedValue' => $formatted];
    }

    /** @return array{value: int, unit: string, formattedValue: string} */
    private static function bytes(int $value): array
    {
        $size = abs($value);
        $sign = $value < 0 ? '-' : '';

        $formatted = match (true) {
            $size >= 1024 ** 3 => $sign.number_format($size / 1024 ** 3, 2).' GB',
            $size >= 1024 ** 2 => $sign.number_format($size / 1024 ** 2, 2).' MB',
            $size >= 1024 => $sign.number_format($size / 1024, 2).' KB',
            default => $sign.$size.' B',
        };

        return ['value' => $value, 'unit' => 'B', 'formattedValue' => $formatted];
    }
}
