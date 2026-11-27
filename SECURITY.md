# Security policy

## Reporting a vulnerability

**Do not open a public issue for a security vulnerability.**

Report it privately through GitHub:

**→ [Report a vulnerability](https://github.com/adamgreenwell/kitsune/security/advisories/new)**

That opens a private advisory. Access follows [GitHub’s advisory permissions](https://docs.github.com/en/code-security/reference/permissions/repository-security-advisory), including eligible repository security roles and explicitly invited collaborators. The report is encrypted in transit, the discussion stays attached to the eventual fix, and a CVE can be issued from it if warranted. Review the participants before adding sensitive material.

If GitHub is not an option for you, say so in a public issue **without any detail about the vulnerability itself** — just that you need a private channel — and one will be arranged.

## What to expect

| | |
|---|---|
| Acknowledgement | within 5 working days |
| Initial assessment | within 10 working days |
| Fix or mitigation plan | communicated with the assessment |

This is a single-maintainer project with a stated bus factor of one ([`GOVERNANCE.md`](GOVERNANCE.md)). These are honest targets rather than a contractual SLA, and if one slips you will be told rather than left waiting.

## Operational responsibility and availability

**Adam Greenwell (`adamgreenwell`) is the primary owner of intake, triage, private patch coordination and release/disclosure decisions.** These are responsibilities held by the same maintainer, not additional staff or continuous coverage.

No continuity delegate is currently named and qualified. When Adam is unavailable, responses can be delayed and security release/disclosure decisions wait for an available, authorized owner. A delayed report is acknowledged when the owner returns. Nothing automatically forwards private reports, grants access, publishes an advisory or transfers release authority. A future delegate needs their consent, appropriate private access and a verified notification handoff before being relied on.

The existing 5- and 10-working-day targets remain targets, not a new availability guarantee. The [response runbook](docs/security-response.md) separates written procedure from an actual intake, notification and human-acknowledgement rehearsal. A form or enabled setting alone does not establish that the process works.

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

Kitsune is **pre-alpha**. 0.x development packages exist; v1.0 is not released. Package publication is not a
stable-version security-maintenance commitment, and this policy makes no claim about where that code is deployed.

| Version | Security-maintenance status |
|---|---|
| 0.x development packages, including v0.1.0, v0.1.1 and v0.2.0 | No stable-version support commitment; private reports are welcome. |
| v1.0 and later stable minors | Not yet released; no currently supported stable version is declared. |

Adam selected **the latest stable minor, at its latest patch**, as the maintenance model. Before the first stable
release, name its supported minor and current patch here. Before a later release supersedes a supported minor,
publish upgrade instructions and the maintainer's chosen transition/notice and end-of-support terms. No calendar
support duration, overlap, EOL date or superseded-major coverage is inferred. These remain release decisions.

The [versioning and upgrade policy](docs/versioning-and-upgrades.md) defines the compatibility boundaries and
case-by-case admission of retained pre-v1.0 databases. It does not qualify an installation or an upgrade. The
operational intake and continuity rehearsal remains open in [#212](https://github.com/adamgreenwell/kitsune/issues/212).

## Disclosure

Coordinated disclosure. A fix is prepared privately, released, and the advisory published once operators have had a reasonable window to upgrade. Reporters are credited unless they prefer otherwise.

Kitsune will not ask a reporter to sign an NDA, and will not treat a good-faith report as an attack.

## A note on language

Report in whatever language you are comfortable writing. The maintainer reads English natively and gets by in German and Italian; everything else goes through machine translation.

For security reports specifically, English is safer **if you can manage it** — a mistranslated technical detail has consequences a misunderstood feature request does not. If you cannot, report anyway. It will be worked through carefully.
