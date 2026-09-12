# Pattern parity harness

Measures where **PCRE** (what enforces an authored pattern) and **ECMA-262** (what a JSON
Schema `pattern` means to an API consumer) disagree, and whether Kitsune already refuses each
disagreement.

It exists because rule 3 of the field-type contract — *"`apiSchema()` may only publish a
constraint the consumer can enforce"* — is a claim about two languages, and the only honest way
to hold it is to run both.

```bash
php  tools/pattern-parity/measure.php  > /tmp/pcre.json
node tools/pattern-parity/measure.mjs  > /tmp/ecma.json
php  tools/pattern-parity/compare.php /tmp/pcre.json /tmp/ecma.json
```

## ⚠️ Two fidelity rules, both learned by getting them wrong

Earlier versions of this harness reported **seven** divergences where there are three. Both
errors made the code look worse than it is, and either would discredit a real finding.

**1. The server compiles `Pattern::delimit()`'s output, not the raw source.** `delimit()`
rewrites `.` to `[^\n\r\x{2028}\x{2029}]` and `\s` to the explicit ECMAScript whitespace class,
and sets the `uD` modifiers. Comparing raw-vs-raw invents divergences for `.` on CR and `\s` on
U+0085, U+180E and U+FEFF, all of which Kitsune already neutralises.

**2. `D` is not optional.** It is why `$` does not match before a trailing newline in PCRE, which
is how PCRE's `$` is made to mean what ECMAScript's already means. Dropping it invents three more.

**3. Both engines must be asked the *same question*.** The first version of this harness handed
PHP `\\1` and Node `\1` — different patterns — and every conclusion drawn from it was luck rather
than method. That is why the cases live in **one shared JSON file** that both readers parse: the bytes reach
PCRE and ECMAScript identically because neither reader transcribes them.

⚠️ This paragraph claimed the file *"is written ASCII-escaped so neither reader has to guess an
encoding"*, and it is not — the subjects are literal UTF-8, and have been since the file was
written. Both readers use a standard JSON parser, which is what actually removes the guess, so
nothing was ever wrong except the sentence. It is corrected rather than made true, because
escaping 162 cases to satisfy a README would make them unreadable for no gain.

## ⚠️ An incomplete result set is refused, not compared

`compare.php` exits **2** rather than comparing when either result file's case IDs are not exactly
`cases.json`'s, or when a result is missing `compiles` or `matches`.

That guard exists because the tool lied without it, which review found. An absent measurement was
read through `?? null` as `{compiles: null, matches: null}`, and **two absences compare equal** — so
a case that neither file had measured counted as agreement. Demonstrated by deleting one divergent
case from both files: `divergent AND refused` fell from 98 to 97, `refused, both reject` rose from 1
to 2, and nothing said anything. The unmeasured case did not merely vanish; it was reported in the
bucket whose label is *"refusing costs nothing"*.

One-sided absence is the mirror image: it manufactures a divergence out of nothing.

Both matter because this tool's numbers are quoted in `docs/field-types.md` and in review replies. A
stale pair of files could have reported `0 live defects` for a corpus it had never run.

## ⚠️ It measures ONE version pair

The harness runs the PCRE and Node it has. **It cannot see divergence between versions it is not
running** — and property membership is version-dependent, so a pattern portable on one pair can
diverge on another.

Demonstrated by review: `\p{Cn}` on U+10940 agrees on PCRE 10.48 with Node 22 and **diverges** on
PCRE 10.44 with Node 24, because "unassigned" shrinks with every Unicode release. `Cn` is therefore
excluded from the portable categories **on principle rather than on measurement**, and the harness
duly reports it as an expressiveness cost. That is the instrument being honest about its reach.

Record the two versions with any result you quote:

```bash
php -r 'echo PCRE_VERSION, PHP_EOL;'
node --version
```


⚠️ **And a divergence can be a BUG rather than a property, which changes the remedy.** Review measured
`(?=a)a?a` diverging on PCRE 10.44 with Node 24; on PCRE 10.48 with Node 22 both engines match, as do
six neighbouring shapes. Both dialects define that pattern identically, so the disagreement is an
upstream defect fixed between those PCRE releases — and refusing a shape the two languages agree on
would be a permanent expressiveness cost for a transient bug.

`composer.json` requires PHP `^8.4`, whose earliest releases bundle PCRE2 10.44, so the exposure is
real rather than hypothetical. The case is in `cases.json` as `unanchored-lookahead-optional-prefix`,
which means this harness reports it as a **live defect** on any pair where it still diverges — which
is the honest place for a version-dependent finding, rather than a rule in the grammar that outlives
the bug.
## Reading the output

- **divergent AND accepted** — a live defect. The engines disagree and nothing refuses it.
- **divergent AND refused** — handled. The disagreement exists and is caught.
- **agrees BUT refused** — the expressiveness cost: a pattern both engines would have honoured.

A case whose `matches` is `null` while `compiles` is true means the engine **gave no verdict**
rather than answering. PCRE reports it when it exhausts its backtrack limit; the ECMAScript side
reports it with `timedOut: true` when it cannot answer inside the deadline. Treating either as
"no match" hides catastrophic backtracking instead of reporting it.

⚠️ **Every ECMAScript case runs in a worker with a deadline** (`PARITY_DEADLINE_MS`, default 2000).
That is not defensive style: `RegExp.prototype.test` is synchronous, so one catastrophically
backtracking case pinned the event loop and the tool emitted no JSON at all. The first version
committed here had exactly that defect.

## Adding a case

Append to `cases.json` with an `id`, a `pattern` (no delimiters, no flags), a `subject` that
**discriminates**, and a `why`. A subject both engines match tells you nothing and manufactures
false confidence — pick input where they would differ if they differ at all.

Cases whose subject needs a lone surrogate cannot round-trip a shared UTF-8 file and are
deliberately absent; that limitation is a property of the transport, not of the engines.
