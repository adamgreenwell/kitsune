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
than method. That is why the cases live in **one shared JSON file** that both readers parse, and
why the file is written ASCII-escaped so neither reader has to guess an encoding.

## Reading the output

- **divergent AND accepted** — a live defect. The engines disagree and nothing refuses it.
- **divergent AND refused** — handled. The disagreement exists and is caught.
- **agrees BUT refused** — the expressiveness cost: a pattern both engines would have honoured.

A case whose `matches` is `null` while `compiles` is true means PCRE **errored** rather than
answered — a backtrack limit, most likely. That is a third outcome, and treating it as "no match"
hides catastrophic backtracking.

## Adding a case

Append to `cases.json` with an `id`, a `pattern` (no delimiters, no flags), a `subject` that
**discriminates**, and a `why`. A subject both engines match tells you nothing and manufactures
false confidence — pick input where they would differ if they differ at all.

Cases whose subject needs a lone surrogate cannot round-trip a shared UTF-8 file and are
deliberately absent; that limitation is a property of the transport, not of the engines.
