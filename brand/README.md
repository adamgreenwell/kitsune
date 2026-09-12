# Kitsune brand

**These files are not covered by the MPL.** See [`LICENSE.md`](LICENSE.md) in this directory. The code is yours to use; the name and the mark are not (ADR-005).

**Status: provisional.** The mark is adopted for use and not for registration. ADR-032 has the reasoning and the bar for dropping "provisional."

---

## The system

A kitsune's tails are its counting unit — nine is the mature form. The ladder is built on that, so the smallest mark is **a couple of units of the largest**, not a shrunken copy of it.

| Mark | Composition | Used at | Used for |
|---|---|---|---|
| **Formal** | Nine-tail fox + wordmark | ≥128px | README, site header, social card, print, conference |
| **Compact** | Nine-tail fox, no wordmark | 64–128px | App icon, admin sidebar, avatar |
| **Glyph** | Two tails | ≤48px | Favicon, tab strip, maskable icon |

The glyph is **two tails**, decided 2026-09-12 by render rather than by argument — the mirrored pair holds structure at 16px that a single diagonal stroke does not. Two-tailed kitsune are a stage in the folklore, so the count is still a count and never a lesser fox.

Never a one-tailed *fox*. That form is both the most generic image in software and, in the folklore, a juvenile kitsune. The ladder drops the wordmark, then drops to the unit — it never depicts a lesser fox.

## Asset manifest

Nothing here is built yet. This is what to export while the vector source is open.

### Formal
- [ ] `formal-horizontal-light.svg` — mark left, wordmark right
- [ ] `formal-horizontal-dark.svg`
- [ ] `formal-stacked-light.svg` — mark above wordmark
- [ ] `formal-stacked-dark.svg`
- [ ] `formal-mono.svg` — single colour, flat, no tints

### Compact
- [ ] `compact-light.svg`, `compact-dark.svg`, `compact-mono.svg`
- [ ] `compact-128.png`, `compact-256.png`, `compact-512.png`

### Glyph
- [x] `kitsune-glyph.svg` — square, centred, namespaced
- [ ] `glyph-dark.svg`, `glyph-mono.svg`
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
| `kitsune-fox.svg` | `0 0 1034 785` | `kt-fox-` | Nine-tail fox alone |
| `kitsune-one-tail.svg` | `0 0 331 337` | `kt-onetail-` | Single-tail glyph. Not adopted; kept as provenance |
| `kitsune-two-tails.svg` | `0 0 458 329` | `kt-twotails-` | Two-tail glyph as drawn, landscape |
| **`kitsune-glyph.svg`** | **`0 0 451 451`** | **`kt-glyph-`** | **The glyph. Square-boxed from the above. This is the one to use** |

Every viewBox starts at `0 0`, every id is namespaced, and all 94 ids across the five files are unique — verified, so any combination can be inlined in one document without breaking `aria-labelledby` or cross-wiring a mirror transform.

**Each file carries 20px of built-in inset** — about 2% of the box — uniform on all four sides. Do not double-pad it. The one exception is `kitsune-logo.svg`, whose bottom inset is 14px rather than 20; cosmetic, and worth evening up next time the wordmark is touched.

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

### Dark-ground wordmark

The teal wordmark cannot be used on a dark ground. `#2E96A4` is the lightest-clearing minimum at **5.42:1** on `#0D1117`, and it only reaches 3.49:1 back on white — so **the wordmark needs two colours, one per ground.** There is no single hue that serves both.

### Grounds the mark may not sit on

- **Brand teal `#00545D`.** The legs and paws are teal and vanish at 1.00:1; the rust silhouette edge is 1.67:1. Verified by render — the fox appears to have no legs.
- Anything that puts the rust silhouette below 3:1. Mid grey `#8A8A8A` is fine; darker greys are not.

## Open defects

Measured 2026-09-12 by rendering at size on white, `#FAFAFA`, `#0D1117`, mid grey, brand teal and brand rust.

- [ ] **No horizontal lockup.** Still the biggest gap — stacked cannot sit in a site header, a README banner, or a 1200×630 card.
- [ ] **Dark-ground variants.** The wordmark at `#2E96A4` or lighter; the legs recoloured off teal wherever the ground is dark.
- [ ] **Hand-tune the 16px cut.** The two-tail glyph survives 16px — the chevrons nearly close but the internal split holds — so this is a polish pass on the raster export, not a redraw. The single-tail version needed more.
- [ ] **No monochrome variant.**

### Closed

- ~~Duplicate element IDs.~~ **Done** — namespaced per file, 94 ids, zero collisions, verified by inlining all five together.
- ~~Non-zero viewBox origins.~~ **Done** — all normalised to `0 0 W H` by translating the content. Ink bounds and per-side inset are unchanged on every file, and before/after renders are identical.

- ~~The two-tail glyph is landscape and needs a square re-box.~~ **Done** — `kitsune-glyph.svg`, ink bounds measured at 418×289, boxed square at 451 with the content translated and IDs namespaced.
- ~~Cream tail tips die on white.~~ **Disproven.** The white shapes are fully inset within the rust and read as notches on any ground. Predicted from the raster comp; the vector does not have the problem.
- ~~The keyline haloes on dark.~~ **There is no keyline.** The comp appeared to have one; the vector has no stroke anywhere.
- ~~The wordmark is unoutlined.~~ **Already outlined** as paths. No typeface licence question arises.

### Minimum sizes, measured

| Mark | Holds down to | Fails at |
|---|---|---|
| Formal lockup, stacked | ~140px | 90px — wordmark unreadable |
| Compact fox | ~64px | 32px — becomes an orange smudge |
| Glyph (two tails) | 16px | — verified in a browser-tab mock at true 16px |
| One-tail glyph | ~24px | 16px — single diagonal stroke loses its tip. Not adopted |

## Using the mark

- **Alt text** is `Kitsune` for the formal lockup and empty (`alt=""`) wherever the mark is decorative beside the word "Kitsune" already in text. Never `Kitsune logo` — screen readers announce the role already.
- **The mark is never the sole carrier of meaning.** Anything a user must act on gets a text label too.
- **Clear space** is the height of the wordmark's cap on all four sides.
- **Do not** recolour, rotate, stretch, add effects, or place the mark on a ground that drops it below 3:1.
- **A user's own site logo is not this logo.** `site_group.settings.logo` (ADR-022) is operator content and has nothing to do with these files.
