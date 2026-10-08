<?php

declare(strict_types=1);

namespace PhpDataBridge;

/**
 * What a framework adds to the core: its hooks, its request stages and the parts of the
 * payload only it knows (route, framework details, page props). One class per framework.
 */
interface Adapter
{
    /** Registers the framework's hooks. Hooks on classes the app never loads cost nothing. */
    public static function register(): void;

    /** False when the app has its own toolbar or must not be observed (production). */
    public static function shouldCollect(): bool;

    /** @return array<string, array{0: string, 1: string}|null> Stage per lifecycle point, in request order. */
    public static function stages(): array;

    /**
     * Adds the framework's parts to the payload.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function contribute(array $data, object $request, object $response): array;
}
