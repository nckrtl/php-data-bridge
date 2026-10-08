<?php

declare(strict_types=1);

namespace PhpDataBridge\Adapters;

use PhpDataBridge\Adapter;
use PhpDataBridge\Bridge;
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

        return $kernel === null || $kernel->getEnvironment() !== 'prod';
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
            'route_uri' => is_string($routeName) ? self::routePath($kernel, $routeName) : null,
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
            pre: static fn () => Recorder::mark('boot'),
            post: static function (object $kernel): void {
                Recorder::$framework['kernel'] ??= $kernel;
                Recorder::mark('booted');
            },
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
                    $started[] = [hrtime(true), Recorder::appFrame()];
                },
                post: static function (object $connection, array $params) use (&$started): void {
                    [$start, $frame] = array_pop($started) ?? [hrtime(true), null];
                    // From the parameters: getDatabase() runs a query itself, which would hook again.
                    $connectionParams = $connection->getParams();
                    $driver = $connectionParams['driver'] ?? null;
                    $database = $connectionParams['dbname'] ?? null;

                    Recorder::$queries[] = [
                        'sql' => (string) ($params[0] ?? ''),
                        'bindings' => array_values(array_map(self::plain(...), (array) ($params[1] ?? []))),
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
                Recorder::$framework['template_context'] = array_map(self::plain(...), (array) ($params[1] ?? []));
            },
            post: static function () use (&$depth): void {
                if (--$depth === 0 && isset(Recorder::$points['render'])) {
                    Recorder::mark('rendered');
                }
            },
        );
    }

    private static function routePath(?object $kernel, string $name): ?string
    {
        $container = $kernel?->getContainer();

        if ($container === null || ! $container->has('router')) {
            return null;
        }

        return $container->get('router')->getRouteCollection()->get($name)?->getPath();
    }

    /** Template context and bindings as plain data: objects become their class name. */
    private static function plain(mixed $value, int $depth = 0): mixed
    {
        return match (true) {
            is_scalar($value), $value === null => $value,
            is_array($value) => $depth >= 3 ? '[array]' : array_map(static fn ($item) => self::plain($item, $depth + 1), $value),
            $value instanceof \DateTimeInterface => $value->format(DATE_ATOM),
            $value instanceof \BackedEnum => $value->value,
            $value instanceof \Stringable && ! method_exists($value, 'getId') => (string) $value,
            default => get_debug_type($value),
        };
    }
}
