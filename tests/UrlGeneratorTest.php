<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix Routing — Registrar, RouteTable and UrlGenerator
 *
 * Two properties carry the weight here.
 *
 * **A group prefix must reach the table.** A name registered inside
 * `group('/{lang:en|it}')` whose stored pattern lacks the prefix generates URLs
 * that are missing their language segment — links that 404 for every user while
 * the route itself works fine when typed by hand.
 *
 * **The table must build with no dispatcher.** FastRoute does not replay the
 * definitions when its compiled dispatcher is cached, so a table populated as a
 * side effect of dispatching is empty in production and full in development.
 *
 * Run: php src/Libs/Italix/Routing/tests/UrlGeneratorTest.php
 */

declare(strict_types=1);

// The autoloader, wherever this library happens to sit: vendored inside a
// project, or installed as a package under vendor/italix/*. Hardcoding one
// depth makes the suite runnable in exactly one arrangement.
(static function (): void {
    foreach ([
        __DIR__ . '/../vendor/autoload.php',               // checked out on its own
        __DIR__ . '/../../../../../vendor/autoload.php',   // vendored in a project
        __DIR__ . '/../../../../vendor/autoload.php',      // installed as a package
        __DIR__ . '/../../../autoload.php',                // sibling autoloader
    ] as $autoload) {
        if (is_file($autoload)) {
            require_once $autoload;

            return;
        }
    }

    fwrite(STDERR, "Could not find an autoloader. Run composer install.\n");
    exit(2);
})();

use Italix\Routing\Registrar;
use Italix\Routing\RouteTable;
use Italix\Routing\UrlException;
use Italix\Routing\UrlGenerator;

use function Italix\Routing\{route_table, url_generator};
use function Italix\Testing\{suite, section, test, summary};

suite('Italix Routing — UrlGenerator');

$throws = static function (callable $fn): array {
    try {
        $fn();

        return [false, ''];
    } catch (UrlException $e) {
        return [true, $e->getMessage()];
    }
};

/** The shape of this project's real route file, in miniature. */
$definition = static function (Registrar $r): void {
    $r->route('GET', '/', 'HomeAction');

    $r->group('/{lang:en|it}', static function (Registrar $r): void {
        $r->named('home', 'GET', '', 'HomeAction');
        $r->named('verify', ['GET', 'POST'], '/reset-password/{token:[a-f0-9]{64}}', 'VerifyAction');
        $r->named('admin.login', 'GET', '/admin/login.html', 'LoginAction');

        $r->group('/{area:admin|editor}', static function (Registrar $r): void {
            $r->named('articles.index', 'GET', '/articles/index.html', 'SubjectsAction');
            $r->named('articles.edit', 'GET', '/articles/{id:new|\d+}/edit.html', 'SubjectAction');
            $r->route('POST', '/articles/{id:\d+}/delete', 'SubjectAction');
        });
    });
};

// -----------------------------------------------------------------------------
section('recording without a dispatcher');

$table = route_table($definition);

test('named routes are recorded', $table->has('articles.edit'));
test('unnamed routes are not', !$table->has('') && $table->count() === 5, 'count: ' . $table->count());
test('names are sorted', $table->names() === [
    'admin.login', 'articles.edit', 'articles.index', 'home', 'verify',
], json_encode($table->names()));
test('the method is kept', $table->method_code('articles.index') === 'GET');
test('an array of methods keeps the first', $table->method_code('verify') === 'GET');

// -----------------------------------------------------------------------------
section('group prefixes reach the table');

test(
    'a single group prefix is stored',
    $table->pattern('admin.login') === '/{lang:en|it}/admin/login.html',
    $table->pattern('admin.login')
);
test(
    'nested group prefixes are stored in order',
    $table->pattern('articles.edit') === '/{lang:en|it}/{area:admin|editor}/articles/{id:new|\d+}/edit.html',
    $table->pattern('articles.edit')
);
test('an empty pattern inside a group is just the prefix', $table->pattern('home') === '/{lang:en|it}');

// -----------------------------------------------------------------------------
section('the dispatcher sink sees the same patterns');

$seen = [];
$registrar = Registrar::into(static function ($method, string $pattern, $handler) use (&$seen): void {
    $seen[] = [is_array($method) ? implode('|', $method) : $method, $pattern, $handler];
});
$definition($registrar);

test('every route reaches the sink, named or not', count($seen) === 7, 'count: ' . count($seen));
test('the unnamed root is dispatched', $seen[0] === ['GET', '/', 'HomeAction'], json_encode($seen[0]));
test(
    'the sink gets the full prefixed pattern',
    $seen[5][1] === '/{lang:en|it}/{area:admin|editor}/articles/{id:new|\d+}/edit.html',
    $seen[5][1]
);
test('the handler is passed through untouched', $seen[5][2] === 'SubjectAction');
test('multiple methods are passed through as given', $seen[2][0] === 'GET|POST', $seen[2][0]);
test('recording and dispatching produce the same table', $registrar->table()->all() === $table->all());

// -----------------------------------------------------------------------------
section('generating');

$url = url_generator($table);

test(
    'a URL is built from a name',
    $url->to('articles.edit', ['lang' => 'it', 'area' => 'admin', 'id' => 41])
        === '/it/admin/articles/41/edit.html'
);
test(
    'the same name serves the other area',
    $url->to('articles.index', ['lang' => 'it', 'area' => 'editor'])
        === '/it/editor/articles/index.html'
);
test('a query string is appended', $url->to('home', ['lang' => 'it'], ['page' => 2]) === '/it?page=2');
test('an empty query adds no ?', strpos($url->to('home', ['lang' => 'it']), '?') === false);
test('has() reports a known name', $url->has('home'));
test('has() reports an unknown one', !$url->has('nope'));

// -----------------------------------------------------------------------------
section('ambient defaults, explicit parameters');

$scoped = $url->with(['lang' => 'it', 'area' => 'editor']);

test('defaults fill the placeholders', $scoped->to('articles.edit', ['id' => 41]) === '/it/editor/articles/41/edit.html');
test('an explicit value overrides a default', $scoped->to('articles.edit', ['area' => 'admin', 'id' => 41])
    === '/it/admin/articles/41/edit.html');
test('a default a route does not use is simply unused', $scoped->to('admin.login') === '/it/admin/login.html');
test('with() returns a new generator', $url->defaults() === []);
test('with() merges rather than replaces', $scoped->with(['area' => 'admin'])->defaults()
    === ['lang' => 'it', 'area' => 'admin']);
test('with() returns a UrlGenerator', $scoped instanceof UrlGenerator);

// The asymmetry that makes the defaults safe.
[$threw, $message] = $throws(static function () use ($scoped): void {
    $scoped->to('articles.edit', ['id' => 41, 'ownerid' => 2]);
});
test('an explicit parameter nothing consumes is refused', $threw);
test('…naming the offender', strpos($message, 'ownerid') !== false, $message);
test('…and listing what the route does take', strpos($message, '"id"') !== false, $message);

// -----------------------------------------------------------------------------
section('failures name the route');

[$threw, $message] = $throws(static function () use ($scoped): void {
    $scoped->to('articles.edti', ['id' => 1]);
});
test('an unknown name throws', $threw);
test('…and suggests the real one', strpos($message, 'articles.edit') !== false, $message);

[$threw, $message] = $throws(static function () use ($url): void {
    $url->to('articles.edit', ['id' => 41]);
});
test('a missing ambient parameter throws', $threw);
test('…naming it', strpos($message, '"lang"') !== false, $message);

[$threw, $message] = $throws(static function () use ($scoped): void {
    $scoped->to('articles.edit', ['id' => 'abc']);
});
test('a value the pattern rejects throws', $threw);
test('…with the route, the value and the pattern', strpos($message, 'articles.edit') !== false
    && strpos($message, '"abc"') !== false && strpos($message, 'new|\d+') !== false, $message);

test('try_to() returns null instead of throwing', $scoped->try_to('articles.edit', ['id' => 'abc']) === null);
test('try_to() returns the URL when it works', $scoped->try_to('articles.edit', ['id' => 7])
    === '/it/editor/articles/7/edit.html');

// -----------------------------------------------------------------------------
section('the table refuses ambiguity');

[$threw, $message] = $throws(static function (): void {
    (new RouteTable())
        ->add('articles.edit', '/a/{id:\d+}')
        ->add('articles.edit', '/b/{id:\d+}');
});
test('one name for two patterns is refused', $threw);
test('…showing both patterns', strpos($message, '/a/') !== false && strpos($message, '/b/') !== false, $message);

$same = (new RouteTable())
    ->add('articles.save', '/a/{id:\d+}', 'GET')
    ->add('articles.save', '/a/{id:\d+}', 'POST');
test('the same name for GET and POST of one form is fine', $same->count() === 1);

[$threw] = $throws(static function (): void { (new RouteTable())->add('', '/x'); });
test('an empty name is refused', $threw);

[$threw, $message] = $throws(static function (): void {
    Registrar::recording()->named('bad', 'GET', '/x/{unclosed', 'SomeAction');
});
test('a malformed pattern fails when the table is built, not when linked to', $threw, $message);

// -----------------------------------------------------------------------------
section('base path');

$sub = url_generator($table, ['lang' => 'it', 'area' => 'admin'], '/app/');

test('a base path is prefixed', $sub->to('articles.index') === '/app/it/admin/articles/index.html');
test('a trailing slash on the base path is not doubled',
    strpos($sub->to('articles.index'), '//') === false, $sub->to('articles.index'));

// -----------------------------------------------------------------------------
section('template URLs for the browser');

$scoped = $url->with(['lang' => 'it'])->in('');

test(
    'an unsupplied parameter becomes a token',
    $scoped->pattern_for('articles.edit', ['area' => 'admin']) === '/it/admin/articles/:id/edit.html',
    $scoped->pattern_for('articles.edit', ['area' => 'admin'])
);
test(
    'supplied parameters are still filled',
    $scoped->pattern_for('articles.index', ['area' => 'editor']) === '/it/editor/articles/index.html'
);
test(
    'a route with nothing left over is a plain URL',
    $scoped->pattern_for('admin.login') === '/it/admin/login.html'
);
test(
    'the token prefix is configurable',
    $scoped->pattern_for('articles.edit', ['area' => 'admin'], '__') === '/it/admin/articles/__id/edit.html'
);
test(
    'several tokens survive together',
    $url->pattern_for('articles.edit') === '/:lang/:area/articles/:id/edit.html',
    $url->pattern_for('articles.edit')
);

// A supplied value is still checked — a template must not emit a bad prefix.
[$threw, $message] = $throws(static function () use ($scoped): void {
    $scoped->pattern_for('articles.edit', ['area' => 'nope']);
});
test('a supplied value is still validated', $threw, $message);

$token_c = str_repeat('ab', 32);
test(
    'a validated value with a quantifier regex round-trips',
    $scoped->pattern_for('verify', ['token' => $token_c]) === '/it/reset-password/' . $token_c
);

exit(summary());
