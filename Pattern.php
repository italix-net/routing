<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix Routing - Pattern
 *
 * @package Italix\Routing
 */

declare(strict_types=1);

namespace Italix\Routing;

/**
 * A route pattern, split into literals and placeholders, read backwards.
 *
 * The syntax is FastRoute's — `{name}` and `{name:regex}` — because that is
 * what the route table is already written in. This class **parses that string
 * format**; it does not use FastRoute, which is what lets this library depend
 * on nothing (house rule 13). The cost of that choice is stated plainly: if
 * FastRoute's syntax ever changes, this parser has to follow.
 *
 * Brace counting is not optional. `{token:[a-f0-9]{64}}` contains a `{64}`
 * quantifier inside the placeholder, so stopping at the first `}` would parse
 * the regex as `[a-f0-9]{64` and every generated token URL would be wrong in a
 * way that looks almost right.
 */
final class Pattern
{
    /** What a bare {name} matches in FastRoute */
    public const DEFAULT_REGEX = '[^/]+';

    private string $source;

    /** @var array<int, string|array{name: string, regex: string}> */
    private array $segments;

    /** @var string[] */
    private array $names;

    private function __construct(string $source, array $segments, array $names)
    {
        $this->source   = $source;
        $this->segments = $segments;
        $this->names    = $names;
    }

    public static function parse(string $pattern): self
    {
        $segments = [];
        $names    = [];
        $literal  = '';
        $length   = strlen($pattern);
        $i        = 0;

        while ($i < $length) {
            $char = $pattern[$i];

            if ($char === '[' || $char === ']') {
                // FastRoute's optional segments. Refused rather than guessed
                // at: generating a URL from one means deciding which optional
                // parts to include, and a wrong guess produces a plausible URL
                // that routes somewhere else (house rule 7).
                throw new UrlException(
                    "Optional segments are not supported for URL generation: \"{$pattern}\". "
                    . 'Declare the two forms as two named routes.'
                );
            }

            if ($char !== '{') {
                $literal .= $char;
                $i++;
                continue;
            }

            $close = self::matching_brace($pattern, $i);

            if ($close === null) {
                throw new UrlException("Unbalanced \"{\" in route pattern \"{$pattern}\".");
            }

            if ($literal !== '') {
                $segments[] = $literal;
                $literal    = '';
            }

            $body = substr($pattern, $i + 1, $close - $i - 1);
            $pos  = strpos($body, ':');

            $name  = $pos === false ? $body : substr($body, 0, $pos);
            $regex = $pos === false ? self::DEFAULT_REGEX : substr($body, $pos + 1);

            $name = trim($name);

            if ($name === '') {
                throw new UrlException("Unnamed placeholder in route pattern \"{$pattern}\".");
            }

            $segments[] = ['name' => $name, 'regex' => $regex];
            $names[]    = $name;

            $i = $close + 1;
        }

        if ($literal !== '') {
            $segments[] = $literal;
        }

        return new self($pattern, $segments, $names);
    }

    public function source(): string
    {
        return $this->source;
    }

    /**
     * @return string[]
     */
    public function names(): array
    {
        return $this->names;
    }

    public function regex_for(string $name): ?string
    {
        foreach ($this->segments as $segment) {
            if (is_array($segment) && $segment['name'] === $name) {
                return $segment['regex'];
            }
        }

        return null;
    }

    /**
     * Fill the placeholders.
     *
     * Values are validated against their own regex **before** being encoded,
     * so the check sees what the author wrote rather than a percent-encoded
     * version of it.
     *
     * @param  array<string, mixed> $params
     * @throws UrlException on a missing or non-matching parameter
     */
    public function build(array $params, string $route_name_c = ''): string
    {
        $where = $route_name_c === '' ? "pattern \"{$this->source}\"" : "route \"{$route_name_c}\"";
        $url   = '';

        foreach ($this->segments as $segment) {
            if (is_string($segment)) {
                $url .= $segment;
                continue;
            }

            $name = $segment['name'];

            if (!array_key_exists($name, $params)) {
                throw new UrlException(
                    "{$where} is missing parameter \"{$name}\" — pattern is \"{$this->source}\"."
                );
            }

            $value = $params[$name];

            if (is_bool($value) || $value === null || is_array($value) || is_object($value)) {
                throw new UrlException(sprintf(
                    '%s parameter "%s" must be a string or a number, %s given.',
                    ucfirst($where),
                    $name,
                    gettype($value)
                ));
            }

            $value = (string) $value;

            if (!self::matches($segment['regex'], $value)) {
                throw new UrlException(sprintf(
                    '%s parameter "%s" — "%s" does not match (%s)',
                    ucfirst($where),
                    $name,
                    $value,
                    $segment['regex']
                ));
            }

            $url .= rawurlencode($value);
        }

        return $url;
    }

    /**
     * Fill what is supplied and leave the rest as `:name` tokens.
     *
     * For URLs whose last parameter is only known in the browser — a Tabulator
     * row's id, a value the user has not chosen yet. The alternative in a
     * template is string concatenation in JavaScript, which is the very thing
     * named routes exist to remove; here the path still comes from the route
     * table and only the leaf is substituted client-side.
     *
     * Supplied values are validated exactly as in build(). Tokens are not: they
     * stand for a value this side has never seen.
     *
     * @param array<string, mixed> $params
     */
    public function build_template(array $params, string $token_prefix = ':', string $route_name_c = ''): string
    {
        $url = '';

        foreach ($this->segments as $segment) {
            if (is_string($segment)) {
                $url .= $segment;
                continue;
            }

            $name = $segment['name'];

            if (!array_key_exists($name, $params)) {
                $url .= $token_prefix . $name;
                continue;
            }

            $url .= self::parse($this->placeholder($name))->build([$name => $params[$name]], $route_name_c);
        }

        return $url;
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    /**
     * The `{name:regex}` source for one placeholder, so a single value can be
     * validated and encoded through the same path build() uses.
     */
    private function placeholder(string $name): string
    {
        $regex = $this->regex_for($name);

        return $regex === self::DEFAULT_REGEX || $regex === null
            ? '{' . $name . '}'
            : '{' . $name . ':' . $regex . '}';
    }

    /**
     * The index of the `}` that closes the `{` at $open, counting nested braces.
     */
    private static function matching_brace(string $pattern, int $open): ?int
    {
        $depth  = 0;
        $length = strlen($pattern);

        for ($i = $open; $i < $length; $i++) {
            if ($pattern[$i] === '{') {
                $depth++;
                continue;
            }

            if ($pattern[$i] === '}') {
                $depth--;

                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return null;
    }

    /**
     * Anchored match. The delimiter is `~` with the subject's own `~` escaped,
     * so a regex containing `/` — a path fragment — needs no special casing.
     */
    private static function matches(string $regex, string $value): bool
    {
        $delimited = '~^(?:' . str_replace('~', '\~', $regex) . ')$~Du';

        $result = @preg_match($delimited, $value);

        if ($result === false) {
            throw new UrlException("Route parameter regex is not valid: ({$regex})");
        }

        return $result === 1;
    }
}
