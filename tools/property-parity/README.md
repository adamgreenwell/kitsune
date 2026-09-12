# Property parity harness

Measures whether **PCRE** and **ECMA-262** agree about what a Unicode property *means*, for every
property name the grammar may publish.

It exists because the allowlist was built on the weaker of two tests. `Pattern::PORTABLE_PROPERTIES`
admitted a name when both engines **compile** it — and `\p{Bidi_Mirrored}` compiles in both and means
two different things:

| name | PCRE 10.48 | ECMAScript (Node 22.23.2) | delta |
|---|---|---|---|
| `Bidi_Mirrored` | 428 codepoints | 554 codepoints | **+126**, one-directional |

The 126 include U+2202 `∂` and U+2140 `⅀`. Rule 3 of the field-type contract — *"`apiSchema()` may only
publish a constraint the consumer can enforce"* — makes that unpublishable on its own. It was also a
**cost** defect, which is how it was found: every boundary proof in `Pattern` asks PCRE whether an atom
can match a character, so PCRE's narrower set proved boundaries the consumer does not have.
`^\p{Bidi_Mirrored}*\p{Bidi_Mirrored}*∂\p{Bidi_Mirrored}*X$` published and measures 45.9 ms at 250
characters, 363.4 at 500 and 2,924.5 at 1,000 — three adjacent variable-width atoms, because `∂`
divides them only in PCRE.

```bash
php  tools/property-parity/names.php                 > /tmp/names.json
php  tools/property-parity/measure.php  /tmp/names.json > /tmp/pcre.json
node tools/property-parity/measure.mjs  /tmp/names.json > /tmp/ecma.json
php  tools/property-parity/compare.php  /tmp/pcre.json /tmp/ecma.json
```

Exit 1 means a **publishable** name diverges — a defect in this repository. Exit 2 means the two dumps
cover different names, which is a broken run rather than a finding.

## What the last sweep found

267 names — the 53 on the allowlist plus all 213 script names ICU knows, because `Script=` is
allowlisted by prefix and so every script name is publishable. Each name is tested against
**1,112,064 codepoints**: every one except the surrogates U+D800–U+DFFF, which are not valid UTF-8 and
make each engine report its own idea of an invalid subject rather than a membership answer.

| | |
|---|---|
| agree exactly | **228** |
| compile in neither engine | 38 — script aliases and legacy codes (`Katakana_Or_Hiragana`, `Hans`, `Cyrs`, …), which `compiles()` refuses anyway |
| compile in one engine only | 0 |
| **membership diverges** | **1** — `Bidi_Mirrored`, now off the allowlist |

## Three rules, all learned the same way as `pattern-parity`'s

**1. The allowlist is read from the code, not transcribed.** `names.php` reflects
`Pattern::PORTABLE_PROPERTIES`. A harness that keeps its own copy of the list tests the copy.

**2. The same modifiers the server compiles.** `Pattern::delimit()` sets `uD`, so the probe does too.

**3. Compiling is not agreeing**, which is the whole point of the harness and was the gap in the rule
it now defends. The allowlist's own docblock claimed a full-codepoint comparison — for three *aliases*.
Every other name was admitted on compiling alone. Doing the stronger test for three names and the
weaker one for fifty is how a claim outruns its evidence.

## When to re-run it

When either engine's Unicode version moves: a new PHP or Node minor can change a property's membership
without changing its name, and nothing else in this repository would notice. `\p{Cn}` is the standing
reminder — `field-types.md` §4 records it diverging on a PCRE version this machine cannot run, which is
the same class of skew one version apart.

⚠️ **Needs the `intl` extension**, only to enumerate script names from ICU. It is not a runtime
dependency of the package; this is a maintenance harness, like the Node half of `pattern-parity`.
