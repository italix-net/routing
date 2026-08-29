<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix Routing - Registrar
 *
 * @package Italix\Routing
 */

declare(strict_types=1);

namespace Italix\Routing;

/**
 * Where routes are declared: once, producing both a dispatcher entry and a
 * name.
 *
 * The dispatcher is reached through a **callable sink**, not through a
 * `FastRoute\RouteCollector`. That is what keeps this library dependency-free
 * without duck-typing an object it was handed: a callable is a first-class
 * type, and the six-line closure that forwards to FastRoute lives in the
 * application's `conf.php`, where wiring belongs.
 *
 *     $registrar = Registrar::into(static function ($method, $pattern, $handler) use ($r): void {
 *         $r->addRoute($method, $pattern, $handler);
 *     });
 *
 * With no sink the registrar only records, which is how the name table is built
 * on requests where the compiled dispatcher is cached and the definitions are
 * never replayed into FastRoute.
 *
 * `group()` is this library's own, not FastRoute's, because the table has to
 * see the **full** pattern — a name whose URL is missing its group prefix
 * generates links that 404.
 */
final class Registrar
{
    private RouteTable $table;

    /** @var callable|null */
    private $sink;

    private string $prefix;

    public function __construct(RouteTable $table, ?callable $sink = null, string $prefix = '')
    {
        $this->table  = $table;
        $this->sink   = $sink;
        $this->prefix = $prefix;
    }

    /**
     * A registrar that records names and does not dispatch.
     */
    public static function recording(?RouteTable $table = null): self
    {
        return new self($table ?? new RouteTable());
    }

    /**
     * A registrar that records names and forwards to a dispatcher.
     *
     * @param callable(string|array, string, mixed):void $sink
     */
    public static function into(callable $sink, ?RouteTable $table = null): self
    {
        return new self($table ?? new RouteTable(), $sink);
    }

    public function table(): RouteTable
    {
        return $this->table;
    }

    public function prefix(): string
    {
        return $this->prefix;
    }

    /**
     * An unnamed route. Reaches the dispatcher, never the table.
     *
     * @param string|string[] $method
     * @param mixed           $handler
     */
    public function route($method, string $pattern, $handler): self
    {
        $this->dispatch($method, $this->prefix . $pattern, $handler);

        return $this;
    }

    /**
     * A named route.
     *
     * @param string|string[] $method
     * @param mixed           $handler
     */
    public function named(string $name_c, $method, string $pattern, $handler): self
    {
        $full = $this->prefix . $pattern;

        // Parsed at declaration time, so a malformed pattern fails when the
        // route table is built rather than the first time someone links to it.
        Pattern::parse($full);

        $this->table->add($name_c, $full, $method);
        $this->dispatch($method, $full, $handler);

        return $this;
    }

    /**
     * Declare routes under a shared prefix.
     *
     * @param callable(self):void $definition
     */
    public function group(string $prefix, callable $definition): self
    {
        $definition(new self($this->table, $this->sink, $this->prefix . $prefix));

        return $this;
    }

    /**
     * @param string|string[] $method
     * @param mixed           $handler
     */
    private function dispatch($method, string $pattern, $handler): void
    {
        if ($this->sink === null) {
            return;
        }

        ($this->sink)($method, $pattern, $handler);
    }
}
