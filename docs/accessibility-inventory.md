# Kitsune — Accessibility and RTL Inventory

**Answers:** [ADR-018](decision-log.md#adr-018--product-internationalization-is-a-requirement-not-a-nice-to-have)'s open spike, issue #12.
**Created:** 2026-09-08
**Status:** Measured against Filament **v5.7.8**. Every number below came from a command; the commands are named so they can be re-run.

---

## Why this document exists

Pillar three includes **physical** accessibility, and the commitment is real assistive-technology testing rather than a boilerplate conformance claim. ADR-018 asked two questions and left them open: how much WCAG conformance is *inherited* from Filament, and whether v5's RTL **layout** is as complete as its RTL **translations**.

Both had been argued rather than measured. This document is the measurement, and the answer to the second question turned out to be short: **Filament's RTL layout works, and every RTL gap that remains is Kitsune's.**

One correction belongs at the top, because it cost the most. The browser suite claimed an RTL render check "needs the locale switcher that does not exist yet", and that was **wrong**. Filament renders `dir` from `__('filament-panels::layout.direction')`, so the app locale alone decides it:

```bash
APP_LOCALE=ar php artisan serve --port=8137
curl -s localhost:8137/admin/login | grep -o 'dir="[^"]*"'   # dir="rtl"
```

Half of this spike sat behind an imaginary blocker for a day because nobody ran that. It is invariant 15 with a receipt.

---

## 1. How much surface is even ours

The honest starting point, and it reframes everything below:

```bash
find packages/core/src skeleton/app skeleton/resources -name "*.blade.php"              # 1 file
find packages/core/src skeleton/app skeleton/resources \( -name "*.css" -o -name "*.js" \)  # 0 files
```

⚠️ **Named source roots, not `find packages skeleton` minus `vendor`** — which is what this section said first, and it reported 30 CSS/JS files and 2 views rather than 0 and 1. Neither figure was wrong about *authored* code: the 30 are Filament's stylesheet and scripts published into `skeleton/public` by `filament:assets`, and the extra view is a compiled Blade cache in `skeleton/storage`. Both are generated, and an exclusion list that has to name every generated tree rots the moment one is added. Listing the roots where code is written cannot drift the same way. The CSS/JS half was caught in review; the view count had the same defect and was not reported, which is the argument for fixing the command rather than the number.

Kitsune authors **one** Blade view and **zero** lines of CSS or JavaScript. The admin is Filament's markup, Filament's stylesheet and Filament's components, configured through PHP. So today the inherited share of the accessible surface is very close to all of it, and the "must build" column is mostly *future* obligations created by features not yet written — not a backlog of broken markup.

That will change. Every custom Filament component, every published view and every line of project CSS moves surface from the first column to the second, and each one has to carry its own conformance rather than inheriting it.

---

## 2. WCAG — inherited versus must build

### What was measured

`e2e/accessibility.spec.js` runs `@axe-core/playwright` with tags `wcag2a`, `wcag2aa`, `wcag21a`, `wcag21aa` across the five page shapes the admin has — dashboard, entry list, entry create, entry edit, related records. `e2e/rtl.spec.js` runs the same scan again against a server in an RTL locale.

**Result: zero violations at any impact level, on every page shape, in both directions**, plus zero at a 390px mobile viewport under RTL.

The mobile figure has its own scan (`rtl.spec.js`, "no critical or serious WCAG violations at a mobile width under RTL") because the mobile layout is a *different* layout — the sidebar becomes a drawer and the topbar gains a trigger, so it has its own focus order and its own touch-target sizes. ⚠️ It did not, at first: the number was measured in a throwaway probe, the probe was replaced by a spec that sets a mobile viewport and never runs axe, and this document cited a result no listed command reproduced. Caught in review. **A measurement nobody can re-run is the same liability as a claim nobody measured** — invariant 15 pointed the other way round.

That is a real result and it is a *narrow* one. Axe evaluates machine-detectable rules: contrast ratios, label associations, ARIA validity, landmark structure, document language. It cannot evaluate whether a control is *understandable*, whether focus order is *sensible*, whether an error message says what to do, or whether the entry editor can be operated by someone who cannot see it. A green axe run means no detectable failures, not conformance.

### Inherited (verified)

| | Evidence |
|---|---|
| Document language on every admin page | `lang` asserted non-empty; `ar` under the RTL locale |
| No detectable WCAG 2.1 A/AA failures in admin chrome | 5 page shapes × 2 directions, 0 violations |
| Landmark structure, ARIA validity, label association | Axe rule families, all passing |
| Contrast in the default theme | Axe contrast rules, passing |

### Must build

| | Why it cannot be inherited |
|---|---|
| **The screen-reader pass** | Not automatable, and the pillar-three commitment is specifically this. Driving the entry editor with a real reader is the only way to answer "is this usable". Stays open — see §5 |
| **Keyboard operation of the field builder** | The create-field modal is a reactive form whose Settings section is *rebuilt* when the type changes (`SettingsSchemaRenderer`). Rebuilding a subtree moves focus, and axe has no rule for "focus went somewhere useless" |
| **Error messaging on runtime schema** | Field labels are org data (ADR-018), so validation messages are assembled from operator-authored strings. A message that reads correctly in English and nonsensically in translation is a conformance problem no checker sees |
| **Every future custom component** | Anything not inherited from Filament arrives with zero conformance and has to earn it |
| **Any published Filament view** | Publishing a view forks it: upstream accessibility fixes stop arriving |

---

## 3. RTL — inherited versus must build

### What Filament actually ships

```bash
ls vendor/filament/filament/resources/lang/ | wc -l                        # 64
grep -rl "'rtl'" vendor/filament/filament/resources/lang/*/layout.php      # 6
```

Sixty-four locales, and **six** carry `direction => 'rtl'`: `ar`, `ckb`, `fa`, `he`, `ku`, `ur`. ADR-018 said "four major RTL languages" — Sorani (`ckb`) and Kurmanji (`ku`) were missing from the count. `Kitsune::RTL_LANGUAGES` is these six, and deliberately not the wider CLDR set: nothing in this project can verify a direction claim for a language it has no translation for.

### The layout mirrors, and here is the proof

`e2e/rtl.spec.js` renders the admin under `APP_LOCALE=ar` on a second server and compares geometry against the LTR render of the same page. The assertion is a **relation**: an element `n` pixels from the left edge in LTR must sit `n` pixels from the *right* edge in RTL.

At 1280px, across three page shapes, drift was **0 on every landmark** — `.fi-sidebar`, `.fi-main`, `.fi-topbar`, `.fi-sidebar-nav`, `.fi-topbar-end`. The sidebar moves from `[0,320]` to `[960,1280]`; main moves from `[320,1280]` to `[0,960]`.

The mechanism is worth recording, because it explains why it works rather than merely reporting that it does:

```
.fi-sidebar  position: sticky   inset-inline-start: 0px
             → resolves to right: 0px, left: auto under dir="rtl"
```

Logical inset properties, so the browser does the mirroring. Consistent with the sheet as a whole: 535 logical to 139 physical direction-sensitive declarations, about 79% direction-agnostic.

**The mirror test can fail, which is the point.** Forcing `direction: ltr` while leaving `dir="rtl"` — precisely the failure ADR-018 describes, RTL translations shipped over an unmirrored layout — moves the drift from 0 to **1216**. The previous RTL check could not fail: it visited the *English* site and asserted `dir="ltr"`.

### Inherited (verified)

| | Evidence |
|---|---|
| `dir` on `<html>` from the app locale | `dir="rtl"`, `lang="ar"`, all five page shapes |
| Admin layout mirrors exactly | Drift 0 on 5 landmarks × 3 page shapes at 1280px |
| No horizontal overflow under RTL | `scrollWidth <= clientWidth`, all five shapes |
| Off-canvas drawer parks on the correct side | At 390px the sidebar sits at `left: 406` — beyond the right edge, mirroring LTR's negative offset |
| No new WCAG failures introduced by mirroring | Axe re-run under RTL, 0 violations |
| An unknown locale degrades safely | `APP_LOCALE=xx` yields `dir="ltr"`: Laravel resolves the fallback locale before returning a missing key, so Filament's `?? 'ltr'` is dead code rather than a latent bug. Checked because it looked like one |

### Must build — the gaps, enumerated

**G1 — Kitsune's own output had no direction. FIXED in this spike.**
`skeleton/resources/views/welcome.blade.php` emitted `lang` from the app locale and no `dir` at all, so an Arabic locale served Arabic text in a left-to-right document. Filament supplies `dir` for the admin from its own translations; the public side has no panel and must not depend on one (ADR-002 keeps core headless-capable), so nothing was going to supply it. `Kitsune::textDirection()` now does.

**G2 — `sites.locale` exists and is applied by nothing.**
```bash
grep -rn "setLocale" packages/core/src skeleton/app    # no matches
```
The column is there with a default, and `golfdom-fr` is seeded with one, but no middleware maps a site's locale onto `app()->setLocale()`. Two consequences: an operator cannot reach RTL at all without editing `APP_LOCALE`, and because direction is resolved per *process*, **a multi-site install cannot serve one site RTL and another LTR concurrently**. That is the accurate version of the "locale switcher" claim — a narrower and more actionable statement than the blocker it was written as. Note ADR-018 rule 2: the **UI** locale is a user preference, not a site setting, so this is two mappings and not one, and they can disagree.

**G3 — no per-field content direction, and Filament will not supply it. ◐ Partly resolved.**
```bash
grep -rn 'dir=' vendor/filament/*/resources/views | wc -l    # 1
```
Exactly one `dir` in the entire view layer: the root `<html>`. No input, textarea or table cell carries `dir="auto"`. So **content always renders in the direction of the chrome.** An Arabic value in an English admin — or an English value in an Arabic one — gets its punctuation, parentheses and numerals laid out the wrong way. This is not hypothetical for Kitsune: ADR-018 rule 2 exists because one org has editors working in different languages, and the seed fixture already models a bilingual org. `dir="auto"` on field inputs and table cells is Kitsune's to add.

**What is done (issue #39):** every entry value the admin renders today carries `dir="auto"` — the title and slug inputs, and **all three** title columns: the entry list, the related-records table and the revisions list — so each resolves on its own first strong directional character rather than on the panel's. A browser test measures the **rendered** direction with `getComputedStyle().direction` rather than asserting the attribute, because `dir="auto"` can be present and resolve the wrong way; that is the mistake the original RTL check made. The seed now carries an Arabic-titled entry in the otherwise-Latin `golfdom` org, because the failure only appears with bidirectional content in one admin and a test without it would pass by asserting about LTR text in an LTR panel, and it is attached to another entry so the related-records page has something bidirectional to render.

⚠️ Review found the first pass short by two. `EntryResource`'s table was fixed and the related-records and revisions tables each define their **own** `TextColumn::make('title')`, so they still inherited the panel's direction — a screen is only as complete as the enumeration behind it. The fix came from grepping every `TextColumn::make('title')` in `packages/core/src`, not from patching the one that was reported.

⚠️ **What is not done, and why it is not merely unfinished:** the per-field UI does not exist yet. `EntryResource` renders `title`, `slug` and `status` directly; `FieldType::formComponent()` and `tableColumn()` are on the contract in `field-types.md` and implemented by nothing, so there is no textarea, rich-text editor, select or relation picker to attach `dir` to. When that UI lands, the attribute has to land with it — attaching it afterwards means auditing every component, which is the argument ADR-018 makes for the no-bare-strings rule.

Rich text is ready at the storage layer: `dir` is already in `RichTextType::ALLOWED_ATTRIBUTES`, so per-block direction survives sanitising — verified, `<p dir="rtl">…</p><p dir="ltr">…</p>` round-trips while `style` and `onclick` do not. What cannot be demonstrated yet is per-block direction **rendering**, because nothing renders rich text.


**G4 — `field_storage.translation_scope` was declared and unread. ✅ Resolved.**

Default `per_locale`, read by nothing: the column asserted a capability that did not exist. Dropped under issue #40, with ADR-017 amended to record that it arrives with the code that reads it. Not made fail-closed, because unlike `pii_class` there was no consumer to fail closed for — a guard protecting nothing is still a promise.

```bash
grep -rl "translation_scope" packages/core/src        # nothing — no reader
grep -rl "translation_scope" packages/core/database   # the migration, a comment saying why it is absent
grep -rl "translation_scope" tests                    # EntrySchemaTest, asserting the absence
```

⚠️ The three commands are separated **because one command cannot honestly answer this**. The original entry recorded `grep -rn "translation_scope" packages/core/src tests # no matches`, and the regression test added in the same commit made that output false — a recorded result that stops being true is worse than no evidence, and this one was falsified by its own change. Distinguishing *reader* from *reference* is the point: the two matches that exist are a comment explaining the absence and a test defending it, and neither is a consumer.

**G5 — the RTL language list is limited to what was measured.**
Six languages. A site publishing in Divehi, Pashto, Sindhi, Uyghur or Yiddish renders LTR. This is a deliberate, recorded limitation rather than an oversight (see `Kitsune::RTL_LANGUAGES`), and extending it means adding a translation, not just a string.

Direction resolves from an explicit **script** subtag when the locale carries one, because direction is a property of the script rather than the language and two of the six are written in more than one: `ku-Latn` and `ckb-Latn` are Latin and must render LTR, while `ku-IQ` — a region, not a script — stays RTL. Reading the language subtag alone got both Kurdish cases wrong, which was caught in review. The recognised RTL scripts are `Arab`, `Aran` and `Hebr`; any other explicit script is treated as LTR, the same fail-safe direction an unknown locale takes.

**G6 — the mirror test covers landmarks, not every element.**
It compares five structural elements, so it catches direction failing at the document level. A single physical offset on some inner component would slip past it; the no-overflow assertion and the logical-versus-physical CSS ratio are the backstops, and neither is exhaustive. Stated because a test's coverage is part of its result.

**G7 — RTL with an actual reader is unmeasured.**
Mirrored geometry is not the same as comprehensible reading order. This folds into §5.

---

## 4. What runs in CI

| Job | Covers |
|---|---|
| `e2e/accessibility.spec.js` | Axe WCAG 2.1 A/AA on five page shapes, LTR; document language; logical-vs-physical CSS ratio as a regression guard |
| `e2e/rtl.spec.js` (`admin-rtl` project) | `dir`/`lang`, axe under RTL on five page shapes **and again at 390px**, no overflow, **the mirror assertion**, mobile drawer side, and Kitsune's own public page |
| `tests/Core/TextDirectionTest.php` | `Kitsune::textDirection()` across the six RTL languages, LTR languages, `ar_EG`/`ar-EG`/`AR` subtag forms, and the unknown-locale fallback |

The RTL project needs a second server, which `playwright.config.js` starts itself — so CI needs no orchestration beyond what the browser job already does, and invariant 11 is untouched: none of this reaches the bare-clone Pest suite except the direction unit test, which needs nothing.

---

## 5. What a human still has to do

Automation answered question 2 of ADR-018 and only part of question 1. What remains is genuinely manual and is **not** a CI job:

1. **Drive the entry editor with a screen reader** — NVDA on Windows and VoiceOver on macOS at minimum. Create an entry, fill a rich-text field, attach a relation, save, and recover from a validation error. Record what is unusable rather than what is imperfect.
2. **Drive it with the keyboard only**, including the create-field modal whose Settings section rebuilds on type change.
3. **Have someone who reads Arabic or Hebrew use the admin.** Mirrored geometry is measurable; whether the interface *reads* is not.

Until those happen, the honest claim is the one at the top of this document: no detectable failures, on a surface that is almost entirely inherited. That is a good starting position and it is not a conformance statement, and ADR-018 is explicit that a boilerplate conformance claim is exactly what this project will not make.
