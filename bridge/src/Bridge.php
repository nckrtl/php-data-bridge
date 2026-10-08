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
    public const ADAPTERS = [Adapters\Laravel::class];

    public static function register(): void
    {
        foreach (self::ADAPTERS as $adapter) {
            $adapter::register();
        }

        // Laravel and Symfony both send through HttpFoundation: the last moment to add data.
        self::hook('Symfony\Component\HttpFoundation\Response', 'send', pre: static function (object $response): void {
            if (Recorder::$sent || Recorder::$request === null || Recorder::$adapter === null) {
                return;
            }

            Recorder::$sent = true;
            Recorder::mark('send');

            if ((Recorder::$adapter)::shouldCollect()) {
                Payload::attach(Recorder::$request, $response);
            }
        });
    }

    /**
     * Registers a hook. The extension calls `$pre` with ($object, $params, ...) before the
     * method runs and `$post` with ($object, $params, $returnValue, $exception, ...) after.
     */
    public static function hook(string $class, string $function, ?\Closure $pre = null, ?\Closure $post = null): void
    {
        \OpenTelemetry\Instrumentation\hook(
            $class,
            $function,
            pre: $pre === null ? null : static function (mixed ...$args) use ($pre): void {
                self::guard(static fn () => $pre(...$args));
            },
            post: $post === null ? null : static function (mixed ...$args) use ($post): void {
                self::guard(static fn () => $post(...$args));
            },
        );
    }

    public static function guard(\Closure $callback): void
    {
        try {
            $callback();
        } catch (\Throwable $exception) {
            error_log('[php-data-bridge] '.$exception->getMessage().' at '.$exception->getFile().':'.$exception->getLine());
        }
    }
}
