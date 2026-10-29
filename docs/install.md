# Install Kitsune

## Download, inspect, then run

The operator path is **download, inspect, run**. Before executing an installer, obtain its explicitly versioned
file and checksum from the project domain over HTTPS, inspect what it changes, and verify the release signature
and checksum through the release's documented trust path. Never substitute a redirect, a floating script or a
command pasted from an unrelated site.

**The Phase 6 installer and signing/trust work are still open.** This development snapshot supplies no installer
URL, signing key or fabricated verification command. Track issues #213–#216 and the
[roadmap](roadmap.md). The steps above describe the required operator workflow; they do not claim a downloadable
release has been qualified. Use the [development checkout instructions](../README.md#running-it-locally) to evaluate the
current code locally, with disposable data.

The development [artifact tooling](release-artifacts.md) builds and signs local candidate manifests, verifies
selected downloads before PHP starts, and generates pinned download/inspect/run commands from authenticated
bytes. It supplies no production trust root, installer download or public one-liner; those release gates stay open.

## Choose a hosting path

The designed reference floor is **1 vCPU, 1 GB RAM, SQLite, no container runtime and no external services**.
Native PHP-FPM and SQLite are the recommended default. The PHP requirement is `^8.4`; use a supported PHP that
actually meets it, checking the selected distribution and release instructions rather than assuming an old
package source still covers your OS.

Docker is an equally supported design path for reproducibility, scale or operator preference. It is optional;
a separate database server, Redis, search daemon, Node runtime and always-on worker are not requirements for a
default small installation. The [resource-floor decision](decision-log.md#adr-027--the-resource-floor-is-a-designed-constraint-with-a-number)
states the constraint and its measurement obligations. This guide does not turn unfinished installer or floor
qualification into a result.

## Create your own first owner

The installer must never invent a default administrator. The current blueprint command can create the first
owner only while creating the first org on an installation with no org and no account:

```sh
php artisan kitsune:blueprint apply blog --org=myblog --owner=you@example.test
```

It asks for the password twice at a hidden prompt. For automation that already holds a secret,
`--owner-password-stdin` reads its first line from standard input. Never place the password in an argument,
environment variable or log. Passwords have a minimum of 15 characters; configuration may raise that floor.
The Phase 6 choice between terminal and protected web onboarding is still open (#214).

The README's `migrate --seed` path creates **development demonstration accounts with known passwords**. It is
not the operator onboarding path and must not be exposed as a new public installation.

## Before putting data in it

Set up [backup and restore](backup-restore.md), including secure custody of `APP_KEY` and any retained previous
keys. Read the [upgrade boundaries](upgrades.md) and the [security policy](../SECURITY.md). A local setup does not
qualify a public host; the [host runbook](../deploy/runbook/README.md) describes its separate, explicitly intrusive
checks and maintenance-window requirements.

The installer reports **nothing, ever**: no usage, version or successful-install ping. This documentation
artifact also has no telemetry, external search service, remotely fetched font or automatically requested
third-party image. See [ADR-026](decision-log.md#adr-026--self-hosting-one-command-no-database-server-no-phone-home).
