# The Visual Harness — screenshot regression for venue edits (P3.5)

**Status:** shipped in Iteration 8 "The Salon" (roadmap P3.5). Tooling for the
environment where the app runs (PHP + built front-end + Chromium); the build
sandbox has neither, so this harness ships verified by `node --check` and by
contract tests, not by a live sweep — the same honesty rule as the PHP test
suite (run it in your environment; the manual task list carries the step).

## The one idea

Every venue's composition is **fully determined** by its config (Iteration 0
seeded-PRNG contract). Therefore "the render changed" is always either an
**intended edit** or a **regression** — and a pixel diff is how you tell them
apart in seconds instead of by eyeballing twelve previews. The harness is the
safety net the roadmap promised for exactly the moment this iteration
creates: a twelfth venue that was born entirely through config edits.

## What it automates

For each active, published venue (or the `--venue=` subset):

1. Opens `/venues/{slug}/preview` — the same runtime, payload pipeline and
   sample exhibition a customer's chooser test walks.
2. Emulates **reduced motion**, so the Iteration 4 arrival performs its
   instant composed cut — a deterministic first frame with no tween-timing
   race. (This is the framing the tier matrix and lineup stills use.)
3. Waits for the entrance curtain's load-complete signal (`#enter-btn`
   gains `pointer-events: auto`), clicks through, lets the frame settle.
4. Screenshots the canvas at a fixed 1280×720 viewport.
5. Pixel-diffs against `baselines/{slug}.png` (pixelmatch, small per-pixel
   threshold, ≤1% mismatch budget) and exits non-zero on drift, writing
   `{slug}.diff.png` so the failing venue shows you WHERE it changed.

## Setup (once, in the Laravel repo root)

```bash
npm i -D playwright pixelmatch pngjs
npx playwright install chromium
vite build                      # the preview loads the built bundle
php artisan serve               # or your local domain
```

## Daily use

```bash
# Re-baseline everything (after an INTENDED visual change):
node scripts/visual-harness.mjs --base-url=http://127.0.0.1:8000 --update

# Regression check (the default; e.g. in CI or before a deploy):
node scripts/visual-harness.mjs --base-url=http://127.0.0.1:8000

# Just the venues you touched:
node scripts/visual-harness.mjs --base-url=http://127.0.0.1:8000 \
    --venue=the-salon --venue=zen-gallery
```

Commit baseline updates **in the same PR as the edit that caused them** —
the baseline diff IS the visual review artifact for a venue edit.

## Reading a failure

| Signal | Meaning | Action |
|---|---|---|
| One venue drifts right after you edited it | Intended | `--update` that venue; commit new baseline with the edit |
| One venue drifts, nobody touched it | Regression | Open `{slug}.diff.png`; bisect the runtime change |
| Many venues drift after a runtime change | Global regression | Fix, or consciously re-baseline all and justify in the PR |
| Screenshots error (no canvas) | The preview is broken | The chooser test is failing loudly — fix before shipping |

## Determinism notes (what this harness may rely on)

- Seeded PRNG (Iteration 0): identical two loads, per venue, per tier.
- Sample exhibitions are config-fixed and served from the same URLs, so the
  hang is stable across runs.
- Reduced-motion arrival = instant cut (Iteration 4 contract), removing the
  only time-based element of the first frame.
- Tolerance: headless rasterizers vary slightly across machines/GPUs; the
  1% mismatch budget absorbs rasterizer noise but not layout, material,
  palette or identity drift. Same-machine runs are effectively exact.

## Relation to the manual gates (§13)

The harness automates the *evidence collection* for the lineup still and the
tier matrix; it does not replace the felt-experience walk (the promise walk
remains a human task — words must still match the *experience*, not just the
pixels). Record harness output in the iteration's test notes next to the
manual gate results, as Iteration 8 did for The Salon's twelve-venue sweep.
