# Italix Routing

[![PHP Version](https://img.shields.io/badge/php-%3E%3D7.4-8892BF.svg)](https://php.net/)
[![License](https://img.shields.io/badge/license-MPL%202.0-blue.svg)](LICENSE)

Named routes and URL generation. Declare a route once, link to it by name, and find out at
generation time when the link is wrong instead of when a user reports a 404.

Zero dependencies. It parses FastRoute's pattern *syntax*; it does not use FastRoute, and it never
sees a request.

```bash
php src/Libs/Italix/Routing/tests/PatternTest.php
php src/Libs/Italix/Routing/tests/UrlGeneratorTest.php
```

---

## Declaring

`routes.php` returns `fn(Registrar $r): void`:

```php
return function (Registrar $r) use ($route) {

    $r->group('/{lang:en|it}', function (Registrar $r) use ($route) {

        $r->named('password.reset', ['GET', 'POST'], '/reset-password/{token:[a-f0-9]{64}}',
            $route([PasswordResetAction::class, 'display']));

        $r->named('admin.users.edit', 'GET', '/admin/users/{id:new|\d+}/edit.html',
            $route([AdminUserAction::class, 'edit'], $admin));

        // Unnamed routes still dispatch; they just cannot be linked to.
        $r->route('GET', '/article/{id:\d+}', ArticleAction::class);
    });
};
```

`group()` is this library's own, not FastRoute's, because the table has to record the **full**
pattern. A name whose stored pattern is missing its group prefix generates links without the
language segment — which 404 for every user while the route works fine when typed by hand.

---

## Linking

```php
$url->to('admin.users.edit', ['lang' => 'it', 'id' => 41]);
// /it/admin/users/41/edit.html
```

### Ambient defaults

`lang` does not belong in every call. Set it once:

```php
$url = $this->url->with(['lang' => $lang]);

$url->to('admin.users.index');            // /it/admin/users/index.html
$url->to('admin.users.edit', ['id' => 41]);
```

**A default a route does not use is simply unused. An explicit parameter nothing consumes is
refused.** That asymmetry is the whole ergonomic argument — short call sites, without letting a
typo like `userid` silently turn into a query string.

### Areas

An application often serves one set of screens under two prefixes — say `/admin/` for staff and
`/portal/` for customers — guarded by different middleware. `in()` resolves a name inside a group:

```php
$url = $this->url->with(['lang' => $lang])->in($area);   // 'admin' or 'portal'

$url->to('orders.index');          // admin.orders.index  or  portal.orders.index
$url->to('orders.edit', ['id' => 7]);
```

There is **no fallback** to the bare name. `in('portal')->to('settings.index')` throws when the
portal area has no such screen — the check a hardcoded `/admin/` string could never perform.

### In a template

```php
<?php use Italix\Encode\Html as H; ?>

<a href="<?= H::url($url->to('users.edit', ['id' => $id])) ?>">…</a>
```

`H::url()` still encodes. The generator produces a correct path; `Encode` decides it is safe to put
in an attribute. Two jobs, two libraries.

---

## Failing early

Every message names the route. A parameter fault also names the parameter, the value and the pattern
it failed:

```
Italix\Routing\UrlException: Route "admin.users.edit" parameter "id" — "abc" does not match (new|\d+)
```

An unknown name suggests the nearest ones:

```
No route is named "admin.user.edit". Did you mean: admin.users.edit, admin.users.index?
```

`try_to()` returns `null` instead of throwing — for the genuinely optional case, a breadcrumb that
may have no target. Everywhere else, let the exception through: swallowing it turns a broken link
into a silently missing one.

---

## Route caching

FastRoute does not replay the definition closure when its compiled dispatcher is cached. A name
table filled as a side effect of dispatching would therefore be **empty in production and full in
development** — the worst possible failure schedule.

So the definitions are replayed twice, from one declaration:

```php
$route_definitions = require __DIR__ . '/routes.php';

// Dispatcher pass — a plain callable is the seam
'routes' => static function (RouteCollector $r) use ($route_definitions): void {
    $route_definitions(Registrar::into(
        static function ($method, string $pattern, $handler) use ($r): void {
            $r->addRoute($method, $pattern, $handler);
        }
    ));
},

// Table pass — no sink, so no handler is resolved
RouteTable::class => static fn(): RouteTable => RouteTable::record($route_definitions),
```

---

## Nested braces

`{token:[a-f0-9]{64}}` contains a `{64}` quantifier inside the placeholder. A parser that stops at
the first `}` reads the regex as `[a-f0-9]{64` — not a syntax error, just a *different pattern* — and
every generated magic link comes out subtly wrong. Password-reset and e-mail-confirmation links are
exactly this shape, so the brace counting is tested directly.

---

## From the CLI

```bash
ix routes                                   # every named route
ix routes admin.users                       # filtered by prefix
ix routes --params=lang=it,id=41            # also render each URL
```

---

## Deliberately not

- **Optional segments (`[...]`) are refused.** Generating a URL from one means deciding which parts
  to include, and a wrong guess produces a plausible URL that routes somewhere else. Declare the two
  forms as two named routes.
- **No automatic naming from the controller class.** A name is an interface; deriving it from a class
  name means renaming the class breaks every template.
- **No route model binding, no reverse matching, no dispatching.** This library turns a name into a
  string. The router routes.
