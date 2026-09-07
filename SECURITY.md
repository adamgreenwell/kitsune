# Security policy

## Reporting a vulnerability

**Do not open a public issue for a security vulnerability.**

Report it privately through GitHub:

**→ [Report a vulnerability](https://github.com/adamgreenwell/kitsune/security/advisories/new)**

That opens a private advisory visible only to you and the maintainer. It is preferred over email: the report is encrypted in transit, the discussion stays attached to the eventual fix, and a CVE can be issued from it directly if one is warranted.

If GitHub is not an option for you, say so in a public issue **without any detail about the vulnerability itself** — just that you need a private channel — and one will be arranged.

## What to expect

| | |
|---|---|
| Acknowledgement | within 5 working days |
| Initial assessment | within 10 working days |
| Fix or mitigation plan | communicated with the assessment |

This is a single-maintainer project with a stated bus factor of one ([`GOVERNANCE.md`](GOVERNANCE.md)). These are honest targets rather than a contractual SLA, and if one slips you will be told rather than left waiting.

## Severity, and the category that outranks everything

**Isolation failures are the highest-severity class in this project**, and will be treated that way regardless of how they are reached.

That means anything where data crosses a boundary it should not:

- **Cross-org** — one customer's data reachable by another. This is the worst thing that can happen to Kitsune. Filament's tenancy does not scope this level at all, so there is no framework safety net beneath it ([ADR-021](docs/decision-log.md))
- **Cross-site within one org** — one site's content reachable from another
- **Scope escape** — a model or query that bypasses its declared scope, including through a plugin

Also taken seriously, in roughly descending order: authentication and authorisation bypass, remote code execution, SQL injection, stored XSS in `rich_text` content, privilege escalation through the RBAC layer, and disclosure of data classified `personal` or `sensitive` under [ADR-020](docs/decision-log.md).

## Scope

**In scope:** `kitsune/core`, the app skeleton, first-party modules, and anything in this repository.

**Out of scope:** third-party plugins (report to their authors), vulnerabilities in Laravel or Filament themselves (report upstream — though tell us if Kitsune's usage makes one exploitable when it otherwise would not be), and denial of service through resource exhaustion on deliberately undersized hardware.

## Supported versions

Kitsune is **pre-alpha and has no released version**. There is nothing deployed to protect yet, and no security support commitment can honestly be made until there is.

A supported-versions table appears here at v1.0, alongside the published upgrade policy.

## Disclosure

Coordinated disclosure. A fix is prepared privately, released, and the advisory published once operators have had a reasonable window to upgrade. Reporters are credited unless they prefer otherwise.

Kitsune will not ask a reporter to sign an NDA, and will not treat a good-faith report as an attack.

## A note on language

Report in whatever language you are comfortable writing. The maintainer reads English natively and gets by in German and Italian; everything else goes through machine translation.

For security reports specifically, English is safer **if you can manage it** — a mistranslated technical detail has consequences a misunderstood feature request does not. If you cannot, report anyway. It will be worked through carefully.
