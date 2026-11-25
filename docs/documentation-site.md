# Documentation delivery

## Needs before tools

The readers are operators and first-party contributors. They need to find the instructions that match the code
they are running, follow a task into its decision record, and return without losing their place. This is separate
from the marketing site's first-install obligation (ADR-030).

The source inspection on 2026-10-26, at `4e6f9059d841533b7ff45e18685da2c7022392ab`, found 27 root/docs Markdown
documents totalling 1,834,692 bytes. The decision log alone was 1,293,161 bytes and contained 42 ADRs. A real parse
with the locked CommonMark 2.10.1 on PHP 8.5.10 found 463 headings and 331 links, with no missing local targets or
fragments. These are corpus measurements, not user research or rendering-performance claims.

| Representative task | Existing evidence | Delivery requirement |
| --- | --- | --- |
| Install without a default account | README's development setup; ADR-026/027 and ADR-039 | An operator-first guide; distinguish the designed installer from the development checkout and its demo seed |
| Upgrade without losing access | Blueprint receipts in README/ADR-039; credential rotation guide | Keep version and source visible; link backup and key custody before changes; no invented support duration |
| Choose a field and synchronise its schema | `field-types.md`, schema-sync command, ADR-006 | Search identifiers and prose; retain punctuation in code; reach a specific heading |
| Understand Org, Site and shared rows | Architecture, ADR-021 and ADR-042 | Deep links must stay within the chosen snapshot, including duplicate headings in the long log |
| Install a first-party module | ADR-038 and the module command | Name the pre-freeze limits beside the task; an internal seam is not a stable plugin SDK |

Required: immutable, named source snapshots; navigation between built snapshots; browser-local search of the
selected snapshot; ordinary links and readable pages without JavaScript; keyboard and narrow-screen reading;
source-byte provenance; broken-link checks. A snapshot label is not a release or a support promise. Public
publication, DNS and host qualification are separate actions.

## Approaches compared

| Approach | What the repository demonstrates | Tradeoff |
| --- | --- | --- |
| Existing GitHub Markdown and wiki | All five tasks have source material; `bin/wiki-sync.php` generates only the ADR index and roadmap status, and its links name main | Retains the existing contribution path, but does not deliver a browsable multi-snapshot artifact or a snapshot-local search index |
| Static artifact using the locked PHP Markdown parser | The actual corpus parses with the dependency already in the development lock; all 331 source links resolve locally; no new package is needed | Add a small renderer, link mapper, index and reading UI, with tests for their real failure boundaries. The project owns that small build tool |
| A running Kitsune application | ADR-030 explicitly leaves docs undecided; public theming and search are later roadmap work | Would couple reading recovery instructions to an application and database, and require capabilities the docs task does not otherwise need. No running-CMS prototype or performance result is claimed |

Choose the static artifact. It resolves the measured content/navigation problem without adding a package,
service, Node build, runtime requirement or new extension surface. Keep `docs/` authoritative. Split the long
decision log for reading while preserving its original document-wide heading identities and cross-links.
The full source text remains downloadable alongside each snapshot.

## Build and verify locally

The development-only builder is `php bin/docs-build.php`. It uses the existing Composer development install,
never boots Kitsune, and writes under the ignored `build/docs` directory. Serve the artifact with an ordinary
static HTTP server. Search needs HTTP to fetch its local JSON; navigation and content do not need JavaScript.

```sh
php bin/docs-build.php --version=development
php bin/docs-build.php --version=reference-main --ref=<full-commit>
php -S 127.0.0.1:8346 -t build/docs
```

Use an actual full commit for the reference build, not the angle-bracket placeholder. Open the local server's
index and choose a built snapshot. `--output=<new-directory>` selects a separate owned artifact root for a
reproduction check. An existing snapshot is refused rather than overwritten; an unrelated existing directory
is refused. No build command starts a public listener or uploads the artifact.

The builder accepts an explicit version label and an optional Git ref. A ref is resolved to a commit before any
source read. Working-copy builds identify their actual document bytes and clearly say when the checkout is
dirty. Each snapshot has its own content and assets; building another snapshot cannot change the older one.
Only snapshots actually built appear in the version navigation. A source commit and a content digest are
provenance, not a security-support table.

Local build, link, search and browser evidence belongs with the pull request. No public URL or successful public
readback is claimed by a local build. [#210](https://github.com/adamgreenwell/kitsune/issues/210) closed after
the implementation merged through #339 and all five scoped local acceptance requirements were independently
verified. Public publication and its readback still need separate authorization. The
[versioning and upgrade policy](versioning-and-upgrades.md) records the selected maintenance/admission models
and the unresolved release/support terms; a documentation snapshot does not qualify an upgrade.
