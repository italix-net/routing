<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix Routing — Pattern parsing and building
 *
 * The case this suite exists for is `{token:[a-f0-9]{64}}`. A parser that stops
 * at the first `}` reads the regex as `[a-f0-9]{64` — which is not a syntax
 * error, just a different pattern — and every generated magic link is subtly
 * wrong. Password-reset and e-mail-confirmation links are exactly this shape.
 *
 * Run: php src/Libs/Italix/Routing/tests/PatternTest.php
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

use Italix\Routing\Pattern;
use Italix\Routing\UrlException;

use function Italix\Testing\{suite, section, test, summary};

suite('Italix Routing — Pattern');

/** @return array{0: bool, 1: string} did it throw, and with what message */
$throws = static function (callable $fn): array {
    try {
        $fn();

        return [false, ''];
    } catch (UrlException $e) {
        return [true, $e->getMessage()];
    }
};

// -----------------------------------------------------------------------------
section('parsing');

$p = Pattern::parse('/it/admin/articles/index.html');
test('a literal pattern has no names', $p->names() === []);
test('…and builds to itself', $p->build([]) === '/it/admin/articles/index.html');

$p = Pattern::parse('/{lang:en|it}/{area:admin|editor}/articles/{id:new|\d+}/edit.html');
test('every placeholder is found', $p->names() === ['lang', 'area', 'id'], json_encode($p->names()));
test('the regex is captured', $p->regex_for('id') === 'new|\d+', (string) $p->regex_for('id'));
test('an unknown name has no regex', $p->regex_for('nope') === null);
test('source() is the original', strpos($p->source(), '{lang:en|it}') !== false);

$p = Pattern::parse('/{lang}/home.html');
test('a bare {name} defaults to [^/]+', $p->regex_for('lang') === Pattern::DEFAULT_REGEX);

// -----------------------------------------------------------------------------
section('nested braces — the quantifier case');

$p = Pattern::parse('/{lang:en|it}/reset-password/{token:[a-f0-9]{64}}');

test('the placeholder names survive', $p->names() === ['lang', 'token'], json_encode($p->names()));
test(
    'the {64} quantifier stays inside the regex',
    $p->regex_for('token') === '[a-f0-9]{64}',
    'got: ' . var_export($p->regex_for('token'), true)
);

$token_c = str_repeat('a1b2', 16);            // 64 hex characters
test('strlen check on the fixture', strlen($token_c) === 64);
test(
    'a 64-character token builds',
    $p->build(['lang' => 'it', 'token' => $token_c]) === '/it/reset-password/' . $token_c
);

[$threw, $message] = $throws(static function () use ($p, $token_c): void {
    $p->build(['lang' => 'it', 'token' => substr($token_c, 0, 63)]);
});
test('a 63-character token is refused', $threw, 'message: ' . $message);
test('…naming the parameter', strpos($message, '"token"') !== false, $message);

$p = Pattern::parse('/x/{a:\d{2,4}}/y');
test('a {2,4} quantifier parses', $p->regex_for('a') === '\d{2,4}', (string) $p->regex_for('a'));
test('…and accepts 3 digits', $p->build(['a' => '123']) === '/x/123/y');
[$threw] = $throws(static function () use ($p): void { $p->build(['a' => '1']); });
test('…and refuses 1 digit', $threw);

// -----------------------------------------------------------------------------
section('building');

$p = Pattern::parse('/{lang:en|it}/{area:admin|editor}/articles/{id:new|\d+}/edit.html');

test(
    'a full URL is built',
    $p->build(['lang' => 'it', 'area' => 'admin', 'id' => '41']) === '/it/admin/articles/41/edit.html'
);
test(
    'an int parameter is accepted',
    $p->build(['lang' => 'it', 'area' => 'editor', 'id' => 41]) === '/it/editor/articles/41/edit.html'
);
test(
    'the literal alternative "new" matches',
    $p->build(['lang' => 'en', 'area' => 'admin', 'id' => 'new']) === '/en/admin/articles/new/edit.html'
);

[$threw, $message] = $throws(static function () use ($p): void {
    $p->build(['lang' => 'it', 'area' => 'admin', 'id' => 'abc'], 'admin.articles.edit');
});
test('a value the regex rejects throws', $threw);
test('…naming the route', strpos($message, 'admin.articles.edit') !== false, $message);
test('…the parameter', strpos($message, '"id"') !== false, $message);
test('…the value', strpos($message, '"abc"') !== false, $message);
test('…and the pattern it failed', strpos($message, '(new|\d+)') !== false, $message);

[$threw, $message] = $throws(static function () use ($p): void {
    $p->build(['lang' => 'it', 'area' => 'admin'], 'admin.articles.edit');
});
test('a missing parameter throws', $threw);
test('…naming it', strpos($message, '"id"') !== false, $message);
test('…and showing the pattern', strpos($message, 'edit.html') !== false, $message);

[$threw, $message] = $throws(static function () use ($p): void {
    $p->build(['lang' => 'it', 'area' => 'admin', 'id' => ['x']]);
});
test('an array parameter is refused, not stringified', $threw, $message);

[$threw] = $throws(static function () use ($p): void {
    $p->build(['lang' => 'it', 'area' => 'admin', 'id' => null]);
});
test('null is refused rather than becoming an empty segment', $threw);

// -----------------------------------------------------------------------------
section('encoding');

$p = Pattern::parse('/search/{q}');

test('a space is encoded', $p->build(['q' => 'a b']) === '/search/a%20b');
test('an accent is encoded', $p->build(['q' => 'città']) === '/search/citt%C3%A0');
test('a hash cannot escape the segment', $p->build(['q' => 'a#b']) === '/search/a%23b');
test('a question mark cannot start a query', $p->build(['q' => 'a?b']) === '/search/a%3Fb');

// The regex is what stops traversal; encoding is the second line of defence.
[$threw] = $throws(static function () use ($p): void { $p->build(['q' => 'a/b']); });
test('a slash is refused by the default [^/]+ regex', $threw);

$p = Pattern::parse('/x/{a:.+}');
test('…and encoded when a permissive regex allows it', $p->build(['a' => 'a/b']) === '/x/a%2Fb');

// -----------------------------------------------------------------------------
section('malformed patterns are refused at parse time');

[$threw, $message] = $throws(static function (): void { Pattern::parse('/x/{unclosed'); });
test('an unbalanced brace throws', $threw);
test('…naming the pattern', strpos($message, '{unclosed') !== false, $message);

[$threw] = $throws(static function (): void { Pattern::parse('/x/{}'); });
test('an unnamed placeholder throws', $threw);

[$threw, $message] = $throws(static function (): void { Pattern::parse('/x[/{id:\d+}]'); });
test('an optional segment is refused rather than guessed at', $threw);
test('…and says what to do instead', strpos($message, 'two named routes') !== false, $message);

[$threw, $message] = $throws(static function (): void {
    Pattern::parse('/x/{a:[}')->build(['a' => 'z']);
});
test('an invalid regex is reported as such', $threw, $message);

exit(summary());
