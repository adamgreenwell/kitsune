# kitsune/svg-sanitizer

Accept SVG uploads safely. This is the sanitiser [`kitsune/core`](../core) declares and deliberately does not
ship.

```bash
php artisan kitsune:module install kitsune/svg-sanitizer
php artisan kitsune:module enable kitsune/svg-sanitizer
```

Until it is enabled, core refuses `.svg` uploads and says so.

> **Monorepo-only, like `kitsune/person`**: no mirror, no Packagist entry, and
> [`split-packages.yml`](../../.github/workflows/split-packages.yml) still names `core` alone. There is
> deliberately no `composer require` line above, because there is nothing to require it from yet — publishing
> a second package is a decision rather than a consequence of tagging.

## Why this is a module rather than part of core

ADR-041 decided SVG is accepted and sanitised on upload, using a **maintained library** rather than a
hand-rolled walk — SVG is XML with namespaces, `xlink`, `foreignObject`, entity expansion and embedded CSS,
and its bypasses are discovered by other people, continuously. Buying that means buying the population who
discover them.

The library with that population is [`enshrined/svg-sanitize`](https://github.com/darylldoyle/svg-sanitizer):
50.9M downloads, 114 dependents, contributors from TYPO3, Automattic and Craft. It is **GPL-2.0-or-later**.

Kitsune core is MPL-2.0, and ADR-005 keeps it *plain* MPL-2.0 — no Exhibit B, enforced by AGENTS.md rule 7 —
precisely so that GPL code can lawfully be combined with it. But ADR-005 separately rejected GPL as **core's
own licence**, because that conflicts with ADR-004's paid modules and with proprietary third-party ones. Both
hold at once, so the dependency lives here:

- this package's own code is MPL-2.0 and may lawfully require a GPL library;
- `kitsune/core` keeps an MPL-only dependency graph;
- an operator who never installs this module never distributes GPL code with Kitsune.

This is Standing Principle #11 — *a feature outside core's own job starts as a first-party module, promoted
into core only on evidence* — applied to its first case.

## What core keeps

The gate. `MediaIntake` decides whether `svg` is an accepted extension at all, and the answer is no until
something implements `Kitsune\Core\Media\SanitisesSvg`. No setting widens that, which is ADR-041's *"an org
must not be able to widen its own allowlist"*. The escape ADR-041 reserved — refusing SVG outright — is just
this module not being installed.

Core also decides how the bytes are served: the stored `mime`, `nosniff`, a restrictive
`Content-Security-Policy`, and **attachment** rather than inline, since `MediaDelivery::INLINE` lists raster
types only.

## What this module does

Implements `SanitisesSvg` over the library, with `removeRemoteReferences` and `minify` both switched on —
neither is the default. It refuses input that cannot be parsed as XML, and input that sanitises down to no
SVG document at all, because a zero-value asset stored as a successful upload is a broken image nobody can
explain.

Sanitising happens **on write**, and no original is kept (ADR-041's departure 2). Disabling this module stops
new SVG uploads and changes nothing about SVGs already stored.
