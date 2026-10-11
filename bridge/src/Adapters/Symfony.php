<?php

declare(strict_types=1);

namespace PhpDataBridge\Adapters;

use PhpDataBridge\Adapter;
use PhpDataBridge\Bridge;
use PhpDataBridge\Config;
use PhpDataBridge\Recorder;

/** Symfony: kernel stages, route, Doctrine queries and entities, Twig rendering. */
final class Symfony implements Adapter
{
    /** HttpKernelInterface::MAIN_REQUEST */
    private const MAIN_REQUEST = 1;

    public static function register(): void
    {
        self::kernel();
        self::doctrine();
        self::twig();
    }

    public static function shouldCollect(): bool
    {
        $kernel = Recorder::$framework['kernel'] ?? null;

        return $kernel !== null && Config::allowsEnvironment($kernel->getEnvironment());
    }

    public static function stages(): array
    {
        return [
            'start' => ['Bootstrapping', '#FC3D46'],
            'boot' => ['Booting kernel', '#FF9C4D'],
            'booted' => ['Request listeners', '#FFD53D'],
            'controller' => ['Controller', '#64BAFF'],
            'render' => ['View rendering', '#85F1BF'],
            'rendered' => ['Controller', '#64BAFF'],
            'response' => ['Response listeners', '#FFD53D'],
            'handled' => ['Preparing response', '#8D76FF'],
            'send' => null,
        ];
    }

    public static function contribute(array $data, object $request, object $response): array
    {
        $kernel = Recorder::$framework['kernel'] ?? null;
        $routeName = $request->attributes->get('_route');
        $controller = $request->attributes->get('_controller');

        $data['request'] += [
            'route_name' => is_string($routeName) ? $routeName : null,
            'route_uri' => $request->attributes->get('_route_path'),
            'controller_action' => is_string($controller) ? $controller : null,
            'middleware' => [],
            'is_inertia' => false,
            'view_name' => Recorder::$framework['template'] ?? null,
            'view_data' => Recorder::$framework['template_context'] ?? null,
        ];

        $data['framework'] = [
            'name' => 'Symfony',
            'version' => class_exists('Symfony\Component\HttpKernel\Kernel', false) ? \Symfony\Component\HttpKernel\Kernel::VERSION : null,
            'environment' => $kernel?->getEnvironment(),
            'debug' => $kernel?->isDebug(),
            'host' => $request->getHost(),
        ];

        return $data;
    }

    private static function kernel(): void
    {
        Bridge::hook(
            'Symfony\Component\HttpKernel\Kernel',
            'boot',
            pre: static function (object $kernel): void {
                Recorder::$framework['kernel'] ??= $kernel;
                Recorder::$enabled = self::shouldCollect();
                if (Recorder::$enabled) { Recorder::mark('boot'); }
            },
            post: static function (object $kernel): void {
                Recorder::$framework['kernel'] ??= $kernel;
                if (Recorder::$enabled) { Recorder::mark('booted'); }
            },
            bootstrap: true,
        );

        Bridge::hook(
            'Symfony\Component\HttpKernel\HttpKernel',
            'handle',
            pre: static function (object $httpKernel, array $params): void {
                if (($params[1] ?? self::MAIN_REQUEST) === self::MAIN_REQUEST && Recorder::$request === null) {
                    Recorder::$request = $params[0] ?? null;
                    Recorder::$adapter ??= self::class;
                }
            },
            post: static function (object $httpKernel, array $params): void {
                if (($params[0] ?? null) === Recorder::$request) {
                    Recorder::mark('handled');
                }
            },
        );

        // The controller runs right after its arguments are resolved.
        Bridge::hook(
            'Symfony\Component\HttpKernel\Controller\ArgumentResolver',
            'getArguments',
            post: static function (object $resolver, array $params): void {
                if (($params[0] ?? null) === Recorder::$request) {
                    Recorder::mark('controller');
                }
            },
        );

        // kernel.response listeners run in filterResponse (private).
        Bridge::hook(
            'Symfony\Component\HttpKernel\HttpKernel',
            'filterResponse',
            pre: static function (object $httpKernel, array $params): void {
                if (($params[1] ?? null) === Recorder::$request) {
                    Recorder::mark('response');
                }
            },
        );
    }

    private static function doctrine(): void
    {
        $started = [];

        foreach (['executeQuery', 'executeStatement'] as $method) {
            Bridge::hook(
                'Doctrine\DBAL\Connection',
                $method,
                pre: static function () use (&$started): void {
                    $started[] = count(Recorder::$queries) < Recorder::MAX_QUERIES ? [hrtime(true), Recorder::appFrame()] : null;
                },
                post: static function (object $connection, array $params) use (&$started): void {
                    $timing = array_pop($started);
                    if ($timing === null || count(Recorder::$queries) >= Recorder::MAX_QUERIES) {
                        Recorder::$droppedQueries++;
                        return;
                    }
                    [$start, $frame] = $timing;
                    // From the parameters: getDatabase() runs a query itself, which would hook again.
                    $connectionParams = $connection->getParams();
                    $driver = $connectionParams['driver'] ?? null;
                    $database = $connectionParams['dbname'] ?? null;

                    Recorder::$queries[] = [
                        'sql' => (string) ($params[0] ?? ''),
                        'bindings' => Recorder::plain((array) ($params[1] ?? [])),
                        'duration' => (hrtime(true) - $start) / 1e6,
                        'connection' => is_string($database) ? $database : 'default',
                        'driver' => is_string($driver) ? $driver : 'dbal',
                        'file' => $frame['file'] ?? null,
                        'line' => $frame['line'] ?? null,
                    ];
                },
            );
        }

        $unitOfWork = 'Doctrine\ORM\UnitOfWork';

        Bridge::hook($unitOfWork, 'createEntity', post: static function (object $uow, array $params): void {
            if (is_string($params[0] ?? null)) {
                Recorder::countModel($params[0], 'retrieved');
            }
        });

        Bridge::hook($unitOfWork, 'scheduleForInsert', pre: static function (object $uow, array $params): void {
            if (is_object($params[0] ?? null)) {
                Recorder::countModel($params[0]::class, 'created');
            }
        });

        Bridge::hook($unitOfWork, 'scheduleForDelete', pre: static function (object $uow, array $params): void {
            if (is_object($params[0] ?? null)) {
                Recorder::countModel($params[0]::class, 'deleted');
            }
        });
    }

    private static function twig(): void
    {
        $depth = 0;

        Bridge::hook(
            'Twig\Environment',
            'render',
            pre: static function (object $twig, array $params) use (&$depth): void {
                if ($depth++ > 0 || isset(Recorder::$framework['template'])) {
                    return;
                }

                Recorder::mark('render');
                Recorder::$framework['template'] = is_string($params[0] ?? null) ? $params[0] : get_debug_type($params[0] ?? null);
                Recorder::$framework['template_context'] = Config::$redactViewData ? '[redacted]' : Recorder::plain((array) ($params[1] ?? []));
            },
            post: static function () use (&$depth): void {
                if (--$depth === 0 && isset(Recorder::$points['render'])) {
                    Recorder::mark('rendered');
                }
            },
        );
    }
}
