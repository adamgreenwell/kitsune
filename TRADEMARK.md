# Kitsune trademark policy

The code is yours. The name is not.

Kitsune's code is MPL-2.0: you may use it, fork it, sell it and host it for customers without asking anyone. The name and the logo are a separate instrument (ADR-005), and they exist so that "Kitsune" keeps meaning one predictable thing — the project in this repository. This page says how you may use them.

⚠️ **Provisional, and not yet reviewed by a lawyer.** Nothing here is legal advice. The mark is **not registered** in any jurisdiction; it is used as an unregistered mark, which is why the project writes Kitsune™ and never Kitsune®. A filing in software and SaaS classes is planned and waits on name clearance ([#1](https://github.com/adamgreenwell/kitsune/issues/1), [#2](https://github.com/adamgreenwell/kitsune/issues/2)). This policy does not depend on that filing, and it will be revised, publicly, when one is made.

## What the marks are

- The word **Kitsune**, as the name of this software and of services built on it.
- The logos in [`brand/`](brand/README.md): the stacked and horizontal lockups, the compact fox and the two-tail glyph.

They are held by **Adam Greenwell**, the project's maintainer. The logo *files* also carry their own copyright notice in [`brand/LICENSE.md`](brand/LICENSE.md); this page covers the name and the marks those files depict.

## Fine without asking

**Talking about Kitsune.** Articles, talks, tutorials, reviews, comparisons, documentation, a course. Accurately, and without suggesting the project wrote or endorses it.

**Saying your work is built for Kitsune.** Name the thing yours, then say what it works with:

- ✅ "SEO Toolkit **for Kitsune**", "a theme **built for Kitsune**", "**compatible with Kitsune** 1.x"
- ✅ A package name that puts your own vendor first: `acme/kitsune-seo`

**Saying you host it.** Describe the service; don't name it Kitsune:

- ✅ "Acme — managed hosting **for Kitsune** sites"
- ✅ "We install, update and back up Kitsune for you"

This is open to every host on the same terms, including competitors of the maintainer's own hosted service. See *The maintainer's own service* below.

**Using a subdomain or path of a domain you already own.** `kitsune.example.edu` for a university's install, `example.com/kitsune` for a section of your site.

**Running a free community event.** "Kitsune Meetup Cleveland", a free workshop, a hack night — as long as entry is free and it is clear the project is not the organiser.

**Showing the logo to refer to Kitsune.** Unmodified, at legible size, with the clear space in [`brand/README.md`](brand/README.md): in a slide, next to a "built for Kitsune" line, in an article about the project.

## Ask first

- **Selling anything with the name or logo on it** — shirts, stickers, mugs, prints.
- **An event that charges for entry**, or one that would read as official.
- **Anything this page does not cover** and you are unsure about. Asking is cheap; renaming later is not.

To ask, [open an issue](https://github.com/adamgreenwell/kitsune/issues/new) describing the use. The answer is given in that issue, so the next person with the same question can find it.

## Not allowed

**Kitsune as the name, or part of the name, of your product, service, company or fork.**

- ❌ "Kitsune SEO", "Kitsune Cloud by Acme", "Acme Kitsune", "KitsunePress"

**Kitsune in a domain name you register.** The subdomain and path uses above are fine; a registered name is not.

- ❌ `kitsunehosting.com`, `kitsune-themes.io`, `getkitsune.dev`

**Suggesting your work is official, endorsed, certified or affiliated** when it is not — in its name, its copy or its design.

**Changing the logo or making it yours.** No recolouring, redrawing, animating or combining it with your own mark, and no using it as your product's logo or app icon. A Kitsune install does not ship the Kitsune logo as its default either; the operator's own logo goes in `site_group.settings.logo`.

## Forks

Fork freely — the MPL guarantees it and [`GOVERNANCE.md`](GOVERNANCE.md) calls that a feature. Pick your own name, and remove the Kitsune logos from what you ship. You may say what it came from: "**based on Kitsune**" or "**a fork of Kitsune**" is an accurate description and is fine.

## The maintainer's own service

The maintainer runs a commercial hosted service built on Kitsune (ADR-004), and owns the marks. That is a conflict of interest, and [`GOVERNANCE.md`](GOVERNANCE.md) commits to disclosing it rather than leaving it to be inferred.

So, plainly: the maintainer's service can use the name in ways this page does not allow anyone else, because the maintainer holds the mark. What this page does **not** do is reserve the descriptive uses for that service. "Hosting for Kitsune", "built for Kitsune" and "compatible with Kitsune" are open to every host and every developer on the terms above, and a change that narrows them will be made by amending this page and its ADR in public, not by a quiet request to one company.

---

*This page changes as the project does. Its history is in git. The decisions behind it are ADR-005, ADR-032 and ADR-044 in [`docs/decision-log.md`](docs/decision-log.md).*
