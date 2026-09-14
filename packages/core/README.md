# kitsune/core

The module kernel, tenancy primitives and runtime schema engine of [Kitsune](https://kitsunecms.org).

⚠️ **This repository is a read-only mirror.** It is split out of the
[Kitsune monorepo](https://github.com/adamgreenwell/kitsune) when a release is tagged. Issues, pull requests
and security reports belong there — see its `CONTRIBUTING.md` and `SECURITY.md`. Changes made here are
replaced by the next release.

## Using it

`kitsune/core` is a Laravel package, not an application: it expects a host that supplies its user model,
the membership pivots and a Filament panel. The monorepo's `skeleton/` is the reference host, and its
decision log records why each of those belongs to the host rather than to core.

## Licence

[Mozilla Public License 2.0](LICENSE).
