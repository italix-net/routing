<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix Routing - factory functions
 *
 * House rule 9: a namespaced snake_case factory for the places where wiring a
 * container is more ceremony than the job deserves — conf.php, a console
 * command, a test.
 *
 * @package Italix\Routing
 */

declare(strict_types=1);

namespace Italix\Routing;

if (!function_exists(__NAMESPACE__ . '\route_table')) {

    /**
     * Build a route table by replaying route definitions.
     *
     * @param callable(Registrar):void $definition
     */
    function route_table(callable $definition): RouteTable
    {
        return RouteTable::record($definition);
    }

    /**
     * @param array<string, mixed> $defaults
     */
    function url_generator(RouteTable $routes, array $defaults = [], string $base_path = ''): UrlGenerator
    {
        return new UrlGenerator($routes, $defaults, $base_path);
    }
}
