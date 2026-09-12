# Kitsune brand

**These files are not covered by the MPL.** See [`LICENSE.md`](LICENSE.md) in this directory. The code is yours to use; the name and the mark are not (ADR-005).

**Status: provisional.** The mark is adopted for use and not for registration. ADR-032 has the reasoning and the bar for dropping "provisional."

---

## The system

A kitsune's tails are its counting unit — nine is the mature form. The ladder is built on that, so the smallest mark is **a couple of units of the largest**, not a shrunken copy of it.

| Mark | Composition | Used at | Used for |
|---|---|---|---|
| **Formal, stacked** | Nine-tail fox + wordmark | ≥140px wide | Print, social card, conference, anywhere square-ish |
| **Formal, horizontal** | Nine-tail fox + wordmark | ≥70px tall | README banner, wide headers, docs masthead |
| **Compact lockup** | Glyph + wordmark | ≥24px tall | Site header, nav bar — anywhere the fox would turn to mud |
| **Compact** | Nine-tail fox, no wordmark | 64–128px | App icon, admin sidebar, avatar |
| **Glyph** | Two tails | ≤48px | Favicon, tab strip, maskable icon |

**The horizontal lockup has a floor of about 70px tall, and it is set by the fox, not the type.** Below that the nine tails collapse into an orange smudge while the wordmark is still perfectly legible — so the compact lockup exists to cover 24–70px, where a site header actually lives. Verified by rendering both at 90, 70, 52, 36 and 28px.

The glyph is **two tails**, decided 2026-09-12 by render rather than by argument — the mirrored pair holds structure at 16px that a single diagonal stroke does not. Two-tailed kitsune are a stage in the folklore, so the count is still a count and never a lesser fox.

Never a one-tailed *fox*. That form is both the most generic image in software and, in the folklore, a juvenile kitsune. The ladder drops the wordmark, then drops to the unit — it never depicts a lesser fox.

## Asset manifest

Nothing here is built yet. This is what to export while the vector source is open.

### Formal
- [x] `kitsune-lockup.svg` — mark left, wordmark right
- [x] `kitsune-lockup-dark.svg`
- [x] `kitsune-lockup-compact.svg` / `-dark.svg` — glyph + wordmark, for ≤70px
- [x] `kitsune-logo.svg` — mark above wordmark
- [x] `kitsune-logo-dark.svg`
- [x] `kitsune-logo-mono.svg` — one ink, knockouts, `currentColor`

### Compact
- [x] `kitsune-fox.svg`, `kitsune-fox-dark.svg`
- [x] `kitsune-fox-mono.svg`
- [ ] `compact-128.png`, `compact-256.png`, `compact-512.png`

### Glyph
- [x] `kitsune-glyph.svg` — square, centred, namespaced
- [x] `kitsune-glyph-mono.svg` (the glyph has no teal, so it needs no dark variant)
- [ ] `favicon.ico` — 16 / 32 / 48 multi-resolution
- [ ] `apple-touch-icon.png` — 180×180, no transparency, no rounding (iOS masks it)
- [ ] `maskable-512.png` — glyph inside the inner 80% safe area; anything outside gets cropped
- [ ] `glyph-16.png`, `glyph-32.png`, `glyph-48.png`

### Social
- [ ] `og-card.png` — 1200×630, formal horizontal on a solid ground

## Source

Vector, in [`source/`](source/). Colours are hardcoded hex; there is no keyline and no stroke anywhere.

| File | viewBox | id prefix | Is |
|---|---|---|---|
| `kitsune-logo.svg` | `0 0 1034 940` | `kt-logo-` | Nine-tail fox + wordmark, stacked |
| `kitsune-logo-dark.svg` | `0 0 1034 940` | `kt-logod-` | Stacked, dark ground |
| `kitsune-fox.svg` | `0 0 1034 785` | `kt-fox-` | Nine-tail fox alone |
| `kitsune-fox-dark.svg` | `0 0 1034 785` | `kt-foxd-` | Nine-tail fox, dark ground |
| `kitsune-logo-mono.svg` | `0 0 1034 940` | `kt-logom-` | Stacked, one ink, any ground |
| `kitsune-fox-mono.svg` | `0 0 1034 785` | `kt-foxm-` | Fox, one ink, any ground |
| `kitsune-glyph-mono.svg` | `0 0 451 451` | `kt-glym-` | Glyph, one ink, any ground |
| `kitsune-glyph.svg` | `0 0 451 451` | `kt-glyph-` | The glyph — two tails, square-boxed |
| `kitsune-lockup.svg` | `0 0 2245 783` | `kt-lockup-` | Horizontal lockup, light ground |
| `kitsune-lockup-dark.svg` | `0 0 2245 783` | `kt-lockupd-` | Horizontal lockup, dark ground |
| `kitsune-lockup-compact.svg` | `0 0 1418 329` | `kt-lkc-` | Glyph + wordmark, light ground |
| `kitsune-lockup-compact-dark.svg` | `0 0 1418 329` | `kt-lkcd-` | Glyph + wordmark, dark ground |

Every viewBox starts at `0 0`, every id is namespaced, and all 275 ids across the twelve files are unique — verified, so any combination can be inlined in one document without breaking `aria-labelledby` or cross-wiring a mirror transform.

The single-tail and unboxed two-tail drafts were deleted on 2026-09-12 once the glyph superseded them; the glyph carries its own copy of the path data and depends on neither.

**The lockup and fox carry 20px of built-in inset** — about 2% of the box — uniform on all four sides. Do not double-pad it. The one exception is `kitsune-logo.svg`, whose bottom inset is 14px rather than 20; cosmetic, and worth evening up next time the wordmark is touched.

## Palette — measured

Sampled from the vector, not transcribed. Ratios are WCAG 2.x.

| Role | Hex | On white | On `#0D1117` |
|---|---|---|---|
| Rust | `#BB481F` | **5.17** — AA, may carry body text | 3.66 — UI only |
| Amber | `#D79A50` | 2.43 — decorative only | **7.77** — AAA |
| Teal | `#00545D` | **8.66** — AAA | 2.19 — **fails** |
| White | `#FFFFFF` | — | 18.92 — AAA |

**Rust clears AA on white at 5.17:1 and may be used for body text.** This was predicted to fail and does not.

Inside the mark, only two pairs hold a 3:1 edge — rust/white at 5.17 and teal/white at 8.66. Everything else is below it: rust/amber 2.13, amber/white 2.43, **teal/rust 1.67**. The silhouette and the white markings do all the structural work; the amber is tonal and contributes nothing at small size. This is why face detail is the first thing to die as the mark shrinks — the eyes and nose are teal on rust.

### Dark grounds need two teals, and not the same one

The teal wordmark cannot be used on a dark ground. **The dark-ground wordmark is `#4FB3C0`.**

`#2E96A4` was the first pick, on the strength of 5.42:1 against GitHub's `#0D1117`. Testing it against a wider set of dark grounds killed it: on slate `#1E293B` it measures **4.19:1** and misses AA. `#4FB3C0` clears AA on every dark ground tried — 7.70 on `#0D1117`, 5.95 on slate, 6.47 on `#222` — and the only ground it fails is brand teal, which the mark may not sit on anyway.

Neither serves both grounds: `#4FB3C0` is 2.46:1 back on white. **The wordmark needs two colours, one per ground**, and that is what the lockup files ship.

**The fox's teal is a different problem with a different answer: `#008493`.** The wordmark has one neighbour — the ground — so it can be tuned for the ground alone. The fox's teal has two, and they do not move together: the forelegs sit on the **white chest**, which stays white on any ground, while the paws and the forelegs' last 21px sit on the **ground itself**. So the fox's teal has to clear 3:1 against white *and* against a dark ground simultaneously.

That window is narrow but real — relative luminance between about 0.12 and 0.30 — and `#008493` sits at its balance point: **4.45:1 on white, 4.26:1 on `#0D1117`, 3.29:1 on slate.** `#4FB3C0` would fix the paws and break the forelegs, dropping the chest edge to 2.46:1.

| | Light ground | Dark ground |
|---|---|---|
| Wordmark | `#00545D` | `#4FB3C0` |
| Fox — ears, eyes, nose, legs, paws | `#00545D` | `#008493` |

The two dark values differ because the elements have different neighbours, not because they were picked by eye. The teal-on-rust edge is weak either way — 1.67:1 light, 1.16:1 dark — and always was; the eyes and ears are legible because the white face markings frame them, not because the teal contrasts with the rust.

### Monochrome

Three files, one ink each, and **no colour hardcoded anywhere** — every shape is `currentColor`.

**The light areas are real holes, not white paint.** The white shapes in the colour mark are inset inside the rust, so they reduce cleanly to knockouts: the mono files carry an SVG `<mask>` that cuts them out, and the ground shows through. That is what makes it a genuine one-ink mark — it prints on coloured stock, embroiders, engraves, and stamps without a second plate.

The teal elements are painted *over* the mask rather than through it, because in the colour mark they sit on top of the white chest and the face markings. Knocking them out with everything else would have removed the legs from the chest and the eyes from the face.

**Inline it and set `color`** — one file then serves every ground:

```html
<span style="color: #00545D">  <!-- mark renders teal -->
```

Referenced with `<img src="…">` it cannot inherit and falls back to black, which is the right default for print.

**Monochrome is the only variant permitted on brand teal.** White ink on `#00545D` is the sanctioned way to put the mark on its own colour; the colour mark still may not go there.

Mono inherits the colour mark's floors — the fox is a blob below about 64px, the glyph holds to 16px.

### Grounds the mark may not sit on

- **Brand teal `#00545D`.** The legs and paws are teal and vanish at 1.00:1; the rust silhouette edge is 1.67:1. Verified by render — the fox appears to have no legs.
- Anything that puts the rust silhouette below 3:1. Mid grey `#8A8A8A` is fine; darker greys are not.

## Open defects

Measured 2026-09-12 by rendering at size on white, `#FAFAFA`, `#0D1117`, mid grey, brand teal and brand rust.

- [ ] **Hand-tune the 16px cut.** The two-tail glyph survives 16px — the chevrons nearly close but the internal split holds — so this is a polish pass on the raster export, not a redraw. The single-tail version needed more.

### Closed

- ~~No monochrome variant.~~ **Done** — three files, mask-based knockouts so the light areas are real holes, and `currentColor` throughout so one file serves any ink on any ground.

- ~~No horizontal lockup.~~ **Done** — `kitsune-lockup.svg`, wordmark at 30% of mark height, gap at 10% of mark width, wordmark centred on the mark's bounding box. Three size ratios and four gap/alignment pairs were rendered before picking; 22% read as a labelled fox and 38% as type with an ornament.
- ~~Dark-ground wordmark.~~ **Done**, at `#4FB3C0` rather than the `#2E96A4` first proposed.
- ~~The fox's legs on dark grounds.~~ **Done**, at `#008493` — chosen from the three colours on the brand hue that clear 3:1 against white and a dark ground at once, and rendered against `#0D1117` and slate before picking.

- ~~Duplicate element IDs.~~ **Done** — namespaced per file, zero collisions, verified by inlining every file together.
- ~~Non-zero viewBox origins.~~ **Done** — all normalised to `0 0 W H` by translating the content. Ink bounds and per-side inset are unchanged on every file, and before/after renders are identical.

- ~~The two-tail glyph is landscape and needs a square re-box.~~ **Done** — `kitsune-glyph.svg`, ink bounds measured at 418×289, boxed square at 451 with the content translated and IDs namespaced.
- ~~Cream tail tips die on white.~~ **Disproven.** The white shapes are fully inset within the rust and read as notches on any ground. Predicted from the raster comp; the vector does not have the problem.
- ~~The keyline haloes on dark.~~ **There is no keyline.** The comp appeared to have one; the vector has no stroke anywhere.
- ~~The wordmark is unoutlined.~~ **Already outlined** as paths. No typeface licence question arises.

### Minimum sizes, measured

| Mark | Holds down to | Fails at |
|---|---|---|
| Formal lockup, stacked | ~140px wide | 90px — wordmark unreadable |
| Formal lockup, horizontal | ~70px tall | 52px — fox degrades; 36px it is a smudge |
| Compact lockup | ~24px tall | — glyph and wordmark both hold at 28px |
| Mono fox | ~64px | 48px — same floor as the colour fox |
| Mono glyph | 16px | — knockouts survive; same as the colour glyph |
| Compact fox | ~64px | 32px — becomes an orange smudge |
| Glyph (two tails) | 16px | — verified in a browser-tab mock at true 16px |
| One tail, for comparison | ~24px | 16px — a single diagonal stroke loses its tip. Measured, then dropped |

## Using the mark

- **Alt text** is `Kitsune` for the formal lockup and empty (`alt=""`) wherever the mark is decorative beside the word "Kitsune" already in text. Never `Kitsune logo` — screen readers announce the role already.
- **The mark is never the sole carrier of meaning.** Anything a user must act on gets a text label too.
- **Clear space** is the height of the wordmark's cap on all four sides.
- **Do not** recolour, rotate, stretch, add effects, or place the mark on a ground that drops it below 3:1.
- **A user's own site logo is not this logo.** `site_group.settings.logo` (ADR-022) is operator content and has nothing to do with these files.
