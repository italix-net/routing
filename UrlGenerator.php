<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix Routing - UrlGenerator
 *
 * @package Italix\Routing
 */

declare(strict_types=1);

namespace Italix\Routing;

/**
 * Builds a URL from a route name.
 *
 * The reason this exists: adding a second area to an application means hunting
 * hardcoded path prefixes through a dozen templates, and finding them by
 * reading. A name is a thing the compiler of last resort — an exception — can
 * check.
 *
 * **Defaults are ambient, parameters are not.** `lang` and `area` are set once
 * per request with `with()`; a default that a route does not use is simply
 * unused. A parameter passed explicitly to `to()` and consumed by nothing is a
 * typo, and is refused. That asymmetry is the whole ergonomic argument: it
 * keeps every call short without letting a misspelled name silently become a
 * query string.
 *
 * @example
 * $url->to('admin.articles.edit', ['id' => 41]);
 * // /it/admin/articles/41/edit.html
 */
final class UrlGenerator
{
    private RouteTable $routes;

    /** @var array<string, mixed> */
    private array $defaults;

    private string $base_path;

    /** Prepended to every name passed to to(); set by in() */
    private string $name_prefix = '';

    /**
     * @param array<string, mixed> $defaults
     */
    public function __construct(RouteTable $routes, array $defaults = [], string $base_path = '')
    {
        $this->routes    = $routes;
        $this->defaults  = $defaults;
        $this->base_path = rtrim($base_path, '/');
    }

    /**
     * A generator with these ambient values merged in. Returns a new instance.
     *
     * @param array<string, mixed> $defaults
     */
    public function with(array $defaults): self
    {
        return $this->derive(array_merge($this->defaults, $defaults), $this->base_path);
    }

    public function base_path(string $base_path): self
    {
        return $this->derive($this->defaults, $base_path);
    }

    /**
     * Resolve names inside a group: `in('editor')->to('articles.index')` asks
     * for `editor.articles.index`.
     *
     * This exists for the shape this framework keeps producing — one set of
     * screens served under two prefixes by the same controllers, differing only
     * in which middleware guards them. Without it every template would either
     * hardcode an area or build a route name by string concatenation, and
     * concatenating a name is the same mistake as concatenating a URL, one
     * layer up.
     *
     * There is **no fallback** to the bare name. A screen one area does not
     * have should fail when that area's template links to it, which is the check
     * hardcoded paths cannot perform.
     */
    public function in(string $group_c): self
    {
        $clone = $this->derive($this->defaults, $this->base_path);
        $clone->name_prefix = $group_c === '' ? '' : rtrim($group_c, '.') . '.';

        return $clone;
    }

    /**
     * A copy that keeps the current name prefix. Every builder goes through
     * here, so with() after in() does not silently drop the group.
     */
    private function derive(array $defaults, string $base_path): self
    {
        $clone = new self($this->routes, $defaults, $base_path);
        $clone->name_prefix = $this->name_prefix;

        return $clone;
    }

    /**
     * The name prefix currently applied, '' when none.
     */
    public function group_prefix(): string
    {
        return $this->name_prefix;
    }

    public function routes(): RouteTable
    {
        return $this->routes;
    }

    /**
     * @return array<string, mixed>
     */
    public function defaults(): array
    {
        return $this->defaults;
    }

    /**
     * Build the URL for a named route.
     *
     * @param  array<string, mixed> $params
     * @param  array<string, mixed> $query  appended as a query string
     * @throws UrlException on an unknown name, a missing parameter, a value the
     *                      pattern rejects, or a parameter nothing consumes
     */
    public function to(string $name_c, array $params = [], array $query = []): string
    {
        $name_c   = $this->name_prefix . $name_c;
        $pattern  = Pattern::parse($this->routes->pattern($name_c));
        $expected = $pattern->names();

        $unused = array_diff(array_keys($params), $expected);

        if ($unused !== []) {
            throw new UrlException(sprintf(
                'Route "%s" has no parameter%s %s — pattern is "%s"%s.',
                $name_c,
                count($unused) === 1 ? '' : 's',
                '"' . implode('", "', $unused) . '"',
                $pattern->source(),
                $expected === [] ? ' (it takes none)' : ' (it takes "' . implode('", "', $expected) . '")'
            ));
        }

        $url = $this->base_path . $pattern->build(
            array_merge($this->defaults, $params),
            $name_c
        );

        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }

        return $url;
    }

    /**
     * The URL with any parameter left unsupplied rendered as a `:name` token.
     *
     * For the one case a template cannot solve on the server: a link whose id
     * belongs to a table row the browser is about to draw.
     *
     *     const edit_url = <?= H::j($url->pattern_for('articles.edit')) ?>;
     *     // "/it/admin/articles/:id/edit.html"
     *     location.href = edit_url.replace(':id', row.id);
     *
     * The path still comes from the route table — only the leaf is substituted
     * client-side, instead of the whole URL being concatenated in JavaScript.
     *
     * @param array<string, mixed> $params
     */
    public function pattern_for(string $name_c, array $params = [], string $token_prefix = ':'): string
    {
        $name_c  = $this->name_prefix . $name_c;
        $pattern = Pattern::parse($this->routes->pattern($name_c));

        return $this->base_path . $pattern->build_template(
            array_merge(
                array_intersect_key($this->defaults, array_flip($pattern->names())),
                $params
            ),
            $token_prefix,
            $name_c
        );
    }

    /**
     * The URL, or null when it cannot be built.
     *
     * For the genuinely optional case — a breadcrumb that may or may not have a
     * target. Everything else should let the exception through: swallowing it
     * turns a broken link into a silently missing one.
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $query
     */
    public function try_to(string $name_c, array $params = [], array $query = []): ?string
    {
        try {
            return $this->to($name_c, $params, $query);
        } catch (UrlException $e) {
            return null;
        }
    }

    public function has(string $name_c): bool
    {
        return $this->routes->has($this->name_prefix . $name_c);
    }
}
