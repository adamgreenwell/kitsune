# Kitsune brand

**These files are not covered by the MPL.** See [`LICENSE.md`](LICENSE.md) in this directory. The code is yours to use; the name and the mark are not (ADR-005).

**Status: provisional.** The mark is adopted for use and not for registration. ADR-032 has the reasoning and the bar for dropping "provisional."

---

## The system

A kitsune's tails are its counting unit — nine is the mature form. The ladder is built on that, so the smallest mark is **one unit of the largest**, not a shrunken copy of it.

| Mark | Composition | Used at | Used for |
|---|---|---|---|
| **Formal** | Nine-tail fox + wordmark | ≥128px | README, site header, social card, print, conference |
| **Compact** | Nine-tail fox, no wordmark | 48–128px | App icon, admin sidebar, avatar |
| **Glyph** | One tail | ≤32px | Favicon, tab strip, maskable icon |

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
- [ ] `glyph-light.svg`, `glyph-dark.svg`, `glyph-mono.svg`
- [ ] `favicon.ico` — 16 / 32 / 48 multi-resolution
- [ ] `apple-touch-icon.png` — 180×180, no transparency, no rounding (iOS masks it)
- [ ] `maskable-512.png` — glyph inside the inner 80% safe area; anything outside gets cropped
- [ ] `glyph-16.png`, `glyph-32.png`, `glyph-48.png`

### Social
- [ ] `og-card.png` — 1200×630, formal horizontal on a solid ground

## Resolve these in the source

Five defects in the comp that are cheap to fix in vector and expensive to fix later:

1. **The cream tail tips die on white.** They are separated from a white page by a keyline alone, so on the README and on GitHub the tails read as notched rather than tipped. Either darken the tip, weight the keyline, or accept that the mark is never placed on pure white.
2. **The white keyline haloes on dark.** Commit to it as a deliberate sticker treatment that works on any ground, or drop it and cut the dark variants properly.
3. **The glyph tip needs its own treatment.** A cream chevron notched out of a small shape is a chipped blob at 16px. Solid contrasting tip, or no tip below 24px.
4. **There is no horizontal lockup.** Stacked cannot sit in a site header, a README banner, or a 1200×630 card — three of the first four places the mark appears.
5. **Outline the wordmark**, and confirm the typeface is licensed for trademark use before doing so. If the letterforms came out of a raster comp they correspond to no real font and must be drawn.

## Palette

⚠️ **Unmeasured.** Sample from the vector once it exists; do not transcribe from the comp. Invariant 15.

| Role | Hex | Must clear |
|---|---|---|
| Rust | `TBD` | 3:1 on ground — **never body text** |
| Amber | `TBD` | Decorative only; assume it clears nothing |
| Teal | `TBD` | 4.5:1 on white — this is the text colour |
| Cream | `TBD` | 3:1 against rust, or the tail tips fail |

Rust on white is expected to land near the 4.5:1 boundary. Whichever side it falls on is a measurement, not a preference, and it decides whether rust may ever carry text.

## Using the mark

- **Alt text** is `Kitsune` for the formal lockup and empty (`alt=""`) wherever the mark is decorative beside the word "Kitsune" already in text. Never `Kitsune logo` — screen readers announce the role already.
- **The mark is never the sole carrier of meaning.** Anything a user must act on gets a text label too.
- **Clear space** is the height of the wordmark's cap on all four sides.
- **Do not** recolour, rotate, stretch, add effects, or place the mark on a ground that drops it below 3:1.
- **A user's own site logo is not this logo.** `site_group.settings.logo` (ADR-022) is operator content and has nothing to do with these files.
