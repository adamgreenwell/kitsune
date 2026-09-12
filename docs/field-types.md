# Kitsune — Field Type Registry

**Companion to:** [`architecture.md`](architecture.md) §3. This doc specifies the field type contract and the types shipping in v1.0.
**Created:** 2026-09-07
**Status:** Design. Filament component mappings need verification against v5.4 during Phase 4.

---

## Why this document exists

The schema engine is the hardest thing in the project (roadmap Phase 4). Most of its difficulty is not the engine — it's that **every field type has to be right in four places at once**, and if the contract between those places is vague, each new type becomes a small research project.

Get this contract right and adding a field type is filling in a form. Get it wrong and the schema engine becomes twelve special cases wearing a trenchcoat.

---

## 1. The four faces

Every field type must answer four questions, and they are not independent:

| Face | Question | State |
|---|---|---|
| **Storage** | How does a value live in the database, and can it be indexed? | Shipped |
| **Form** | What kind of control edits it? | `control(): Control` — the vocabulary; the renderer is #39 |
| **Table** | What kind of cell lists it? | Derived: `Control::cell()` |
| **API** | How does it serialize out, deserialize in, and describe itself in a schema? | Shipped |

⚠️ **The Form and Table columns used to read "What Filament form component edits it?"** They do not, and the difference is the subject of ADR-029: a field type names a **kind** of control from a closed vocabulary, and `Kitsune\Core\Filament` decides which Filament class that is. Asking the type for the component puts a cross-cutting presentation concern — `dir="auto"` above all — into twelve independent answers, where a thirteenth type can omit it and nothing notices.

⚠️ **And this section used to claim "a type that answers three of four is not shippable"** while all twelve shipped types answered exactly three. The statement was aspirational and read as a rule, which is worse than either: it made a real gap look like an invariant already held. The two UI faces are genuinely missing and tracked as #39; the sentence they contradicted is gone.

The failure it warned about is still real, though, and worth keeping in the accurate form: **a type that edits beautifully and cannot be queried is the common one**, because storage is the face with no visible symptom when it is wrong.

---

## 2. Storage strategies

This is the real architectural content of this document. **Not every field lives in the `values` JSON column**, and pretending otherwise is how you end up unable to answer "what references this image?"

### `Promoted` — a real column on `entries`

Reserved for fields the platform itself needs to query on every request, regardless of entity type.

| Field | Column | Why promoted |
|---|---|---|
| `title` | `entries.title` | List views, global search, breadcrumbs |
| `slug` | `entries.slug` | URL resolution, uniqueness constraint |
| `status` | `entries.status` | Filtered on literally every query |
| `published_at` | `entries.published_at` | Scheduling, ordering, feeds |

Promoted fields are **not user-definable**. An entity type turns them on or off in `entry_types.settings` (`sluggable`, `publishable`), but nobody adds a fifth promoted field through the admin. That list is a platform decision and changing it is a migration.

### `Inline` — inside the `values` JSON column

The default. Scalars and small structures. Indexable via stored generated columns when `field_storage.is_indexed` is true.

```json
{ "price": 24.99, "in_stock": true, "summary": "…" }
```

Cardinality > 1 becomes a JSON array: `{"tags": ["a", "b"]}`.

### `Relational` — rows in `entry_relations`

Anything pointing at another entry. **Never** stored as an ID array in JSON.

The reason is in [`architecture.md`](architecture.md) §3, and it's worth repeating because it's the mistake every JSON-first CMS makes: a JSON array of IDs cannot answer *"what points at me?"* without a full table scan, and cascade-on-delete becomes application code that will eventually be wrong. Deleting an image should be able to tell you the eleven articles that use it. That requires a real table with a real index.

---

## 3. The contract

⚠️ **Reproduced from `packages/core/src/Fields/FieldType.php`, and pinned to it by a test.**
`tests/Core/Fields/ContractDocumentationTest.php` reflects over the interface and fails
when this block and the code disagree. It exists because they did: this section declared
`formComponent()`, `tableColumn()` and `generatedColumnType()` — **none of which the
interface has ever had** — while omitting six methods it does. A contract described in
prose drifts from the code it governs, and a reader trusting the prose implements against
an interface that does not exist.

```php
interface FieldType
{
    // ── Identity ───────────────────────────────────────────────────────
    public static function handle(): string;          // 'text', 'number', 'relation'
    public static function label(): string;
    public static function icon(): string;

    // ── Storage ────────────────────────────────────────────────────────
    public function strategy(): StorageStrategy;      // Promoted | Inline | Relational
    public function isIndexable(): bool;
    public function supportsCardinality(): bool;

    /** What this field projects to for indexing, or null if it is not indexable. */
    public function projection(FieldConfig $config): ?Projection;

    /** The `entries` column this type owns, or null for everything else. */
    public function promotedColumn(): ?string;

    public function toStorage(mixed $input, FieldConfig $config): mixed;
    public function fromStorage(mixed $stored, FieldConfig $config): mixed;

    // ── UI ─────────────────────────────────────────────────────────────

    /** What KIND of control edits it. Not a component — see below. */
    public function control(): Control;

    // ── API ────────────────────────────────────────────────────────────
    public function toApi(mixed $stored, FieldConfig $config): mixed;
    public function fromApi(mixed $input, FieldConfig $config): mixed;
    public function apiSchema(FieldConfig $config): array;   // JSON Schema fragment

    // ── Validation & configuration ─────────────────────────────────────
    public function validationRules(FieldConfig $config): array;

    /** Rules for ONE element of a multi-value field, not for the array. */
    public function elementValidationRules(FieldConfig $config): array;

    /** How many elements a multi-value field admits — published, enforced and rendered from here. */
    public function maxItems(FieldConfig $config): ?int;

    public function settingsSchema(): array;          // the "configure this field" form
    public function validateSettings(array $settings): ?string;

    /** Whether a revision must keep the pre-sanitization original. */
    public function retainsOriginal(): bool;

    public function suggestedPiiClass(): string;      // ADR-020 fails closed
}
```

**The UI face is one method returning a `Control`, and that is the whole of it.** A field
type describes a *kind* of control and the panel builds the component — ADR-029, which
generalises ADR-028's *"handing it one was the wrong seam"* from storage drivers to
Filament components.

⚠️ **A field type cannot express an opinion about text direction, and that is the point.**
`Control::direction()` derives it and `Kitsune\Core\Filament` applies it, so there is no
parameter to omit and no default to inherit. Issue #39 shipped `dir="auto"` three times and
was short of complete twice — the second time missing two of three table columns — because
the reach of a correct rule depended on somebody enumerating call sites. Twelve types each
returning a finished `TextInput` is twelve chances to forget; a closed vocabulary is none.

| Control | Direction | Cell | Why |
|---|---|---|---|
| `Line`, `Paragraph` | `Auto` | `Text` | Text a person typed |
| `Choice`, `Choices` | `Auto` | `Badge` | Option **labels** are authored — an org may label statuses in Arabic |
| `EntryPicker` | `Auto` | `Text` | Entry **titles**, which is what the related-records table missed |
| `KeyValue` | `Auto` | `None` | An escape hatch holds anything, keys included |
| `RichText` | `PerBlock` | `None` | One `dir` would impose the first block's direction on the rest |
| `Number`, `Toggle`, `Date`, `DateTime` | `Neutral` | `Numeric` / `Boolean` / `Timestamp` | Glyphs the app chose, not the author |

⚠️ **Adding a case to `Control` is a compile-time obligation.** `direction()` and `cell()` are
`match` with no `default`, so PHPStan reports *"Match expression does not handle remaining
value"* on both until the new kind's direction and cell are decided — verified by adding a
thirteenth case, which produced exactly two errors. The runtime backstop is an
`UnhandledMatchError`. This is the same guarantee `LogicalType` gives the three storage
drivers, and it was added there because `integer` and `boolean` were silently unindexable on
MySQL.

⚠️ `control()` takes **no `FieldConfig`**, unlike `projection()`. A projection genuinely
changes with configuration — a `number` with `format: integer` must project to BIGINT — and a
control kind does not. What varies with configuration (precision, options, target types,
cardinality) the renderer reads from the config it already holds; a parameter nothing uses
invites a type to branch on the wrong thing.

### Three rules that are not negotiable

**1. `validationRules()` never returns Laravel's `unique` or `exists`.** Those rules don't go through Eloquent, so they ignore global scopes and leak across sites and orgs. Return `scopedUnique()` / `scopedExists()`. This is enforced by the plugin validation CLI (roadmap v1.2), and it fails the build.

**2. `projection()` describes what a value projects to; it never writes SQL and never sees a driver.** There are **three** drivers and all three differ: Postgres and MySQL diverge on generated-column syntax *and* JSON path operators, and SQLite cannot add a STORED generated column via `ALTER TABLE` at all — it needs a VIRTUAL one, indexed as an expression index ([`architecture.md`](architecture.md) §1).

> ⚠️ This rule previously read *"`generatedColumnType()` takes the driver and returns driver-specific SQL"*. ADR-028's first amendment replaced that method with `projection(FieldConfig)` and gave the reason in one line — **"handing it one was the wrong seam"** — because a type handed a driver still had to know that a rendered type serves two grammars, and it left the driver nowhere to put the guard. The projection also depends on **configuration**, not just the type: a `number` with `format: integer` must project to BIGINT, and a `text` with `maxLength: 400` must project at that width or be silently truncated in the index.

**3. `apiSchema()` may only publish a constraint the consumer can enforce.** A constraint the consumer cannot read is not published, and one it reads *differently* is worse — it advertises a rule the API does not apply, and nothing reports the disagreement.

This bites hardest on `text`'s `pattern`, because the same string is enforced by PCRE server-side and published as a JSON Schema `pattern`, whose dialect is ECMAScript. The two are not the same language, so `validateSettings()` refuses a pattern that cannot travel. Measured on PHP 8.4/PCRE 10.48 and Node 22, PCRE compiles and ECMAScript rejects or reinterprets all of these:

| Written | Problem | Portable form |
|---|---|---|
| `\d` `\w` `\h` `\v` | PHP's `u` modifier sets `PCRE2_UCP`, so `\d` is any Unicode digit; ECMAScript's is `[0-9]`. `\h` is horizontal whitespace here and the letter `h` there | `[0-9]`, `[A-Za-z0-9_]`, `[ \t]` |
| `(?i)` `(?>` `(?P<n>` `(?#` | Group forms ECMAScript has no parse for | `(?:` `(?=` `(?!` `(?<=` `(?<!` `(?<n>` |

A named capture may use a name in **any script** — `(?<é>`, `(?<日本>`, `(?<ключ>` all compile and match identically in both dialects. Nothing narrower is enforced because nothing needs to be: across 23 candidate names, none is PCRE-accepted-and-ECMAScript-rejected, so `compiles()` already answers for the rest.

| `a++` `x{2,3}+` | Possessive quantifiers | greedy or lazy |
| `(*SKIP)` `(*FAIL)` | Backtracking control verbs | — |
| `\pL` | The braceless property form | `\p{L}` |
| `\p{Arabic}` `\p{Latn}` | A bare script name or code | `\p{Script=Arabic}` |
| `\p{Xan}` `\p{L&}` | PCRE's own property inventions | `\p{Alphabetic}` etc. |
| `\p{bc=AL}` | A property class ECMAScript lacks | — |
| `\p{^L}` | PCRE's internal negation | `\P{L}` |
| `\p{lu}` `\p{Script=latin}` | PCRE matches names loosely — ignoring case, spaces and dashes — where ECMAScript is exact | `\p{Lu}`, `\p{Script=Latin}` |
| `\b` `\B` | Defined in terms of `\w`, so the engines give **opposite** answers on non-ASCII text | the ASCII definition as lookarounds (below) |
| `\a` `\e` | Control characters PCRE spells with a letter | `\x07`, `\x1B` |
| `\g{1}` `\g<1>` `\g1` | PCRE subroutine and relative-backreference forms | `\1`, or `\k<name>` |
| `\o{141}` `\00` | Octal escapes; `\00` is a syntax error under ECMAScript's `u` flag | `\x61`, `\x41` |
| `\101`, `\12` with two groups | A multi-digit escape naming a group that does not exist: PCRE falls back to octal, ECMAScript rejects it. One that **does** name a real group — `\10` with ten groups — is portable and accepted | `\x41`, or add the groups |
| `\1(a)`, `(a)?\1`, `(a)*\1` | A backreference whose group need not have participated. PCRE **fails the match**; ECMAScript treats the reference as an **empty string** — so the two enforce different rules on the same input | define the group first, and make it required |
| `(a\1)`, `(a\1)+`, `(?<n>a\k<n>)` | A backreference **inside the group it names**. The group opens before the reference but has not *closed*, so it has not participated — same divergence, reached by a different route. The quantified form diverges too: PCRE does not reset captures between iterations but still fails, and ECMAScript does reset them | move the reference after the group closes |
| `(a){000000000000000}\1` | A zero lower bound **padded to any width**. The group repeats zero times, so the reference is unset — the padding is only what made it hard to see | `\1` with a required group |

⚠️ Those rows are the ones to read twice, because both engines *compile* every one of them. A backreference is portable exactly when its group **must** participate — existence is not enough, and neither is opening earlier in the pattern.

---

> ⚠️ **Amended — rule 3 is enforced by a published grammar, not by screening divergences.** Decided under [#44](https://github.com/adamgreenwell/kitsune/issues/44). The table above stays as the **evidence**, because it is what the decision rests on; it is no longer the mechanism.
>
> **Why the shape changed.** Every row above was found by a reviewer noticing the next case — nine consecutive rounds of it. The two parts of the screen that **stopped** producing findings are the two that switched from listing offenders to listing what is allowed: the group prefixes and the Unicode property names. Neither has produced a finding since. Everything still enumerating divergences kept producing them.
>
> A denylist **fails open**: a construct nobody anticipated is accepted and published wrong, silently. An allowlist fails closed — an unknown construct is refused because it was never admitted, not because someone remembered it. Rule 3 says `apiSchema()` may only publish a constraint the consumer can enforce; a grammar makes that enforceable *by construction* rather than by enumeration.
>
> **Fresh evidence, measured 2026-09-11.** 200 candidate constructs, enumerated from six independent angles, run through one shared case file so PCRE and ECMAScript are asked the same question. At production fidelity — PCRE compiling `Pattern::delimit()`'s output, ECMAScript compiling the published source — **two constructs the screen accepted diverged, and a third made neither engine answer at all**. Both divergences are now refused by the structural rules below, so the current count is zero:
>
> | Pattern | PCRE | ECMAScript |
> |---|---|---|
> | `^(?=a)+a$` | compiles, matches | **refuses to compile** under `u` — *"Invalid quantifier"* |
> | `(?<=(a\|aa))b\1$` | no match | **match** — PCRE orders lookbehind branches by length, ECMAScript by written order |
> | `^([a-zA-Z0-9]+\.?)+@x\.com$` | **no verdict** — backtrack limit exhausted, ~2ms | **no verdict** — still searching at the harness deadline |
>
> ⚠️ Two apparent findings were **my instrument, not the code**, and are recorded because they change how this must be measured: comparing with `/u` instead of `/uD` invented three `$` divergences, and comparing *raw source* in both engines invented four more — `delimit()` already rewrites `.` and `\s` to explicit ECMAScript-equivalent classes. Raw-vs-raw reported 7 divergences; true fidelity reports 3.
>
> ⚠️ **"Three" is scoped to ONE VERSION PAIR, and that is a limit of the method rather than a result.** The table above was measured on PCRE 10.48 with Node 22. Review demonstrated a **fourth** divergence on PCRE 10.44 with Node 24 — `\p{Cn}` on U+10940, unassigned to one engine's tables and assigned to the other's — which the newer pair agrees on. A harness runs the versions it has; it cannot see skew between versions it is not running.
>
> **So `Cn` and `C` are excluded from the portable categories on principle, not on measurement** — and the principle is narrower than "their membership moves between versions", because *every* category's membership moves. U+10940 is SIDETIC LETTER N01, **assigned in Unicode 17.0** with category `Lo`: a server at 15.1 and a client at 17.0 enforce different rules on `^\p{L}+$` for that one codepoint. No allowlist can fix that, and one that tried to would end up empty.
>
> **The line is whether there is a stable rule to converge on.** *"A letter"* is one — both engines are answering the same question, one of them has a shorter table, and each Unicode release brings them closer to what the author meant. *"Not yet assigned"* is not a rule at all; it is a description of the table's incompleteness, so the answer moves **away** from the author's intent with every release, in both polarities at once: `\p{Cn}` matches steadily less and `\P{Cn}` steadily more, and neither converges anywhere. `C` is excluded because it **contains** `Cn` (`Cc|Cf|Co|Cs|Cn`) and inherits that exactly — it was on the allowlist while `Cn` was off it, which is the same unportable set with one extra spelling. `Cc`, `Cf`, `Co` and `Cs` stay: each names *assigned* characters.
>
> One removal covers four spellings each — `\p{C}`, `\P{C}`, `[\p{C}]`, `[^\p{C}]` — because the refusal reads the property **name** and does not consult class context.
>
> ⚠️ **A property complement is admitted, deliberately.** `\P{L}` and `[^\p{L}]` do include unassigned characters, so a newly assigned letter leaves the complement — but `\p{L}` diverges on the *same* codepoint in the opposite direction, and on the same engine pair. Refusing one polarity while admitting the other would remove half of a symmetric pair and claim a portability the remaining half does not have either. So both are admitted and the limit is disclosed once, here: **a property claim is portable only to the extent the two engines share a Unicode version, and nothing in a pattern can assert that.** What makes `Cn` and `C` different is not their polarity — it is that neither polarity of them names a stable rule.
>
> ⚠️ A consequence worth expecting: the harness reports `\p{Cn}` and `\p{C}` as an *expressiveness cost*, because on the pair it runs the two engines agree — they share Unicode 17.0, and agree on all 1,114,112 codepoints for `L`, `N`, `Nd`, `C`, `Cn`, `Cf`, `P`, `S`, `Z` and `M`. That is the instrument being honest about what it can see, not a reason to re-admit the categories.
>
> ### The grammar
>
> A pattern is accepted when **every construct in it appears below**. Anything else is refused with the reason, whether or not anyone anticipated it.
>
> | Permitted | Notes |
> |---|---|
> | literal characters | a metacharacter must be escaped, from the portable punctuation set |
> | `[...]`, `[^...]`, ranges `a-z` | with permitted escapes inside |
> | `.` and `\s` `\S` | permitted **because `delimit()` normalises them** server-side to explicit ECMAScript-equivalent classes. They are the only constructs admitted by rewriting rather than by agreeing |
> | `\p{...}` `\P{...}` | names from the published category, property and prefix allowlists — **excluding `Cn` and `C`**, which describe the absence of an assignment rather than a stable rule. Both polarities and both class forms are refused. A complement of any *other* property is permitted, symmetrically with the property |
> | `^` `$` | `$` is portable only because `D` is set; it cannot be expressed in the published pattern and must never be dropped |
> | `*` `+` `?` `{n}` `{n,}` `{n,m}` and lazy forms | upper bound **at most 65535** — measured: PCRE refuses to compile above it, ECMAScript allows far more |
> | `(?:...)` `(...)` `(?=...)` `(?!...)` `(?<=...)` `(?<!...)` `(?<name>...)` | the existing group allowlist, unchanged |
> | `\|` | alternation |
> | `\1` `\k<name>` | only where the group **must** participate — see the rows above |
> | `\t` `\n` `\r` `\f` `\xHH` | `\v` is excluded: vertical whitespace here, the letter `v` there |
>
> ### Seven rules the construct list cannot express
>
> ⚠️ **An allowlist of constructs is necessary and not sufficient**, and the third row of the divergence table above is why.
>
> ⚠️ **This section published three of these rules while the code enforced none of them**, and that is recorded rather than quietly corrected. Measured: `^(?=a)+a$`, `(?<=(a|aa))b\1$` and the example in rule 3 below were all *accepted* by `Pattern::unpublishable()`. The first two are exactly the two live defects the parity harness was reporting — the gap was visible the whole time, in the instrument built to find it. A published constraint the code does not keep is the failure invariant 14 exists for, and it is worse than an unwritten rule, because a reader has no reason to doubt it. All of them are enforced by `structuralRefusal()` and asserted by `StructuralGrammarTest`; the harness reports **zero** live defects. Two of the three were later found to leak and are now a single rule about a single property — see rule 3.
>
> 1. **No quantifier on an assertion.** `(?=a)+` is built from two permitted constructs and does not compile under ECMAScript `u`. Checked against **`u`-mode specifically**, because Annex B makes the unflagged dialect more permissive than the flagged one.
> 2. **A lookbehind's alternatives must be equal length.** PCRE orders them by length, ECMAScript by written order, so a differing-length alternation changes which group captured what.
> 3. **An unbounded repetition must have only one way to divide its subject.** That is the property; the rest is how it is established. `([a-zA-Z0-9]+\.?)+` is entirely permitted constructs and makes **neither** engine answer on adversarial input: `preg_match()` returns `false` after exhausting its backtrack limit, and ECMAScript is still searching when the harness deadline expires. This is **not a portability problem** — the two agree, in the sense that neither gives a verdict — it is catastrophic backtracking, and ADR-027's 1 vCPU floor is why it cannot be left to the consumer.
>
>     ⚠️ **This was two rules and they both leaked.** The pair published here was *"no unbounded quantifier over a group containing one"* plus *"not over ambiguous alternation"*, and review found `^(a{1,2})+$` slipping between them: the inner quantifier is **bounded**, so the first never fires, and there is no alternation, so the second does not either. Measured, 30 characters takes ECMAScript ~100 ms and 40 runs past three seconds while PCRE exhausts its backtrack limit. Worse, a test in this repository asserted `^([a-z]{1,8})+$` was *acceptable* — the same shape, measuring the same way. Two rules aimed at symptoms let a third symptom through and blessed a fourth.
>
>     Three ways to establish the property, and a body needs any one of them:
>
>     | Established by | Qualifies | Does not |
>     |---|---|---|
>     | **Fixed width, every alternation inside it unambiguous** | `(?:ab)+`, `(?:cat\|dog)+`, `(?:a(?:b\|c))+` | `(a{1,2})+`, `([a-z]{1,8})+`, `(a?)+` |
>     | **Prefix-free literal alternatives** | `(?:ab\|c)+` at differing lengths | `(a\|aa)+`, `(?:cat\|ca)+`, `(?:a\|)+` |
>     | **A delimited repetition** | `^[^,]+(?:,[^,]+)*$` | `(,+)*`, `(?:,[^;]+)*`, `(?:,(?:,?))*` |
>
>     ⚠️ **A forced division is not sufficient on its own**, and measuring is what established that. `(?:[a-z]|x)+` is fixed at one character, so every iteration consumes exactly one and there is only one way to divide the subject — and it is still catastrophic, because `x` lies inside `[a-z]`: on 30 `x` characters both branches match at every position, giving 2³⁰ branch choices. **ECMAScript 7.9 s, PCRE's backtrack limit exhausted** — while the same pattern on 30 `a` characters is instant, because only one branch can match there. One pattern, two subjects, and only one of them affordable, which is why the rule is about the construct rather than about any subject.
>
>     ⚠️ **The delimiter exemption is a proof, not a plausibility.** Stated without it, the rule refuses `^[^,]+(?:,[^,]+)*$` — the ordinary comma-separated list, and safe. If the repeated body starts with a **required literal** and no **variable-width** atom inside it can match that character, every iteration must begin at an occurrence of it and none can choose to consume one, so the subject's own delimiters force the division: one way to split, nothing to backtrack over. It measures out linear — 5,000 items in 0.04 ms under PCRE and 0.09 ms under ECMAScript, on input that fails at the very end. Class membership is asked of PCRE rather than parsed — of the *normalised* atom, for the reason two notes down — and anything uncertain fails closed.
>
>     ⚠️ **"Variable-width", not "unbounded", and the difference was a live defect.** The proof first asked only about *unbounded* atoms, which exempted `^(?:,,?)*X$` — the optional comma is bounded, and it can still either end the current iteration or start the next. Measured: 40 commas plus a `Y` takes ECMAScript about 1.3 seconds and grows exponentially. A **required** atom that matches the delimiter is still fine, because the ambiguity comes from the *choice* about consuming one rather than from consuming it: `,{2}[^,]+` splits one way. It is nevertheless refused, because the proof also requires the leading literal to be unquantified — conservative, and stated rather than hidden.
>
>     ⚠️ **A required group is transparent, and a bracket pair was enough to hide the same defect.** The scan jumped from a group's `(` to its `)` and asked only about the group's own quantifier, so `^(?:,(?:,?))*X$` — the line above with brackets around the optional comma — was exempted. **Node 22 spends 10.5 s** on the same 40-delimiter subject. The proof reads through any group that carries no unbounded quantifier, at any depth; a group that *is* unbounded-quantified still fails closed unread, because repeating a body without limit can consume the delimiter however the body is written.
>
>     ⚠️ **Forcing the split between iterations is not enough, and the body has to divide one way too.** Review found it by putting the ambiguity entirely *between* the non-delimiter atoms. `^(?:,a*a*)*X$` satisfies the proof exactly — neither `a*` can match a comma, so every iteration must begin at one and none can consume one — and each `,aa` segment still divides three ways between the two stars. **Node 22: 9 ms at 12 segments, 723 ms at 16, 58.8 SECONDS at 20.** The boundaries were forced; what happened inside them was never asked.
>
>     The added condition is the same proof one level in: **every variable-width atom must be separated from the next by a required literal the atom on its LEFT cannot match.** That is the precise condition rather than a convenient one — for `A+ s B+`, if `A` cannot match `s` then `A+` must stop at the *first* `s` and the division is forced whatever `B` can match; if `A` can, it may swallow one and leave a later one. So `^(?:,[^,]+-[^,]+)*X$` is refused (`[^,]` matches `-`, measured 610 ms at 24 segments and climbing) while `^(?:,[^,-]+-[^,-]+)*X$` is **published** (measured flat at 24 segments). The simpler rule "at most one variable-width atom" would have refused the second, which is why it is not the rule.
>
>     ⚠️ **Three holes, one cause: the walk was not uniform.** Found by review in one round, and all three were the same defect seen from different sides — which is why the fix is a rewritten traversal rather than three patches.
>
>     | What was missed | Measured, Node 22.23.2 |
>     |---|---|
>     | **A pattern with no parentheses**, since every rule was driven by the frame list — `^a*a*a*a*a*a*b$` | **26.4 s** on 100 characters and a failing one |
>     | **Any repetition with a finite bound**, since the gate read "unbounded" — `^(a\|aa){1,32}$` | **24.3 s** on 40 characters |
>     | **A group holding both atoms**, since the pending atom was overwritten rather than compared — `^(?:,(?:a*a*))*X$` | **58 s** on 20 segments |
>
>     ⚠️ **A finite bound is not a safe bound, and there is deliberately no threshold.** The bound is the exponent and the *body* is the base, and nothing bounds the base: `^(a|aa){1,16}$` is 7 ms while `^(a|aa|aaa){1,16}$` is 3.4 s and `^(a|aa|aaa|aaaa){1,14}$` is 16.6 s. The safe bound *falls* as the body widens, so a constant is something a wider body defeats. Every repetition that can run twice is screened, and `^(a|aa){1,4}$` is now refused where this document previously published it with the reasoning *"a bounded outer quantifier caps the exponent, so the ambiguity costs nothing"*. It caps the exponent and not the base.
>
>     ⚠️ **The limit on adjacent atoms differs by context, because the cost class does.** Inside a repetition, k adjacent variable-width atoms give the repetition k choices per iteration and the total is **exponential**; at the top level the same k is a **polynomial of degree k**. So a repetition body admits **one** and the top level admits **two**:
>
>     | | k=2 | k=3 | k=4 | k=6 |
>     |---|---|---|---|---|
>     | `^a*…b$` at n=100 | 0 ms | 3 ms | 68 ms | **26.4 s** |
>     | at n=1000 | 2 ms | 490 ms | — | — |
>
>     Two is quadratic in the value's length and it is what real patterns are made of: `^.+\.[a-z]+$` and `^[^@]+@[^@]+$` both measure 0 ms and both would have been refused by a limit of one. Three is cubic and already 490 ms at 1,000 characters.
>
>     ⚠️ **An alternation inside a required group is not spliced into the run, and making `|` end a run was only half right.** A real branch boundary does end one; a **spliced** one does not, because the group's body was flattened into the enclosing list where its `|` means nothing. `^a*(?:b|a*)a*c$` read as two short runs when its second branch is `^a*a*a*c$` — Node 24 spends about **1.9 s on 2,001 characters** and past **15 s** on the 5,000 a `text` field admits. A multi-branch group stays whole and is priced by what it can match; its own branches are walked separately, because a run inside one branch is still a run.
>
>     ⚠️ **And "can it match two lengths" is the question, not "does it contain a variable atom".** `(?:a|aa)` holds neither quantifier nor class and matches one character or two, so the old question called it fixed and `^a*(?:a|aa)a*b$` published. `fixedWidth()` already reads branches, escapes and classes and returns null for anything it cannot measure, which is the conservative answer where it matters.
>
>     ⚠️ **AND THE ALLOWANCE REQUIRES `^`, which this paragraph's own premise needed and the rule did not ask for.** Review found it. Every measurement above is of an **anchored** pattern; an unanchored one is retried from every starting position, so the same two-atom run pays one more factor of the value's length and lands in exactly the cost class the limit of two exists to refuse. Measured on Node 22.23.2, all-`a` subjects that fail:
>
>     | n | `a*a*b` | `a*a*b$` | `^a*a*b` |
>     |---|---|---|---|
>     | 500 | 286.4 ms | 285.1 ms | 1.8 ms |
>     | 1,000 | 489.7 ms | 491.5 ms | 1.5 ms |
>     | 2,000 | 3,941.8 ms | 3,875.7 ms | 5.8 ms |
>     | 5,000 — the `MAX_CONFIGURABLE_LENGTH` ceiling | **60,231.6 ms** | **60,339.4 ms** | 36.0 ms |
>
>     So the limit is **one** where nothing anchors the search, which is the same rule stated once rather than a second number: an unanchored search costs one degree more, so it may hold one atom fewer.
>
>     ⚠️ **A trailing `$` does not help**, which is the middle column — the retry is at the *start*, so only `^` removes it. Reasoning would have got that wrong in a plausible direction, which is why it is measured and why `anchorsTheSearch()` looks for `^` alone.
>
>     ⚠️ **PCRE pays none of it**: every cell in the first two columns is 0.0 ms through `delimit()` on PCRE 10.48, which auto-possessifies the stars and knows the subject must contain a `b`. The two engines **agree**, so no comparison of verdicts can find this — it is a cost the server never pays and the published constraint hands the consumer, which is rule 3 exactly, and ADR-027's floor is why it is not the consumer's problem to absorb.
>
>     ⚠️ **Anchoring is a property of the BRANCH, not of the pattern**, and it is read through what is transparent: a required group (`(?:^a*a*b)` anchors) and a leading assertion (`(?=x)^a*a*b` anchors), but not an alternation unless **every** branch does — `(?:^|,)a*a*b` is refused, and `a*a*b|^a*a*c` is refused for its first branch while its second would be fine alone.
>
>     ⚠️ **And a backreference is as wide as the capture it names**, which the rule was reading from the reference's own quantifier — `\1` carries none, so `^(a+)(a+)\1$` counted two variable-width atoms where there are three. It is a divergence as well as a cost: at 5,000 characters `preg_match()` exhausts its backtrack limit and returns **false** while Node 22.23.2 **matches** in 13.3 ms, so the published schema accepted a value the server rejects. `^(a)(a+)\1$` and `^(a{2})(a+)\1$` still publish, because a fixed-width capture makes the reference fixed too; `\k<name>` is not resolved and counts as variable, which costs at most a refusal of a shape that already has two variable atoms beside it.
>
>     ⚠️ **And k of them cost k times one**, which that rule evaluated one at a time: each is individually inside the allowance, and a hundred of them is 709 characters of pattern. Measured on a 5,000-character value, exactly linear — one **36.9 ms**, two **74.6 ms**, sixteen **586.9 ms**, a hundred **3,719.9 ms**. One is already at the quadratic allowance's own ceiling, so the aggregate budget is what one costs: a repetition may hold **one** assertion whose scan grows with the value. Fixed-width assertion bodies do not scan and are not counted — `^(?:a(?!ac)(?!ad)(?!ae))*X$` publishes.
>
>     ⚠️ **An assertion inside a repetition is paid for on every iteration**, and every other rule published it: `^(?:(?!a*a*c)a)*bX$` has a repeated body that is fixed width — the assertion consumes nothing and the `a` is one character — so this rule's first proof is satisfied and nothing descended into the lookahead. Its own body holds a run of two. Measured on a failing 2,002-character value: **3,894 ms**, against **6 ms** for `^(?:(?!a*c)a)*bX$`. The assertion is held to the REPETITION's limit, one variable-width atom, exactly as the body around it is.
>
>     ⚠️ **And a positive assertion can carry the anchor**, which was the mirror-image false refusal: `(?=^)` says the position is the start as surely as `^` does, and the anchor search skipped past it as it skips every assertion — so `(?=^)(?:a\|a)(?:a\|a)(?:a\|a)(?:a\|a)b` was refused under the unanchored ambiguity budget while the `^` spelling publishes. Only a **positive** assertion, and only when **every** branch of its body anchors: `(?!^)` asserts the opposite and `(?=^\|,)` asserts nothing about the start on its own.
>
>     ⚠️ **A required group divides a run whatever its width.** `^a*a*(?:b|c)a*$` was refused: the group is fixed width, so the branch that applies the all-branch lead proof never ran, and a literal cannot be pulled out of a whole group — so the run survived it and the last `a*` counted as a third atom. Both branches lead with a literal `a*` cannot match, which is the proof already applied to a variable-width group; the width was never what made it work. `(?:b|a)` still refuses, because `a*` may swallow that branch's lead.
>
>     ⚠️ **Two more false refusals, both of them a walk stopping at something that consumes nothing.** `a{0}^a*a*b$` is `^a*a*b$` to both engines, and the search for the anchor stopped at the dead atom — so an anchored pattern was refused under the unanchored limit. And a literal with a **fixed** repetition did not end a run, because the check was skipped for any quantifier at all: `^a*a*b{1}a*$` is the accepted `^a*a*ba*$` written another way. Only a quantifier that can run **zero** times fails to force the boundary; one that runs a variable number of times is a variable-width atom, which the rule already counts.
>
>     ⚠️ **And a branch boundary ends a run**, which was a false refusal until review found it: `|` reached the run walk as an ordinary non-variable atom and nothing reset the state, so `^a*a*|^b*` was refused for three atoms in a row that no execution path contains. The cost of that one was an upgrade blocked by `--strict` over a harmless stored pattern.
>
>     ⚠️ **The obvious next question is whether an unanchored search multiplies the ambiguity ceiling and the delimiter proof too. It multiplies both, and this paragraph got one of them wrong.**
>
>     It said neither mattered, because both sat low enough to absorb a factor of the length — on the strength of sixteen copies of `(?:a|a)` then `b` measuring **178.6 ms unanchored against 0.0 ms anchored**. That measured **one shape**. Review sent another with the same product and two-character branches:
>
>     | at the ceiling, 5,000 characters, a failing subject | unanchored | anchored |
>     |---|---|---|
>     | 16 × `(?:a\|a)` then `b` | 142.7 ms | 0.2 ms |
>     | 16 × `(?:ab\|\x61b)` then `c` | **736.2 ms** | 0.5 ms |
>     | 16 × `(?:ab\|ab)` then `c` | **732.4 ms** | — |
>
>     736 ms here is seconds on ADR-027's floor, so the negative was **wrong rather than incomplete** — a measurement of one shape standing for a class, which is the error this section warns about in three other places. An unanchored pattern's ambiguity budget is now the anchored product divided by the longest value a field may hold: **65,536 over 5,000 is 13**, about three binary choices where an anchored pattern gets sixteen.
>
>     ⚠️ **A product of 8 still publishes unanchored**, and that is deliberate rather than an oversight: refusing any ambiguity without an anchor was the first version of this rule and it refused fifteen shapes in this repository's own tests — all products of 2, all linear at any length. A false refusal for a real cost is still a false refusal.
>
>     ⚠️ **And the product is the BRANCH's, not the pattern's** — found by reading the rule back rather than by measuring it. `^(?:ab|ab)(?:ab|ab)(?:ab|ab)(?:ab|ab)c|x` holds all its ambiguity in the anchored branch and a single literal in the other, and the whole pattern's product refused it for the `x`. An unanchored branch is only ever retried over its own ways to match.
>
>     **The delimiter proof was the half this paragraph got right**: the unanchored comma list `[^,]+(?:,[^,]+)*X` measures **57.5 ms** on 2,500 items, already past the ceiling, so it needs no rule of its own.
>
>     ⚠️ **That allowance needs a real ceiling on the value, and the sentence here used to claim one that did not exist** — "which `TextType` bounds by its configured `maxLength`, 255 by default". Review checked it: the setting had no upper bound, so quadratic meant whatever an org configured. Measured, `^a*a*b$` takes **6.2 s at 65,535 characters and 14.4 s at 100,000**. `TextType::MAX_CONFIGURABLE_LENGTH` caps it at **5,000**, which keeps the worst adversarial case — quadratic pattern, maximal value, subject failing at the end — at 36 ms here and inside half a second on the 1 vCPU floor. It is generous for a single-line field, and `textarea` and `rich_text` take no pattern, so neither is affected.
>
>     ⚠️ **AND THE CEILING BOUNDS ONE ELEMENT WHILE THE FIELD PUBLISHES AN ARRAY**, which review found one rule later. A multi-value `text` field applies its pattern to every item, and a cardinality of `-1` published no `maxItems` at all — so the bounded quadratic was multiplied by an unbounded item count. Measured on Node 22.23.2, an accepted `^a*a*b$` against all-`a` values that fail:
>
>     | items | 10 | 25 | 50 | 100 | 200 |
>     |---|---|---|---|---|---|
>     | 255 characters | 1.3 ms | 2.4 ms | 4.8 ms | 9.7 ms | 19.5 ms |
>     | 1,000 | 14.4 ms | 36.5 ms | 73.2 ms | 145.4 ms | 290.6 ms |
>     | 5,000 | 359.6 ms | 900.4 ms | **1,802.5 ms** | **3,595.8 ms** | **7,262.8 ms** |
>
>     Linear in the item count and quadratic in the length, so the work is `items × length²` and the bound that keeps it where one maximal element put it is `(5000 / length)²` — **384** items at the 255-character default, 25 at 1,000, and **1** at the ceiling itself. `TextType::maxItems()` derives it, `apiSchema()` publishes it as `maxItems` and `validationRules()` enforces the same number, because two expressions of one intent is how a published constraint and an enforced one drift apart.
>
>     ⚠️ **Only where the allowance is claimed.** A run of one variable-width atom is linear — a hundred maximal values against `^[a-z]+$` is half a million character tests — so the bound applies exactly when `Pattern::costsQuadraticPerValue()` says the pattern's cost grows with the square of the value. A declared cardinality is narrowed, never widened: two means two.
>
>     ⚠️ **And so does an assertion at every starting position**, which neither of the other two tests reaches: `(?=a*b)a` holds one variable-width atom inside a lookahead with no repetition anywhere, and the lookahead scans the remaining value wherever the unanchored search tries. **36.0 ms** at 5,000 characters against **0.0 ms** for `^(?=a*b)a` and for `(?=ab)a`. Both polarities count — whether a negative assertion's body is expensive depends on the value rather than on the pattern, and this section prices constructs rather than subjects everywhere else.
>
>     ⚠️ **And a repeated assertion rescans the suffix on every iteration**, which neither of those tests sees: `^(?:a(?!a*b))*$` holds no run of two anywhere and anchors its search, and the lookahead walks the remaining value once per outer iteration. Measured on a 5,000-character value: **35.8 ms**, against **0.1 ms** for `^(?:a(?!ab))*$` — so the cost is the **variable-width** atom inside the assertion, not the assertion. A hundred of those in one valid array is 3.6 seconds. The pattern itself stays publishable: one value at 35.8 ms is inside the quadratic allowance, and it is the array that needs bounding.
>
>     ⚠️ **And an unanchored search supplies the second factor itself**, which the classification missed. `a*b` holds ONE variable-width atom, so the run rule calls it linear — and unanchored it is retried from every starting position, where the star takes the whole remaining value and the `b` refuses all of it. Measured on Node 22.23.2 with 5,000 `a`: **`a*b` 35.6 ms** against **`^a*b` 0.0 ms**, `a*b$` 35.6 ms, `.*x` 37.7 ms against `^.*x` 0.0 ms — the same order as the anchored quadratic this bound exists for. `[a-z]+` is the line, and it is measured rather than assumed: with nothing after it that can fail, every starting position matches at once or fails in constant time, so the retry adds a factor of nothing and the field keeps no bound.
>
>     ⚠️ **And that question is asked of the whole pattern, not of its top level.** The first version asked `quadraticRuns()`, which PRICES a sequence and reads its own atoms so the allowance is charged at exactly one level — so it never looks inside a group, and `^(?:a*a*)b$` came back linear while being the same expression as `^a*a*b$`. The walk that owns the whole pattern splices what runs once, descends into assertion bodies and into every branch of a multi-branch group, and fails closed on anything it cannot parse.
>
>     ⚠️ **And the FORM reads the same number.** `FieldValueRenderer` capped its repeater from `cardinality()` alone, so an author could add rows that validation and the published schema both refuse — three readings of one bound, and the form was the one that disagreed. `maxItems()` is on the `FieldType` contract for exactly that reason: published, enforced and rendered from one answer.
>
>     ⚠️ **It is an upgrade hazard whose refusal lands on an ENTRY**, so `kitsune:audit-patterns` reports it alongside the other two — and names the remedy, which is not the obvious one: cardinality is part of the locked shape once data exists, so what an author can still change is `maxLength` or the pattern.
>
>     ⚠️ **Ambiguity does not need a quantifier, and this is a different axis from every rule above.** Found by review. `^` then thirty copies of `(?:a|a)` then `b$` has no repetition anywhere and no variable-width atom, so nothing looked at it — each group offers two identical ways to match one character, and thirty offer 2³⁰. **PCRE exhausts its backtrack limit and Node 22 takes 50.2 s**, on a 240-character pattern.
>
>     A **product**, not a count, because the cost is measured to be exactly that — about 45 ns per combination on Node, linearly: 3 ms at 2¹⁶, 47 ms at 2²⁰, 3.1 s at 2²⁶. The bound is therefore on the cost (`MAX_AMBIGUITY_PRODUCT`, 65,536), which leaves an order of magnitude for ADR-027's floor while keeping sixteen ambiguous binary alternations publishable. Only **ambiguous** alternations count: thirty copies of `(?:a|b)` are linear, because at most one branch can match at a position.
>
>     ⚠️ **It took two flat models, wrong in opposite directions, before the cost was computed recursively** — and the second was the fix for the first. A flat walk over the frame list multiplied every level of a nest, because a child frame is recorded before its parent, so seventeen nestings of `(?:<previous>|a)` were refused as 131,072 combinations where 100,000 Node matches take **3 ms**. Sorting outermost-first and skipping covered children then *under*counted: an ambiguous outer alternation suppressed its children and contributed only its own branch count, so `^(?:` + 28 × `(?:a|a)` + `|` + 28 × `a` + `)$` read as **2** where the cost is **2²⁸** — 231 characters, and Node spends **10.8 s** on a 29-character subject.
>
>     A flat product cannot express either shape, because the cost of a group depends on what is inside it. Two rules do, and both cases then fall out rather than needing a rule of their own:
>
>     | | cost |
>     |---|---|
>     | a **sequence** | the **product** of its parts — each choice multiplies the ones beside it |
>     | an **ambiguous** alternation | the **sum** of its branches — every branch must be tried |
>     | a **prefix-free** alternation | the **max** — at most one branch can match at a position |
>
>     The sum is what makes nesting cheap again: `(?:X|a)` costs `cost(X) + 1`, so seventeen nestings cost 18 rather than 2¹⁷.
>
>     ⚠️ **Only a required literal the left atom cannot match ends a run, and a fixed width does not.** `a*[a-z]{2}a*` looks divided and is not: the middle atom is two characters wide but its *position* is still free. The argument is the delimiter proof's, and it is about distinguishability rather than width.
>
>     ⚠️ **Membership is tested against the pattern that is actually compiled**, which is not the one the author wrote. `delimit()` rewrites `.`, `\s` and `\S` to explicit ECMAScript-equivalent classes, and the two dialects disagree on three code points — so probing the raw text asks about a class that is never compiled. PCRE's `\s` excludes U+FEFF, so `^(?:<U+FEFF>\s?)*X$` was told its optional atom could not reach the delimiter and was exempted; **ECMAScript's `\s` includes the BOM, and Node 22 takes 17.2 s** on 40 of them. The same normalisation settles the opposite direction: `<U+FEFF>\S?` cannot consume a BOM in *either* dialect once compiled, so it stays exempt.
>
>     ⚠️ **Conservative where it cannot be sure.** `(?:a|[b-z])+` has disjoint branches and is refused, because deciding whether two character classes overlap is more analysis than belongs on an authoring request. The message names the portable ways out, and the harness reports the cost rather than hiding it.
> 4. **A capturing group inside a lookbehind must be fixed length.** Also found by review, and measurement placed the line rather than a blanket ban: with a fixed width the engines agree, including two adjacent captures — `(?<=([ab]{2})([bc]{2}))\2\1$` matches in both. Make either variable and they part company, because the engines traverse a lookbehind in **opposite directions** and allocate the variable part to different groups. `(?<=(a+))\1$` on `aaaa`: PCRE errors, ECMAScript matches. `(?<=([ab]{1,2})([bc]{1,2}))\2\1$` on `abcbca`: PCRE says no, ECMAScript says yes.
>
>     ⚠️ **And a fixed-width capture under a repetition is not fixed either.** Found by review after the width rule. A width is a property of one iteration; *which* iteration's text remains captured is a property of the traversal, and the engines traverse a lookbehind in opposite directions. Measured on PCRE 10.48 with Node 22.23.2:
>
>     | | `aba` | `abb` | `aa` | `ab` | `aabaa` | `abab` |
>     |---|---|---|---|---|---|---|
>     | `([ab]){1,2}` PCRE | no | **match** | match | no | **match** | no |
>     | `([ab]){1,2}` Node | **match** | no | match | no | no | **match** |
>     | `([ab]){2}` PCRE | no | **match** | no | no | **match** | no |
>     | `([ab]){2}` Node | **match** | no | no | no | no | **match** |
>
>     `{2}` is a **fixed** repetition of a **fixed-width** body — the width rule passes it — and it diverges on three of six subjects, which is what makes this a second property rather than a wider net on the first. An **ancestor's** repetition counts too, because `(?:([ab])){1,2}` measures identically: the capture is written once and still runs twice. `([ab]){1}` runs it once, has nothing to reallocate, agrees everywhere, and stays published.
>
>     ⚠️ **The first version of that table was my instrument, not the engines.** `php -r "… \\\\1 …"` through a shell is a literal backslash followed by `1` rather than a backreference, so PCRE was handed a different pattern from Node and reported `no` for every subject. It is the identical error `tools/pattern-parity/README.md` opens with, and the fix is the same: both readers get identical source text, from files rather than from a shell.
>
>     ⚠️ `(?<=(a{1,2}))\1$` and `(?<=(a?))\1$` *agree* on the subjects tried and are refused anyway. That agreement is subject-dependent luck rather than a property of the construct, and a rule that admitted them would be drawing its line at whichever subjects happened to get measured.
>
> ⚠️ That row was originally counted among the divergences, and review corrected it: comparing the harness's full result shapes made it look like disagreement, because only the ECMAScript side carries timeout metadata. It is now classified as *no verdict from either engine*, which is both accurate and a sharper statement of the same point — the danger here is the cost of the pattern, not a difference of opinion about it.
>
> 5. **A group bounded at zero repetitions may not hold an assertion.** `{0}` is dead markup that both engines skip — except that PCRE stops matching when the dead group's alternation *ends* in a positive lookahead. Measured at production fidelity on PCRE 10.48 and Node 22.23.2: `(?:a|(?=a)){0}` matches every subject under ECMAScript and **none** under PCRE, while `(?:(?=a)|a){0}`, `(?:a|(?<=a)){0}`, `(?:a){0}` and `a{0}` agree — so the branch order and the direction of the assertion both matter.
>
>     ⚠️ **AND `^` IS AN ASSERTION, which this rule was not reading.** It looked only at parenthesised frames, so `(?:a|^){0}` held one and reported none — and this was a **live defect on the pair this harness runs**, not insurance on another. Measured at production fidelity on PCRE 10.48 with Node 22.23.2:
>
>     | Pattern | PCRE | ECMAScript |
>     |---|---|---|
>     | `(?:a\|^){0}$` on `a` | no match | **match** |
>     | `(?:^\|a){0}$` on `a` | match | match |
>     | `^(?:a\|^){0}$` on `a` | no match | no match |
>
>     PCRE's start-anchor optimisation survives the dead group; ECMAScript skips the group outright. Branch order matters here exactly as it does for the lookahead form, and what encloses the group matters too — which is why the rule is about the shape rather than about the measured subject. Anchors are found by walking atoms rather than by searching for `^`: `\^` is an escaped literal and `[$]` is a class member, and only a parse tells them apart.
>
>     ⚠️ **The rule is wider than the quirk, deliberately.** *"An alternation whose last branch is a positive lookahead"* is a shape nobody can check by reading it. The `{0}` allowance exists only so dead markup does not fail an upgrade, and a dead group that also holds an assertion is not something anybody wrote on purpose.
>
>     ⚠️ **A frame under a zero-repeat ANCESTOR never runs either**, which review found: `^(?:(a|aa)+){0}$` was refused for the inner `+` although the group holding it executes zero times. Both engines match only the empty string, so `--strict` was blocking an upgrade over a harmless stored pattern.
>
> 6. **A positive lookahead may not assert what an adjacent optional atom consumes.** `(?=a)a?a` — the assertion says the next character is `a`, and the optional atom beside it can consume an `a`, so the assertion constrains nothing the atom does not.
>
>     ⚠️ **THIS ONE IS INSURANCE RATHER THAN A MEASUREMENT, and that is published rather than implied.** On PHP 8.4.25 / PCRE 10.48 / Node 22.23.2 both engines match `a` here, and so do `^(?=a)a?a$`, `(?=a)a?`, `(?=a)aa`, `a?a`, `(?=a)a*a` and `(?=ab)a?ab`. **Review measured PCRE 10.44 with Node 24.15 disagreeing** — PCRE rejecting `a` while ECMAScript matches it — and `composer.json` requires PHP `^8.4`, whose earliest releases bundle PCRE2 10.44. The shape is redundant, so refusing it costs approximately nothing; a rule that is right on one version pair is worth less than that.
>
>     ⚠️ **Either side, and a lead read through anything transparent.** Four review rounds found four ways past a narrower version of this rule and they were all one way — it read the pattern more narrowly than the shape occurs. A group hid the shape (`(?:(?=a)a?a)`), a group hid the asserted character (`(?=(?:a))(?:a)?a`), a quantifier hid it (`(?=a+)a?a`), and the optional atom sat in FRONT (`a{0}(?=a)a`, `a?(?=a)a`). A rule a pair of brackets defeats is not a rule.
>
>     ⚠️ **AND WITHOUT AN ANCHOR, ANY NULLABLE ATOM AFTER THE LOOKAHEAD IS ENOUGH — overlap or not.** Review measured `(?=a)b*a` on the same 10.44 pair: PCRE rejecting `a` while ECMAScript matches it, with `^(?=a)b*a` agreeing. `b*` cannot match the asserted `a`, so the overlap half above passes it on purpose, and the divergence is there one exemption away. It is redundant for a reason of its own: **a search may begin wherever it likes**, so an unanchored assertion in front of something that can match nothing decides nothing the search had not already decided — `(?=a)b*a` and `b*a` accept exactly the same values, and `preg_match()` and a JSON Schema `pattern` both ask only whether a match exists. Anchored, the assertion is doing work again, and `^(?=a)b*a` publishes.
>
>     ⚠️ **A group that consumes nothing is not a group here either.** `(?:(?=a))a?a` wrapped the lookahead alone, so the recursion checked the assertion with no neighbour inside the wrapper and the outer walk read the wrapper as an ordinary atom — the `a?` outside was never compared with the assertion inside. Such wrappers are unwrapped before the walk, at any depth, and `(?:(?=a)x)a?a` still publishes because that group consumes.
>
>     ⚠️ **The neighbour is the next atom that CONSUMES something**, on both sides. A second assertion between the lookahead and the nullable atom hid the shape — `(?=a)(?!b)b*a`, and a negative lookbehind does the same — because the immediate neighbour was zero-width and the walk stopped there. So did an atom bounded at zero repetitions: `a?b{0}(?=a)a` read `b{0}` as the neighbour and skipped the `a?` behind it.
>
>     ⚠️ **And dead markup beside an unanchored lookahead is itself a divergence**, which is the same rule one step further out. `b{0}(?=a)a` is rejected by PCRE 10.44 and matched by Node 24.15 while `^b{0}(?=a)a` agrees, and `b` is not what the lookahead asserts — so the overlap half passes it deliberately and the disagreement is there anyway.
>
>     ⚠️ **That narrowed a refusal, and the narrowing is the honest half.** `a{0}(?=a)a` used to be refused as an *overlap*, as though `a{0}` could match the asserted `a`. It cannot match anything at all, so the reading was wrong where the verdict was right. `^(?:a{0}(?=a)a)$` therefore **publishes** now: anchored, the engines agree, and the `{0}` allowance exists precisely so dead markup in a stored pattern does not fail an upgrade.
>
>     ⚠️ **The overlap has to be PROVED, and this is where the rule stops.** Either PCRE answers class membership for a single-character lead, or the two atoms are written identically — which is what refuses `(?=[0-9])[0-9]?[0-9]`. So `^(?=[A-Za-z])[A-Za-z0-9]*$` still publishes: the assertion excludes a leading digit where the neighbour admits one, so it is not redundant, and an intersection test that guessed would refuse the identifier pattern every schema has. Deciding whether two **different** classes intersect is the primitive [#73](https://github.com/adamgreenwell/kitsune/issues/73) is open on, and it is the same missing question there.
>
> 7. **At most 32 levels of nested groups.** A limit rather than a judgement, and it is published because it was **silent and misdiagnosed** before: every recursive walk in `Pattern` stopped at 64 levels and reported *maximal ambiguity*, so `^` then 65 nested `(?:` around an `a` — 263 characters, identical in both engines — was refused for reaching a retry ceiling it does not reach.
>
>     ⚠️ **Raising the limit was the wrong fix, and measuring showed it.** The structural analysis is superlinear in depth, and it runs on every settings save and on every stored pattern in the migration audit:
>
>     | depth | 8 | 16 | 32 | 48 | 64 | 80 | 249 | 497 |
>     |---|---|---|---|---|---|---|---|---|
>     | | 2.5 ms | 2.7 ms | **16.2 ms** | 53.8 ms | 129.5 ms | 258.6 ms | 11.1 s | 43.1 s |
>
>     So the limit is 32, it is stated, and the refusal names it — nothing a field validation needs nests past three. The internal walks keep a bound of their own, because a walk with no bound can be made to recurse for ever by a pattern that never compiles; the difference is that only an inadmissible pattern now reaches it.
>
> ### What this costs
>
> Measured, so it is a number rather than a worry: of 200 candidates, **two** are refused today that both engines agree on — `\p{Lower}` and `\p{Alpha}`, POSIX-style aliases missing from the property allowlist. Widening a list is a reviewable, testable act; a denylist's gaps are found by accident. **Both are now on it, and `\p{Upper}` with them** — the obvious third of the family, added at the same time so the allowlist does not carry an arbitrary subset.
>
> ⚠️ **Added on a set comparison, not on compiling**, because compiling proves only that a name is accepted. Each alias was compared with its canonical spelling across all 1,114,112 codepoints in *both* engines and is exactly equal: `Lower`/`Lowercase` 2,595 members, `Alpha`/`Alphabetic` 147,421, `Upper`/`Uppercase` 2,006. `\p{Space}` is the reason this is measured one name at a time rather than adopted as a family — **PCRE compiles it and ECMAScript rejects the name**, so it stays out.
>
> The remaining `agrees BUT refused` rows are refusals on purpose, not gaps — **36** of them, and each has a reason that is not "nobody got round to it". ⚠️ This paragraph said *"six of them"* and the accounting below said *"of eleven rows"* while the harness reported twenty-three: the count stopped tracking as each structural rule landed, and an accounting that does not add up is worth less than no accounting, because it reads as complete. Every row the harness reports is in the table now, and the arithmetic under it is the check:
>
> | Refused | Why it is not a gap |
> |---|---|
> | `\bab\b` | A portable spelling exists and the message names it |
> | `^\p{Cn}$`, `^\p{C}$` | Version skew the measured pair cannot show, because it shares one Unicode version |
> | `^(a*)*b$` | Refused on **cost**, not portability — the engines agree here only because the harness's subject is benign |
> | `^([a-zA-Z0-9]+\.?)+@x\.com$`, `^(a|aa)+$`, `^(a{1,2})+$`, `^([a-z]{1,8})+$`, `^(?:[a-z]|x)+$`, `^(a\|aa){1,32}$`, thirty copies of `(?:a\|a)`, one nested in a branch | Refused on cost, and neither engine gives a verdict at all: PCRE exhausts its backtrack limit while ECMAScript passes the deadline. They are counted here *and* as `no verdict`, because "both agree" and "neither answered" are the same shape to a comparison of results |
> | `^(?:a*a*b\|a*a*c\|…)$`, `^a*(?:b\|a)*a*c$`, `^a*a*ba*a*c$`, `^()()…(,)(?:,\10?)*X$`, `a*a*b`, `a*a*b$`, `^a*(?:b\|a*)a*c$`, `^a*(?:a\|aa)a*b$`, `^(a+)(a+)\1$`, 16 × `(?:ab\|\x61b)` then `c`, `^(?:(?!a*a*c)a)*bX$`, `^(?:a(?!a*c)(?!a*c))*X$` | Refused on cost, where the harness's subject is short enough to fail fast. The adversarial ones are measured and in the rules above: **6 s**, **20 s**, **1.5 s**, **60 s** for the two unanchored forms, and **1.9 s at 2,001 characters** for the spliced alternation |
> | `(?=a)a?a`, `(?:(?=a)a?a)`, `a{0}(?=a)a`, `(?=(?:a))(?:a)?a`, `(?=a+)a?a`, `(?=[0-9])[0-9]?[0-9]`, `(?=a)(?:a?)a`, `(?=a)b*a`, `(?=a)(?!b)b*a`, `b{0}(?=a)a`, `(?:(?=a))a?a` | Rule 6, which is **insurance on a version pair this harness does not run** — PCRE 10.44, measured by review. Eleven spellings of one shape, and the harness carries all ten because each was published until the round that found it — one of them found by probing the family rather than by review. `^(?=a)b*a` is in the corpus beside them and **publishes**, because the anchor is what makes the assertion do work |
> | `^(?:a*a*b\|c*c*d\|…)$` — nine branches, distinct leads | The one **known over-refusal**, and it is filed rather than excused: eight of the nine branches fail on their first atom, so the real cost is one quadratic — **35.8 ms** against the 324.3 ms of the shared-lead shape beside it. Summing branch costs cannot see that, and [#73](https://github.com/adamgreenwell/kitsune/issues/73) is the atom-against-atom proof it needs |
>
> So of 36 rows: **three** are portability judgements (`\b` and the two `C` categories), **21** are cost refusals — eight where neither engine answers at all and thirteen where the harness's subject is simply benign — **eleven** are rule 6's insurance against PCRE 10.44, and **one** is the over-refusal above. Three plus twenty-one plus eleven plus one, and only the last is an omission anybody should want closed.
>
> ⚠️ **`divergent AND accepted` is now 0.** It was 2 before the structural rules above were enforced, and both entries were rules this document already claimed. That sentence used to count the rules — *"the five structural rules"* — and the count went stale the moment a sixth landed, which is the same rot as the stale candidate count this section already fixed once. A test pins the heading above to the list beneath it now.
>
> ⚠️ **Migration is not optional, and `kitsune:audit-patterns` is it.** Patterns already authored were accepted by the screen, not by the grammar, so any that fall outside it must be found before this lands — a pattern that saved yesterday and is refused today is a broken install, not a fixed one.
>
> This paragraph stood here for a while with **nothing implementing it**, which review found by searching for the migration it mandates. That is the same failure as a published rule with no enforcement, and this document had already made it once in this section.
>
> ```
> php artisan kitsune:audit-patterns            # report; exits 0
> php artisan kitsune:audit-patterns --strict   # gate; exits non-zero if any row is unpublishable
> ```
>
> ⚠️ **It streams.** The first version collected every failing row and printed afterwards, so memory grew with the number of **failures** rather than with the chunk — `chunkById()` bounds only the database batch. An audit whose entire purpose is to run before an upgrade on a large installation could therefore exhaust ADR-027's 1 GB floor before printing anything, which is the worst possible moment to run out of memory: the operator learns nothing and cannot tell whether it found nothing or died. Rows are emitted as it walks and only the count is carried, which also puts the summary at the end where a reader of a long report finishes.
>
> ⚠️ **What an upgraded installation actually suffers is worse than "some patterns are now invalid".** A stored `^(a|aa)+$` keeps being published in the API schema and keeps being enforced server-side, because nothing revalidates a row that is not saved. Then the first *unrelated* edit to that field — a label, a help string — fails `guardSettingsAreUsable()`, and the author is told their pattern is invalid on a screen where they changed something else. The refusal is correct and the moment is incomprehensible.
>
> ⚠️ **There is no `--force`,** unlike `kitsune:schema-sync`. A pattern says what a field accepts, and only its owner knows what that should be, so there is nothing for a repair flag to do.


The same applies to `\k<name>`: a named reference is a backreference. And optionality is **inherited** — `^((a))?\2$` and `^(?:(a))?\1$` both diverge, because the enclosing group carries the quantifier while the capture itself carries none, and the enclosing group need not be a capturing one.

Participation is decided against the group's **own closing parenthesis**, not against nesting. `^((a)\2)$` and `^(a(b))\2$` sit inside an outer group that has not closed and **agree** in both engines, so refusing them would be a false refusal.

Inherited optionality is likewise a question of **position, not just ancestry**. An optional ancestor only leaves the capture unset if it can be skipped while execution still *reaches* the reference — so where the reference sits inside that same ancestor, the two agree and must be allowed:

| Pattern | Reference vs. the optional ancestor | Portable? |
|---|---|---|
| `^(?:(a)\1)?$` | inside it — skipping the group skips the reference | ✅ allowed |
| `^(?!(a)\1)b$` | inside the assertion | ✅ allowed |
| `^(?:(a))?\1$` | outside it | ❌ diverges |
| `^(?!(a))\1$` | outside the assertion | ❌ diverges |

⚠️ **A pattern is bounded at `Pattern::MAX_LENGTH` (1,000 characters), enforced in `delimit()` as well as `unpublishable()`, and published in `settingsSchema()`.** `delimit()` is the chokepoint every path shares — `compiles()` uses it, and so does the per-value `patternRule()` — whereas `unpublishable()` is not even the first call `validateSettings()` makes. A bound on one entry point is a bound on one entry point. Screening cost grows faster than length — the scan reads one character at a time with `mb_substr`, which walks from the start of the string each call — so an unbounded pattern is a way to hold a request open rather than a way to describe a value. Measured before the bound: a portable 5 KB pattern took 22.6s. A pattern too long to screen is one whose portability is unknown, and unknown fails closed.

⚠️ **Measure both engines on byte-identical patterns.** An early comparison here escaped the pattern differently for each side — PHP received `\\1` (a literal backslash then `1`) while Node received `\1` (a real backreference) — so the two engines were not being asked the same question. The conclusions happened to survive, which is luck and not method. Put the patterns in a data file both engines read.

A group inside an **alternation** goes unset with no quantifier anywhere, so branch selection is a third way to reach a non-participating capture — and it needs no group at all: `^(a)|b\1$` alternates at the top level. **The reference must sit in the same branch as the capture, in every alternating ancestor:**

| Pattern | Capture vs. reference | Portable? |
|---|---|---|
| `^(?:(a)\1\|b)?$` | same branch | ✅ allowed |
| `^((a)\|b)\1$` | names the group *containing* the alternation, and entering it always captures | ✅ allowed |
| `^(a)\1\|b$` | same branch at the top level | ✅ allowed |
| `^(?:(a)\|b\1)?$` | different branch | ❌ diverges |
| `^(?:(a)\|b)\1$` | reference outside the alternating group | ❌ diverges |
| `^((a)\|b)\2$` | capture inside one branch | ❌ diverges |
| `^(a)\|b\1$` | different top-level branch | ❌ diverges |

The three allowed rows are why this is not "refuse any pattern containing a pipe" — the capture's **own** frame is excluded from the walk, because entering a group captures it whatever its internal branches do.

> ⚠️ This was a **recorded residual** here until it wasn't. The note said proving it needed "a reachability analysis this screen does not carry", which was true of the analysis as written and not true of the problem: a branch index is just how many top-level separators precede a position. A documented gap is worth re-reading occasionally rather than treated as settled.
| `\x{41}` `\x4` | ECMAScript's hex escape is exactly two digits, and its braced form is `\u{...}` — which PCRE rejects | `\x41` |
| `\k{n}` `\k'n'` | Only `\k<name>` is shared | `\k<name>` |
| `[\1]` | A digit escape is a backreference outside a class and octal inside one, where ECMAScript rejects it | `\x01` |
| `\_` `\:` `\!` `\@` `\~` `\ ` | ECMAScript escapes only its syntax characters — `^ $ \ . * + ? ( ) [ ] { } |` — plus `/`. PCRE puts a backslash on anything and reads the character literally; 35 ASCII marks diverge | drop the backslash |
| `\-` | Portable **inside** a character class only, as a ClassEscape | `-`, or `[\-]` |
| `\c1` `\c!` | ECMAScript's control escape takes an ASCII letter; PCRE also reads a digit or punctuation there. `\cA` agrees in both | `\cA` |
| `a{,2}` `a{}` `a{2,4,6}` `a{ 2}` `a{b}` | ECMAScript accepts only `{n}`, `{n,}` and `{n,m}` as a quantifier and treats anything else as a syntax error; PCRE reads some as quantifiers and the rest as literal text | `{0,2}`, or `\{` |
| `[a\S]` | `\s` splices into a class; a negation cannot | `[^\s]` |
| `a}` `a]` `}a` `[]]` | A closing delimiter nothing opened: ECMAScript reads a lone quantifier bracket as a syntax error, PCRE as a literal character | `\}`, `\]` |
| `[a\E]` `[a\Q!\E]` `[a\N{U+41}]` | These stay **active inside a character class** in PCRE, where the anchors are refused outright | drop them |

Two divergences cannot be screened, because they are in the **engine** rather than the pattern. Both had the API *laxer* than the schema it published, which is the worse direction — a value passes the API and then breaks every generated client.

`.` excludes only LF under PCRE, where ECMAScript excludes LF, CR, U+2028 and U+2029. No PCRE newline convention matches: the default misses CR, LS and PS; `(*ANY)` catches those but wrongly excludes VT, FF and NEL; `(*ANYCRLF)` still misses LS and PS. So a modifier cannot fix it, and every bare `.` outside a character class is compiled as `[^\n\r\x{2028}\x{2029}]` instead — measured to agree with ECMAScript's dot on all nine characters tried. `\.` and `[.]` are literals and are left alone.

`\s` and `\S` diverge on three code points, and the table below once claimed they agreed — a claim made after measuring two characters. PHP's `u` modifier sets `PCRE2_UCP`, so `\s` becomes Unicode's `White_Space` property, while ECMAScript's is a fixed list: **U+0085 NEL** and **U+180E** match under PCRE and not under ECMAScript, and **U+FEFF** (the BOM) matches under ECMAScript and not under PCRE. `\s` is compiled as ECMAScript's explicit set, spliced as a class body when it appears inside `[...]`. `\S` becomes the negated class outside a class and is **refused inside one**, because a negation has no body to splice — `[^\s]` is the portable spelling.

And `$`: PCRE lets `$` match before a final newline, while ECMAScript's `$` without `m` matches only at the end of input. A field validated `^[a-z]+$` therefore accepted `"abc\n"` server-side and every generated client rejected it — the API being the *laxer* of the two, which is the worse direction. Refusing `$` would remove the most common anchor there is, so instead every pattern is compiled with PCRE's `D` modifier, which gives `$` the end-of-input meaning the published schema already promises. The published text is unchanged.

Some of those rows are judgement calls rather than compile failures, and the rule that settles them is: **refuse a divergence when a portable equivalent exists, record it when refusing would remove a capability.** `\d` is refused because `[0-9]` says the same thing.

`\b` is where that rule earned its keep, by overruling the first answer. It looked like the case for recording — a word boundary has no shorthand to redirect an author to, and on ASCII content the engines agree. But the ASCII *definition* is portable when written out, and measuring it settled the question: across all 108 pattern/input combinations tried, `(?:(?<![A-Za-z0-9_])(?=[A-Za-z0-9_])|(?<=[A-Za-z0-9_])(?![A-Za-z0-9_]))` agrees with ECMAScript's `\b` on both engines. So a portable equivalent exists, and `\b` is refused with that spelling named. Inside a character class it is left alone, because `[\b]` is the backspace character in both dialects.

`\p{L}` remains the case for the other half of the rule: refusing it would remove the ability to express a Unicode-letter constraint at all, so the construct is accepted and only its property name is screened.

⚠️ **Two class shapes where the dialects disagree about what the pattern IS**, both found by review and both admitted by scans that were nearly right.

- **A `]` in first position.** PCRE reads a literal member; ECMAScript under `u` reads `[]` as an EMPTY class. `[]]` was already refused as a closing bracket nothing opened, so the scan caught the shape where nothing rebalanced it and missed the shape where something did: **`[]a[]` compiles in both, PCRE matches `a`, and Node can never match** — it reads an empty class, then `a`, then another empty class. First position means after an optional `^`.
- **A set escape as a range endpoint.** This is the cost of admitting a construct by *rewriting* it. `delimit()` splices `\s` into a character list, so PCRE compiles `[\b-\s]` and `compiles()` reports true — while ECMAScript refuses a character-set escape as a range endpoint and **does not compile the published pattern at all**. A rewrite is only equivalent where the syntax around it is equivalent too, and inside a range it is not. Both ends are checked, because `[\s-x]` inverts the same mistake.

The escape rows above were found by **sweeping the whole escape alphabet** on both engines — every letter, digit and punctuation mark, inside a character class and outside one — rather than by collecting reports. That is what turned up `\a` beside a reported `\e`, and `\00` after a first fix had exempted `\0`. The sweep is kept as a test, so the surface stays closed as either engine moves.

⚠️ Its *coverage* has been the recurring defect, not its logic. It missed punctuation, which let `\_`, `\:` and `\!` through a screen built to catch exactly them; and it enumerated only the first character after the backslash, which could never find `\c1` — because `\c` alone does not compile in PCRE and `\cA` compiles in both, so that divergence lives one character further along. It now pairs every multi-character family (`\c`, `\x`, `\k`, `\p`, `\g`, `\o`) with every character in the alphabet, and tries each longer form inside a character class as well as bare — `[a\Q!\E]` is longer than any pair, and `[\E]` on its own does not compile in PCRE at all because the class ends up empty, so only a class with other content in it exposes that one. **An alphabet with a hole in it is a list of known offenders wearing a sweep's clothes** — so the alphabet is the part worth reviewing.

What is *portable* is allowlisted, not what is broken. There are ~170 Unicode scripts and a list naming them to refuse them would go stale on every Unicode release — publishing a schema no consumer can compile. A list of what travels goes stale in the other direction: an author is refused, with a message naming what is allowed, and a maintainer adds the name.

That cost is real and it landed immediately — the first list omitted `LC`, the Cased_Letter group, which both engines accept, so `\p{LC}` was refused for nothing. So the test in `tests/Core/Filament/EntryTypeBuilderGuardsTest.php` runs **both** directions against the two engines: every name the allowlist permits must compile in both, and every name in a candidate corpus that both engines accept must be one the screen permits. The first direction catches a PCRE or V8 release that moves the boundary; the second catches the omission a list of permitted things is prone to. Neither can be caught by reading the specifications, which is why they are measured.

---

## 4. Types shipping in v1.0

Twelve types. Deliberately small — every one added before the API freeze is a permanent maintenance obligation.

| Handle | Strategy | Indexable | Cardinality | Notes |
|---|---|---|---|---|
| `text` | Inline | ✅ | ✅ | Single line. `maxLength`, optional pattern |
| `textarea` | Inline | ❌ | ✅ | Plain multi-line |
| `rich_text` | Inline | ❌ | ❌ | Sanitized HTML — see §6 |
| `number` | Inline | ✅ | ✅ | `integer` \| `decimal`, precision, min/max, step. Bounded by its projection: `integer` by signed BIGINT, `decimal` by `10^precision` |
| `boolean` | Inline | ✅ | ❌ | |
| `date` | Inline | ✅ | ✅ | Date only |
| `datetime` | Inline | ✅ | ✅ | Stored UTC, displayed in the site's timezone |
| `select` | Inline | ✅ | ❌ | Options from `settings`, or from an entry type |
| `multi_select` | Inline | ❌ | — | Always an array; cardinality is intrinsic |
| `relation` | **Relational** | via table | ✅ | Target entry types constrained in `settings` |
| `slug` | **Promoted** | ✅ (column) | ❌ | `scopedUnique` per (site, entry_type) |
| `json` | Inline | ❌ | ❌ | Escape hatch. See §6 |

### Deliberately deferred

| Type | Deferred to | Why |
|---|---|---|
| `repeater` | v1.1 | Nested field groups are the single hardest type: recursive validation, no meaningful indexing, and revision diffs get ugly. Worth doing properly rather than early |
| `code` | v1.1 | Needs a real editor component and a syntax-highlighting payload; no core dependency should pull that in yet |
| `color`, `email`, `url`, `phone` | v1.1 | All are `text` with validation and a nicer widget. Real, but not load-bearing |
| `table` | v1.2 | A repeater in a trenchcoat. Same problems, less justification |
| `geo` | post-1.0 | Wants PostGIS to be worth anything, which contradicts MySQL parity |

**`user` is deliberately not a field type.** Users are not entries and never will be — they're an auth concern with different lifecycle, different privacy obligations, and a different deletion story (GDPR erasure of a user must not cascade-delete their articles). Use `relation` to a `person` entry type if you need editorial bylines.

---

## 5. Media are entries

**There is no separate media subsystem.** An uploaded file is an entry of a system entry type — `image`, `document`, `video` — carrying its own fields for alt text, caption, credit, rights, expiry, and anything else an org adds.

The bytes live in a companion table:

```
media_files
  id
  entry_id        FK, unique          -- 1:1 with its media entry
  disk            string              -- Flysystem disk
  path            string
  mime            string
  size_bytes      bigint
  checksum        string              -- dedupe + integrity
  width, height   int nullable
  duration_ms     int nullable
  created_at
```

A "media picker" field is therefore just **`relation` constrained to media entry types**. No new storage strategy, no second permission model, no parallel search index.

Three things fall out of this for free, and they're the reason it's worth doing:

1. **The DAM use case is a blueprint, not a subsystem.** Add fields to the `image` type — rights holder, licence expiry, usage notes — and you have digital asset management. That was one of the four use cases in the project's premise, and it costs nothing extra.
2. **Revisions, permissions, audit and tenancy all apply to media automatically**, because media are entries and entries already have all four.
3. **"What uses this image?" is a query on `entry_relations`**, not a full-text search for the filename across every JSON blob.

The trade-off, stated honestly: an `entries` row per asset makes the table bigger than a dedicated media table would, and bulk upload of 10,000 assets writes 10,000 entries plus 10,000 relations. Acceptable — but it's a real number to watch in the Phase 1 storage benchmark.

---

## 6. The two types that need security review

### `rich_text`

User-supplied HTML rendered on public pages. **The only field type in v1 that is an XSS vector.**

- Sanitize on **write** (canonical, cheap) *and* escape on **read** (defence in depth)
- Allowlist tags and attributes; never a denylist
- Sanitizer config lives in core, not in `field_storage.settings` — an org must not be able to widen its own allowlist
- `<script>`, `<style>`, `<iframe>`, event handler attributes and `javascript:` URLs are never permitted, regardless of settings
- Store sanitized. Store the pre-sanitization original **only** in the revision record, never in `entries.values` — implemented as `entry_revisions.unsanitized_values`, a column `snapshot()` does not expose, so a restore cannot reach it. Erasure sweeps it alongside `values`, because it holds the author's original bytes and is personal data like any other value

> **Status, measured 2026-09-09: the write half is implemented.** `AuditedBuilder`'s insert and update paths run every value being written through its field type's `toStorage()`, so `rich_text` is sanitized on the way in — a test asserts the bytes in the column, not the sanitizer's return value. `values` is in `columnsRequiringModelSave()`, so the one write shape that would skip the pipeline is refused rather than trusted.
>
> ⚠️ In the **builder**, not in a `saving` listener. `saveQuietly()`, `createQuietly()`, `updateQuietly()` and `withoutEvents()` suppress model events while still reaching the builder, so a listener would have left every quiet write unsanitized — in `entries.values` and in the revision snapshot alike. A guard has to sit where the write is, and an event is not where the write is.
>
> A bulk write to `values` is refused rather than converted, and that is deliberate: one statement covers rows of many entry types with different field sets, so there is no single correct conversion for it. The conversion is per row because the schema is per row.
>
> **The trap in that bullet, recorded because the implementation had to avoid it.** `Entry::restoreRevision()` writes a revision's `values` back onto the entry. If the revision holds the pre-sanitization original *inside* `values`, restoring it puts unsanitized HTML into `entries.values` — which is the one thing this bullet forbids. So the original has to live somewhere a restore does not read: its own column on `entry_revisions`, not a key in the snapshot. Whoever implements the pipeline should read this bullet as "the original is kept beside the revision", not "inside it".

### `json`

The escape hatch, and escape hatches get abused.

- Size cap, enforced server-side
- Never rendered as HTML without escaping
- Not indexable, not queryable — if you want to query it, you wanted a real field type
- Document it as *"for machine-readable configuration, not content"*, because otherwise it becomes the CMS equivalent of a `misc` column

---

## 7. Indexing

A field is indexed when `field_storage.is_indexed` is true. `Kitsune\Core\Schema\SchemaManager` then adds a stored generated column plus a composite index leading with the scope key — `site_id`, since `entries` is `#[SiteScoped]`. `php artisan kitsune:schema-sync` reconciles the two after a failure, since DDL implicitly commits on MySQL and a row write cannot share a transaction with its schema change.

**The column is named for its projection, not for its owner** — `idx_{handle}__{signature}`, where the signature carries the width wherever the width varies: `idx_price__decimal12_2`, `idx_sku__string64`, `idx_active__boolean` (ADR-028). `entries` is one table shared by every org, so two orgs each defining `price` would otherwise collide: one silently casting the other's data to the wrong type, and either able to drop the other's column. Two rows with the same handle **and** signature generate a byte-identical expression and share the column deliberately; rows that differ in any way that changes the SQL get separate columns. The signature rather than the type handle, because the projection depends on configuration too — a `number` with `format: integer` projects to BIGINT, and through `DECIMAL(12,2)` PostgreSQL refuses the column outright with `numeric field overflow`.

**Verified 2026-09-07** against all three engines (issue #11). The SQL below is what `Kitsune\Core\Schema\Drivers\*` actually emits, and `tests/Core/Schema/GeneratedColumnParityTest.php` runs it on each.

⚠️ **`values` is a reserved word on MySQL and PostgreSQL, so the identifier must be quoted.** Earlier drafts of this document showed it unquoted; that SQL fails as written. Quoting is part of the `SchemaDriver` interface for this reason.

```sql
-- MySQL: $-prefixed path, CAST wrapper, backtick quoting
ALTER TABLE `entries`
  ADD COLUMN `idx_price__decimal12_2` DECIMAL(12,2)
    GENERATED ALWAYS AS (
      CASE WHEN JSON_TYPE(JSON_EXTRACT(`values`, '$.price'))
                IN ('INTEGER', 'UNSIGNED INTEGER', 'DOUBLE', 'DECIMAL')
           THEN CAST(`values`->>'$.price' AS DECIMAL(12,2)) END
    ) STORED;
CREATE INDEX `idx_price__decimal12_2_site_idx` ON `entries` (`site_id`, `idx_price__decimal12_2`);
```

```sql
-- PostgreSQL: bare key, cast suffix, double-quote quoting
ALTER TABLE "entries"
  ADD COLUMN "idx_price__decimal12_2" NUMERIC(12,2)
    GENERATED ALWAYS AS (
      CASE WHEN jsonb_typeof(("values")::jsonb -> 'price') = 'number'
           THEN (("values")::jsonb ->> 'price')::NUMERIC(12,2) END
    ) STORED;
CREATE INDEX "idx_price__decimal12_2_site_idx" ON "entries" ("site_id", "idx_price__decimal12_2");
```

```sql
-- SQLite: json_extract, and VIRTUAL rather than STORED
ALTER TABLE "entries"
  ADD COLUMN "idx_price__decimal12_2" NUMERIC(12,2)
    GENERATED ALWAYS AS (
      CASE WHEN json_type("values", '$.price') IN ('integer', 'real')
           THEN CAST(json_extract("values", '$.price') AS NUMERIC(12,2)) END
    ) VIRTUAL;
CREATE INDEX "idx_price__decimal12_2_site_idx" ON "entries" ("site_id", "idx_price__decimal12_2");
```

### The `CASE` is not defensive style — it is the whole isolation guarantee

`entries` is one table shared by every org, so this expression reads the JSON key from **every row in it**, including rows whose org gave `price` a different type. Without the guard, measured against live engines:

| | another org's `"price": "contact us"` |
|---|---|
| PostgreSQL 17 | `ERROR: invalid input syntax for type numeric` — the column cannot be created |
| MySQL 8.4 | `ERROR 1366: Incorrect DECIMAL value` — the same |
| SQLite | **indexes it as `0`.** A price of zero, answering queries for one |

A value of the wrong JSON type is not this projection's data, so it projects to `NULL` (ADR-028 amendment).

The syntax diverges in five ways — path operator, JSON-type spelling, cast form, identifier quoting, and whether the column can be materialised at all. **That divergence is exactly why a field type describes a `Projection` and never touches SQL.**

Two more divergences the driver owns, each of which made a shipped field type unindexable until the parity suite covered every logical type:

- **MySQL needs two spellings.** `BIGINT` in `ADD COLUMN` and `SIGNED` inside `CAST`, each rejected where the other belongs — so `SchemaDriver` exposes `columnType()` and keeps the cast spelling private. One string for both left every `integer` field unindexable there.
- **PostgreSQL demands an IMMUTABLE expression.** A text-to-`DATE` cast is only STABLE, and there is no immutable alternative (`to_date` is STABLE too). So **`date` and `datetime` project to fixed-width ISO-8601 strings on every engine** — `YYYY-MM-DD` and UTC `…+00:00`, which `toStorage()` already normalises to, making string order exact chronological order.

### Rules

- **Indexing is opt-in.** Every generated column costs write throughput and disk. Default off
- **Cap generated columns on the shared `entries` table** — `SchemaManager::MAX_GENERATED_COLUMNS`, 20 to start, revisit with benchmark data. The cap counts **columns, not `field_storage` rows**: many rows across many orgs can share one column, and the scarce resource is the table. Without a cap, one org can degrade `entries` for everyone, which on KaaS is a noisy-neighbour incident
- **Dropping is reference-counted.** Un-indexing removes the column only once no other field storage row still projects to it. An org must never be able to drop a column another org is querying (ADR-021, ADR-028)
- **Settings that affect storage come from STORAGE, never from the per-type field.** `FieldConfig::setting()` reads `field_storage.settings` only. Presentation merging over storage let an *unlocked* `fields.settings` override a *locked* `field_storage.settings` — a per-type `maxLength: 400` against a column already created as `VARCHAR(255)`, or a per-type `format: integer` changing the conversion under stored decimals — which routes around ADR-006's lock-on-data invariant. A display-only setting uses `presentationSetting()` and says so at the call site
- **Cardinality is handled by the contract, not by each type.** `validationRules()` puts the scalar constraints on `handle.*` when the field is multi-value, `apiSchema()` wraps the item schema in an array, and `toStorage()` maps over the elements. A type describes one value in `scalarValidationRules()` / `scalarApiSchema()` / `castToStorage()`; types that are arrays at every cardinality (`relation`, `multi_select`) override the outer methods instead
- **A projection is a promise about what the field accepts, and validation keeps it.** `number` derives its `DECIMAL(p,s)` from configured `precision`/`scale` and bounds the input to match — unbounded, `10000000000` overflowed the column and `1.234` was rounded inside the index so distinct values compared equal. `select` sizes its projection from the widest configured option key, for the same reason
- **Settings that change the projection lock with the shape.** `is_locked` compares projections rather than naming attributes, so switching `format` from decimal to integer on a field holding data is refused while editing a label is not — and a new projection-affecting setting is covered without anyone remembering to list it
- **An indexed string is capped at 700 characters.** Measured: MySQL caps an index key at 3,072 bytes and utf8mb4 costs four bytes a character, so `VARCHAR(1000)` fails with `ERROR 1071` while `VARCHAR(700)` succeeds. Wider fields are refused with that reason — long text that needs searching wants full-text search, not a scalar projection
- **Field handles are bounded at 32 characters, lowercase snake_case, no doubled underscore.** They become SQL identifiers, PostgreSQL truncates those at 63 bytes, and a truncated identifier is a silent collision rather than an error. `__` is reserved as the separator
- **Adding an index rewrites the table.** On a large `entries` table this locks. Queue it, do it online where the engine supports it, and warn in the UI
- **Marking a field indexed is not reversible for free** — dropping the column is another rewrite
- **Cardinality > 1 is not indexable this way.** A JSON array can't project to a scalar column. Multi-value fields that need querying should be `relation`
- **⚠️ MySQL does not preserve JSON object key order.** PostgreSQL and SQLite return the keys as written; MySQL normalises them. Found by the engine matrix on 2026-09-07, not by reasoning. **Nothing may depend on the key order of `values`** — field order comes from `fields.ordering`, and any test comparing a decoded `values` array must sort first or compare order-insensitively
- **SQLite is a third driver, not a variant of the other two.** It cannot `ALTER TABLE ADD COLUMN` a STORED generated column; it takes a VIRTUAL one, indexed as an expression index. VIRTUAL means the value is computed on read rather than materialized, so the write-throughput and disk costs above do not apply in the same way — and neither does the "adding an index rewrites the table" warning. The SQLite DDL is deliberately not written out here: proving all three drivers behave identically is roadmap Phase 1 work, and this doc should not imply a verification that has not happened

---

## 8. Locking

`field_storage.is_locked` flips true the moment any entry holds data for that field. This is Drupal's guard and it's copied deliberately.

**Locked** — cannot change: `type`, `cardinality`, anything altering the stored shape.
**Never locked** — `label`, `help_text`, `is_required`, `default_value`, `ordering`, `group`, and everything else in `fields` (presentation is per-entity-type and safe to edit).
**Special case** — `is_indexed` can still be toggled on a locked field, since it changes the index rather than the data. It's expensive, not unsafe.

Changing a locked field's type requires an explicit migration: create a new field, run a conversion, verify, drop the old one. The engine should offer this as a guided flow rather than pretending it's an edit — silent type coercion is how content gets destroyed.

---

## 9. Adding a field type — the checklist

Because this should be mechanical:

1. Implement `FieldType`
2. Pick a strategy — `Promoted` is closed, so realistically `Inline` or `Relational`
3. If indexable, return a `Projection` from `projection()` — **describing** the logical type and width, never SQL and never per-driver. The driver renders it, and `LogicalType` is an enum so PHPStan fails an unhandled match: a new logical type cannot silently leave one engine behind
4. *(Not yet applicable — issue #39.)* Name the **kind** of control and cell from the published vocabulary. You will not write a Filament class here and you will not decide text direction: the renderer derives it, which is what stops a new type shipping without it (ADR-029)
5. `toApi()` / `fromApi()` / `apiSchema()` — round-tripping must be lossless, and `apiSchema()` may only publish a constraint that is actually enforced
6. `validationRules()` — `scopedUnique()` / `scopedExists()` only
7. `settingsSchema()` — the field's own configuration form
8. Register in the field type registry
9. **Tests:** round-trip through storage; validation; a **cross-org boundary test**; and if indexable, an index-creation test on all three engines

---

## 10. Privacy classification

Every `field_storage` row carries a `pii_class` — `none | personal | sensitive` — and **a field that has not been classified does not save** (ADR-020). `sensitive` means GDPR Article 9 special-category data.

This is not paperwork. In a runtime schema engine the platform genuinely cannot know whether "Customer Notes" holds personal data, so a right-of-access or erasure request is unanswerable without the declaration. Field-name heuristics don't work — an org's labels may not even be in English.

What the classification drives:

| Class | Effect |
|---|---|
| `none` | Nothing. The default answer for a price, a slug, a publication date |
| `personal` | Included in subject-access export; redacted on erasure, **including in revisions**; retention policy applies |
| `sensitive` | All of the above, plus encryption at rest and stricter default retention |

Field type authors should set a sensible **suggested** class — a `relation` to a `person` type suggests `personal`, a `number` suggests `none` — but the org confirms it, because only the org knows what the field is for.

**Consequences for field type implementations:**

- `toStorage()` / `fromStorage()` must round-trip through encryption transparently for `sensitive` fields
- Every type needs a **redaction** path, not just a delete — erasure replaces a value in place, in the entry *and in every revision*, without destroying the record
- `rich_text` is the awkward one: personal data can be anywhere in a free-text body, so redaction is a content operation rather than a field operation. Treat a `personal`-classified `rich_text` field as requiring manual review during an erasure request, and say so in the UI rather than pretending it is automatic

---

## 11. Open questions

| Question | Why it matters |
|---|---|
| Do revisions store full `values` snapshots or diffs? | Full snapshots are simple and get expensive fast on `rich_text` |
| Should `relation` support polymorphic targets, or one target type per field? | Polymorphic is more flexible and much harder to validate and index |
| Do `relation` fields target the translation group or a specific locale row? | Left open by ADR-017; group-targeting with an optional locale override is the leading candidate. It decides what `entry_relations.target_entry_id` actually points at |
| Default cap on indexed fields per entity type | 20 is a guess. Needs Phase 1 benchmark data |
| Does `multi_select` warrant `Relational` storage instead? | It would make faceted filtering trivial at the cost of a join |
| Should `pii_class` have a suggested default per field type, or force an explicit choice every time? | Suggestions reduce friction; forcing the choice is what makes the guarantee real |
| How is erasure surfaced for `rich_text`, where personal data can be anywhere in the body? | Manual review is the honest answer, but it needs a UI that says so rather than implying automation |

**Translations are settled, and this doc predated the decision.** They are **entry-level, not field-level** (ADR-017, revised by ADR-021): each translation is its own `entries` row linked by `translation_group`, and locale is derived from `sites.locale` rather than stored on the entry.

Field-level translation was not merely rejected but *disqualified*. A locale map inside the JSON — `{"title": {"en": "Hello", "fr": "Bonjour"}}` — cannot be projected to a scalar by a stored generated column, so indexing would need one generated column per indexed field per locale. That collides head-on with the mechanism §7 depends on.

**What a field type author needs to take from this:** `field_storage` *will* carry a `translation_scope` enum — `shared | per_locale`. ⚠️ It is not in the schema yet: it was declared with a default and read by nothing, so it asserted a behaviour nothing honoured, and ADR-017's amendment defers it to the code that reads it (issue #40). A `shared` field is denormalized into every sibling locale row (editable only on the origin), because a shared value must be *physically present* in each row to be indexable. Types whose values are identical across locales — SKU, price, dimensions — are the ones this matters for.
