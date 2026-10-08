<?php

declare(strict_types=1);

namespace PhpDataBridge;

/** What the hooks saw during one request. Plain static state: one request per FPM worker. */
final class Recorder
{
    /** @var array<string, array{time: float, memory: int}> Lifecycle points, first hit wins. */
    public static array $points = [];

    /** @var list<array<string, mixed>> */
    public static array $queries = [];

    /** @var array<string, array{retrieved: int, created: int, updated: int, deleted: int}> */
    public static array $models = [];

    /** The HttpFoundation request the framework handles. */
    public static ?object $request = null;

    /** @var class-string<Adapter>|null The framework that handled the request. */
    public static ?string $adapter = null;

    /** @var array<string, mixed> Framework details the adapter keeps for its payload parts. */
    public static array $framework = [];

    public static bool $sent = false;

    /** The app's root: the closest folder above the front controller with a composer.json. */
    public static string $basePath = '';

    public static function start(): void
    {
        $script = (string) ($_SERVER['SCRIPT_FILENAME'] ?? getcwd().'/index.php');
        self::$basePath = self::findBasePath(dirname($script)) ?? dirname($script, 2);
        self::$points['start'] = [
            'time' => (float) ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true)),
            'memory' => memory_get_usage(),
        ];
    }

    public static function mark(string $name): void
    {
        self::$points[$name] ??= ['time' => microtime(true), 'memory' => memory_get_usage()];
    }

    public static function countModel(string $class, string $event): void
    {
        self::$models[$class] ??= ['retrieved' => 0, 'created' => 0, 'updated' => 0, 'deleted' => 0];
        self::$models[$class][$event]++;
    }

    /** @return array{file: string, line: int|null}|null The first app frame (outside vendor/ and public/) of the current call. */
    public static function appFrame(): ?array
    {
        $vendor = self::$basePath.'/vendor/';
        $public = self::$basePath.'/public/';

        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 60) as $frame) {
            $file = $frame['file'] ?? null;

            if ($file !== null && str_starts_with($file, self::$basePath) && ! str_starts_with($file, $vendor) && ! str_starts_with($file, $public)) {
                return ['file' => $file, 'line' => $frame['line'] ?? null];
            }
        }

        return null;
    }

    private static function findBasePath(string $directory): ?string
    {
        for ($level = 0; $level < 4 && $directory !== '/'; $level++, $directory = dirname($directory)) {
            if (is_file($directory.'/composer.json')) {
                return $directory;
            }
        }

        return null;
    }
}
