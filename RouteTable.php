<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix Routing - RouteTable
 *
 * @package Italix\Routing
 */

declare(strict_types=1);

namespace Italix\Routing;

/**
 * Named routes: name => [method, pattern].
 *
 * Deliberately holds no handlers. The table exists to answer one question —
 * "what does the URL for this name look like?" — and keeping the handlers out
 * means it can be built by replaying the route definitions without loading a
 * single controller class.
 *
 * That property is what makes named routes survive **route caching**. FastRoute
 * does not run the definition closure when the compiled dispatcher is cached,
 * so a table populated as a side effect of dispatching would be empty on every
 * cached request — and `$url->to()` would throw on production and work in
 * development, which is the worst possible failure schedule.
 */
final class RouteTable
{
    /** @var array<string, array{method: string, pattern: string}> */
    private array $routes = [];

    /**
     * Build a table by replaying route definitions with no dispatcher attached.
     *
     * @param callable(Registrar):void $definition
     */
    public static function record(callable $definition): self
    {
        $registrar = Registrar::recording();
        $definition($registrar);

        return $registrar->table();
    }

    /**
     * @param string|string[] $method
     */
    public function add(string $name_c, string $pattern, $method = 'GET'): self
    {
        if ($name_c === '') {
            throw new UrlException("A named route cannot have an empty name (pattern \"{$pattern}\").");
        }

        $method = is_array($method) ? (string) ($method[0] ?? 'GET') : (string) $method;

        if (isset($this->routes[$name_c]) && $this->routes[$name_c]['pattern'] !== $pattern) {
            throw new UrlException(sprintf(
                'Route name "%s" is used twice, for "%s" and "%s". Names must be unique.',
                $name_c,
                $this->routes[$name_c]['pattern'],
                $pattern
            ));
        }

        // The same name for GET and POST of one form is normal and intended:
        // the pattern is identical, so the first registration wins and the
        // second is a no-op rather than a conflict.
        $this->routes[$name_c] = ['method' => strtoupper($method), 'pattern' => $pattern];

        return $this;
    }

    public function has(string $name_c): bool
    {
        return isset($this->routes[$name_c]);
    }

    public function pattern(string $name_c): string
    {
        if (!isset($this->routes[$name_c])) {
            throw new UrlException($this->unknown_message($name_c));
        }

        return $this->routes[$name_c]['pattern'];
    }

    public function method_code(string $name_c): string
    {
        if (!isset($this->routes[$name_c])) {
            throw new UrlException($this->unknown_message($name_c));
        }

        return $this->routes[$name_c]['method'];
    }

    /**
     * @return string[]
     */
    public function names(): array
    {
        $names = array_keys($this->routes);
        sort($names);

        return $names;
    }

    /**
     * @return array<string, array{method: string, pattern: string}>
     */
    public function all(): array
    {
        $routes = $this->routes;
        ksort($routes);

        return $routes;
    }

    public function count(): int
    {
        return count($this->routes);
    }

    /**
     * An unknown name is almost always a typo or a rename, so say what exists
     * nearby rather than only what does not.
     */
    private function unknown_message(string $name_c): string
    {
        $near = [];

        foreach ($this->names() as $candidate) {
            if (strncmp($candidate, $name_c, max(1, strrpos($name_c, '.') ?: strlen($name_c))) === 0
                || levenshtein($name_c, $candidate) <= 3
            ) {
                $near[] = $candidate;
            }
        }

        $message = "No route is named \"{$name_c}\".";

        if ($near !== []) {
            $message .= ' Did you mean: ' . implode(', ', array_slice($near, 0, 5)) . '?';
        }

        return $message;
    }
}
