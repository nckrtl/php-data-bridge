<?php

declare(strict_types=1);

namespace PhpDataBridge\Adapters;

use PhpDataBridge\Adapter;
use PhpDataBridge\Bridge;
use PhpDataBridge\Config;
use PhpDataBridge\Recorder;

/** Laravel: lifecycle stages, route, queries, Eloquent models, Inertia and Livewire. */
final class Laravel implements Adapter
{
    public static function register(): void
    {
        self::lifecycle();
        self::database();
        self::models();
        self::inertia();
    }

    public static function shouldCollect(): bool
    {
        // The app has the toolbar package, which collects the same data: stay out of the way.
        if (class_exists('NckRtl\Toolbar\ToolbarServiceProvider')) {
            return false;
        }

        return Config::allowsEnvironment(\Illuminate\Container\Container::getInstance()->environment());
    }

    public static function stages(): array
    {
        return [
            'start' => ['Bootstrapping', '#FC3D46'],
            'providers' => ['Booting services providers', '#FF9C4D'],
            'booted' => ['Middleware in', '#FFD53D'],
            'controller' => ['Controller', '#64BAFF'],
            'render' => ['View rendering', '#85F1BF'],
            'rendered' => ['Middleware out', '#FFD53D'],
            'handled' => ['Preparing response', '#8D76FF'],
            'send' => null,
        ];
    }

    public static function contribute(array $data, object $request, object $response): array
    {
        $app = \Illuminate\Container\Container::getInstance();
        $route = Recorder::$framework['route'] ?? null;
        $page = Recorder::$framework['inertia_page'] ?? null;

        $data['request'] += [
            'route_name' => $route?->getName(),
            'route_uri' => $route?->uri(),
            'controller_action' => $route?->getActionName(),
            'middleware' => array_map(
                static fn ($middleware) => ['class' => is_string($middleware) ? $middleware : get_debug_type($middleware)],
                self::optional(static fn () => $route?->gatherMiddleware()) ?? [],
            ),
            'is_inertia' => $page !== null,
            'view_name' => $page['component'] ?? null,
            'view_data' => Config::$redactViewData ? '[redacted]' : Recorder::plain($page['props'] ?? null),
        ];

        $data['laravel'] = [
            'version' => $app->version(),
            'environment' => $app->environment(),
            'timezone' => $app['config']->get('app.timezone'),
            'locale' => $app->getLocale(),
            'debug' => (bool) $app['config']->get('app.debug'),
            'host' => $request->getHost(),
        ];

        $data['inertia'] = $page === null ? null : self::optional(static fn () => self::inertiaDetails($app, $page));

        return $data;
    }

    private static function lifecycle(): void
    {
        // Laravel loads APP_ENV during bootstrap, after Kernel::handle starts.
        Bridge::hook('Illuminate\Foundation\Bootstrap\LoadConfiguration', 'bootstrap',
            post: static function (): void { Recorder::$enabled = self::shouldCollect(); },
            bootstrap: true,
        );

        Bridge::hook(
            'Illuminate\Foundation\Bootstrap\RegisterProviders',
            'bootstrap',
            pre: static fn () => Recorder::mark('providers'),
        );

        Bridge::hook(
            'Illuminate\Foundation\Http\Kernel',
            'handle',
            pre: static function (object $kernel, array $params): void {
                Recorder::$request ??= $params[0] ?? null;
                Recorder::$adapter ??= self::class;
            },
            post: static function (): void { if (Recorder::$enabled) { Recorder::mark('handled'); } },
            bootstrap: true,
        );

        Bridge::hook(
            'Illuminate\Foundation\Http\Kernel',
            'bootstrap',
            post: static fn () => Recorder::mark('booted'),
        );

        Bridge::hook(
            'Illuminate\Routing\Route',
            'run',
            pre: static function (object $route): void {
                Recorder::$framework['route'] ??= $route;
                Recorder::mark('controller');
            },
        );

        // The router turns the controller's return value (an Inertia or view response)
        // into an HTTP response here; the first call after the controller is the render.
        Bridge::hook(
            'Illuminate\Routing\Router',
            'prepareResponse',
            pre: static function (): void {
                if (isset(Recorder::$points['controller'])) {
                    Recorder::mark('render');
                }
            },
            post: static function (): void {
                if (isset(Recorder::$points['render'])) {
                    Recorder::mark('rendered');
                }
            },
        );
    }

    private static function database(): void
    {
        $started = [];

        Bridge::hook(
            'Illuminate\Database\Connection',
            'run',
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

                Recorder::$queries[] = [
                    'sql' => (string) ($params[0] ?? ''),
                    'bindings' => Recorder::plain((array) ($params[1] ?? [])),
                    'duration' => (hrtime(true) - $start) / 1e6,
                    'connection' => $connection->getName(),
                    'driver' => $connection->getDriverName(),
                    'file' => $frame['file'] ?? null,
                    'line' => $frame['line'] ?? null,
                ];
            },
        );
    }

    private static function models(): void
    {
        $model = 'Illuminate\Database\Eloquent\Model';

        Bridge::hook($model, 'newFromBuilder', post: static function (object $model, array $params, mixed $instance): void {
            if (is_object($instance)) {
                Recorder::countModel($instance::class, 'retrieved');
            }
        });

        Bridge::hook($model, 'performInsert', post: static function (object $model, array $params, mixed $saved): void {
            if ($saved === true) {
                Recorder::countModel($model::class, 'created');
            }
        });

        Bridge::hook($model, 'performUpdate', post: static function (object $model, array $params, mixed $saved): void {
            if ($saved === true && $model->wasChanged()) {
                Recorder::countModel($model::class, 'updated');
            }
        });

        Bridge::hook($model, 'delete', post: static function (object $model, array $params, mixed $deleted): void {
            if ($deleted) {
                Recorder::countModel($model::class, 'deleted');
            }
        });
    }

    private static function inertia(): void
    {
        Bridge::hook(
            'Inertia\Response',
            'toResponse',
            post: static function (object $inertia, array $params, mixed $response): void {
                if ($response instanceof \Illuminate\Http\JsonResponse) {
                    $page = $response->getData(true);
                } elseif (is_object($response) && ($response->original ?? null) instanceof \Illuminate\View\View) {
                    $page = $response->original->getData()['page'] ?? null;
                }

                Recorder::$framework['inertia_page'] = isset($page) && is_array($page) ? $page : null;
            },
        );
    }

    private static function optional(\Closure $read): mixed
    {
        try { return $read(); } catch (\Throwable) { return null; }
    }

    /** @return array<string, mixed> */
    private static function inertiaDetails(object $app, array $page): array
    {
        $shared = class_exists('Inertia\ResponseFactory', false)
            ? array_keys($app->make('Inertia\ResponseFactory')->getShared())
            : [];

        $types = array_fill_keys($page['mergeProps'] ?? [], 'merge')
            + array_fill_keys(array_keys($page['scrollProps'] ?? []), 'scroll')
            + array_fill_keys(array_keys($page['onceProps'] ?? []), 'once');

        $props = [];

        foreach (array_keys($page['props'] ?? []) as $name) {
            $props[$name] = ['shared' => in_array($name, $shared, true), 'type' => $types[$name] ?? null, 'loaded' => true];
        }

        foreach ($page['deferredProps'] ?? [] as $group => $names) {
            foreach ($names as $name) {
                $props[$name] = ['shared' => false, 'type' => 'defer', 'defer_group' => $group, 'loaded' => false];
            }
        }

        return ['version' => $page['version'] ?? null, 'props' => $props];
    }
}
