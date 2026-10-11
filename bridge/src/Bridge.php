<?php

declare(strict_types=1);

namespace PhpDataBridge;

/**
 * Registers the hooks. Every hook goes through `hook()`, which catches errors so the bridge
 * never breaks the app it observes, and which never changes arguments or return values.
 */
final class Bridge
{
    /** @var list<class-string<Adapter>> */
    public const ADAPTERS = [Adapters\Laravel::class, Adapters\Symfony::class];

    public static function register(): void
    {
        foreach (self::ADAPTERS as $adapter) {
            $adapter::register();
        }

        // Laravel and Symfony both send through HttpFoundation: the last moment to add data.
        self::hook('Symfony\Component\HttpFoundation\Response', 'send', pre: static function (object $response): void {
            // Laravel's bootstrap eligibility check already resolves this provider.
            // Its own toolbar owns the history URL as well as payload collection.
            if (Recorder::$adapter === Adapters\Laravel::class
                && class_exists('NckRtl\\Toolbar\\ToolbarServiceProvider', false)) {
                return;
            }
            $allowed = Recorder::$enabled && Recorder::$adapter !== null && (Recorder::$adapter)::shouldCollect();
            if (Store::serveDetails($response, $allowed)) {
                Recorder::$sent = true;
                return;
            }
            if (Recorder::$sent || Recorder::$request === null || Recorder::$adapter === null) {
                return;
            }

            Recorder::$sent = true;
            Recorder::mark('send');

            if ($allowed) {
                Payload::attach(Recorder::$request, $response);
            }
        }, bootstrap: true);
    }

    /**
     * Registers a hook. The extension calls `$pre` with ($object, $params, ...) before the
     * method runs and `$post` with ($object, $params, $returnValue, $exception, ...) after.
     */
    public static function hook(string $class, string $function, ?\Closure $pre = null, ?\Closure $post = null, bool $bootstrap = false): void
    {
        \OpenTelemetry\Instrumentation\hook(
            $class,
            $function,
            pre: $pre === null ? null : static function (mixed ...$args) use ($pre, $bootstrap): void {
                if (! $bootstrap && ! Recorder::$enabled) { return; }
                self::guard(static fn () => $pre(...$args));
            },
            post: $post === null ? null : static function (mixed ...$args) use ($post, $bootstrap): void {
                if (! $bootstrap && ! Recorder::$enabled) { return; }
                self::guard(static fn () => $post(...$args));
            },
        );
    }

    /** True while a hook callback runs: calls it makes itself are not recorded. */
    private static bool $inCallback = false;

    public static function guard(\Closure $callback): void
    {
        // A callback that calls a hooked method (a query to name the database, say) must not
        // set off its own hooks again, or it records itself and can loop forever.
        if (self::$inCallback) {
            return;
        }

        self::$inCallback = true;

        try {
            $callback();
        } catch (\Throwable $exception) {
            error_log('[php-data-bridge] '.$exception->getMessage().' at '.$exception->getFile().':'.$exception->getLine());
        } finally {
            self::$inCallback = false;
        }
    }
}
