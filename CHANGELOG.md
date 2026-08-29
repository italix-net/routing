# Changelog — italix/routing

Format: [Keep a Changelog](https://keepachangelog.com/). Versioning policy: `VERSIONING.md` at the
project root.

## [2.0.0] — 2026-08-28

### Changed — BREAKING

`_c` on function/method names is retired in favor of spelling out what the value actually is —
see `src/Libs/Italix/CONVENTIONS.md`, "`_c` is for variables... only." `_c` stays on variables,
parameters and properties; only the method name changed, no behavior:

- `RouteTable::method_c()` → `method_code()`

## [1.0.1] — 2026-08-13

### Legal

- **Licensed under MPL-2.0**, applied 2026-08-13: the `license` field in `composer.json`, a `LICENSE`
  file, and the Exhibit A notice in every source file — MPL §1.4 defines "Covered Software" per file,
  so the per-file header is what makes the licence apply rather than decoration.

  This is a **first declaration, not a relicensing.** The package carried no licence at all before,
  which in most jurisdictions means all rights reserved: nothing had been granted, so nothing is
  taken away and no consumer's position gets worse. That is why it is recorded here rather than
  treated as a breaking change — unlike `italix/orm`, which went Apache-2.0 → MPL-2.0 and took a
  MAJOR because that direction does narrow what a consumer already had.

## [1.0.0] — 2026-08

First release. See `README.md` for usage.

### Added

- **`Pattern`** — parses FastRoute's `{name}` / `{name:regex}` syntax and builds URLs from it. Counts
  nested braces, so `{token:[a-f0-9]{64}}` keeps its quantifier. Values are validated against their
  own regex **before** being percent-encoded.
- **`RouteTable`** — name → method + pattern. Holds no handlers, which is what lets it be built
  without loading a controller.
- **`Registrar`** — `named()`, `route()`, `group()`. Forwards to the dispatcher through a **callable
  sink**, so this library needs no FastRoute dependency and no duck-typing.
- **`UrlGenerator`** — `to()`, `try_to()`, `has()`, plus `with()` for ambient defaults, `in()` for
  a name group, `base_path()`, and `pattern_for()`.

  **`in($area)`** resolves a name inside a group: `in('portal')->to('subjects.index')` asks for
  `portal.subjects.index`. It exists for the shape this framework keeps producing — one set of
  screens under two prefixes, guarded by different middleware. Without it a template would either
  hardcode an area or build a route name by concatenation, which is the same mistake as
  concatenating a URL, one layer up. There is no fallback to the bare name.

  **`pattern_for($name)`** renders unsupplied parameters as `:name` tokens, for the one case a
  template cannot resolve on the server — a link whose id belongs to a table row the browser is
  about to draw. The path still comes from the route table; only the leaf is substituted in
  JavaScript.
- **`UrlException`** — every message names the route; a parameter fault also names the parameter,
  the value and the pattern it failed.
- `functions.php` — `route_table()` and `url_generator()` (house rule 9).

### Two decisions worth knowing

**Named routes survive route caching.** FastRoute does not replay the definition closure when its
compiled dispatcher is cached, so a table filled as a side effect of dispatching would be empty in
production and full in development — the worst possible failure schedule. `RouteTable::record()`
replays the definitions with no sink attached, which is cheap because no handler is resolved.

**Defaults are ambient, explicit parameters are not.** `lang` and `area` are set once per request
with `with()`, and a default a route does not use is simply unused. A parameter passed to `to()` and
consumed by nothing is refused — so `agencyid` cannot silently become a query string. That asymmetry
is what keeps every call site short without losing typo detection.

### Deliberately not

- **Optional segments (`[...]`) are refused**, not guessed at. Generating a URL from one means
  deciding which optional parts to include, and a wrong guess produces a plausible URL that routes
  somewhere else. Declare the two forms as two named routes.
- **No automatic naming from the controller class.** A name is an interface; deriving it from a
  class name means renaming the class breaks every template.
- **No route model binding**, no reverse matching of a URL back to a name, and no dispatching — this
  library never sees a request.
