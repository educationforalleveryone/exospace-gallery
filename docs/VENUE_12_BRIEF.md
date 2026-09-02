# Venue #12 Brief — the catalog's next growth decision

**Status:** BUILT — Iteration 8 "The Salon" (roadmap P3.2). The pre-committed rule below fired its **no-data branch** (the build environment holds no production view data; `venues:catalog-report` on production is the instrument). The Salon shipped as the twelfth venue; the decision record in §7 is completed. If production data later crosses the ≥50% branch, the Grand Hall (§4 candidate B) is pre-staged and unspent — and the Salon remains justified on its own merits (cheapest build, funnel reach, portrait-format reach).
**Eligibility (roadmap §16.7):** P2.1 pipeline ✅ (IT5) · P2.2 descriptors-for-all ✅ (IT6) · P2.4 data ✅ (this brief + `php artisan venues:catalog-report`). No exceptions remain.

---

## 1. The gap this venue fills

The roadmap's identity audit (§3.3) closed with a precise statement: after the eleven-venue catalog covers **clean, warm-industrial, dramatic, serene, luxurious, electric, infinite, ethereal, cosmic, reflective, natural**, the only uncovered emotional registers are:

- **Intimacy** — a small salon: warm, domestic, close-hung.
- **Grandeur** — a great hall with real verticality.

These two are the only credible candidates for venue #12. Nothing else is missing; the catalog is not thin, it is under-realized. Any proposal outside these two registers is a demo, not a venue (§18 standing rule).

## 2. The instrument

```
php artisan venues:catalog-report          # human-readable rollup
php artisan venues:catalog-report --json   # machine-readable (briefs, CI)
```

The command rolls up, per venue: total galleries, public galleries, venue-attributed views (`venue_templates.view_count`, queued via `IncrementGalleryViews`), conversion per 1,000 views (`VenueTemplate::conversionRate()` — null when views = 0), demand by plan tier, and the §3.3 register coverage map. Its output is the *only* numeric input the decision rule in §3 consumes. Do not decide from anecdote; re-run the command on production and paste the JSON into the decision record.

## 3. The pre-committed decision rule

> **Studio-tier venues holding ≥ 50% of total venue-attributed views → build GRANDEUR (the great hall).**
> **Below 50% — or no meaningful view data yet → build INTIMACY (the salon).**

Why this rule and not a taste contest:

- **Grandeur is the expensive build.** Real verticality means taller wall meshes, more geometry per room, a costlier mobile pass. Premium demand concentrated at Studio tier is the market signal that justifies that cost — the people paying the most are telling us where the ladder should extend.
- **Intimacy is the cheap, high-leverage build.** A small salon is geometrically modest (fewer draw calls than most existing rooms), flatters the small-format and portrait work that today's large halls actively bury, and serves the free tier the conversion funnel under-serves. If demand is diffuse or unknown, the salon wins on cost, risk, and audience breadth simultaneously.
- The rule is decided by **data, not by the reviewer**. If the rollup shows 50/50, the tie belongs to the salon (the "below 50%" branch) — closeness is not a mandate for the expensive build.

## 4. The two candidates against the standing rule (§18)

### Candidate A — Intimacy: "The Salon" (default winner)

| Question | Answer |
|---|---|
| Which family? | Room family — closest siblings: Zen Gallery (serene), Dark Museum (dramatic). |
| What is its one idea? | *Close-hung warmth* — a domestic-scale room where works sit at conversational distance, salon-wall dense, under warm light. |
| What art does it flatter? | Small and portrait-format works, studies, photography, prints — the majority of what independent artists actually upload. |
| What does it cost on mobile? | Lowest of any venue since the White Cube: smaller room volume, fewer meshes, no new phenomena. Budget well under the §11.4 ceilings. |
| What does its fallback look like? | Nothing to fall back from — a plain room with warm tint degrades to the default room gracefully; no tier gates required. |
| Tier | Free or Pro. Reachable by the tier whose demand the rule says needs serving. |
| Placement character | Density preset `intimate` (2.8 spacing, shipped in IT6) + orientation-aware pairing — the salon is the first venue DESIGNED for the P2.3 curation machinery. |

### Candidate B — Grandeur: "The Grand Hall" (fires only on premium-dominant data)

| Question | Answer |
|---|---|
| Which family? | Room family — sibling: Dark Museum; the vertical counterpoint to everything horizontal. |
| What is its one idea? | *Real verticality* — a double-height hall with a clerestory light line, where scale itself is the drama. |
| What art does it flatter? | Large-format canvases, triptychs, commissioned statements — the works that make a Studio subscription feel inevitable. |
| What does it cost on mobile? | The critical risk: tall walls + higher camera range must pass the §11.4 draw-call and fps budgets on the mobile tier FIRST. Build order: massing → budgets → detail, in that order, and the detail dies before the budget does. |
| What does its fallback look like? | Tier gates on clerestory glow + upper-wall detail (the IT2 `tier_fallbacks` pattern); low tier receives the same hall with simpler ceilings — same room, quieter drama, no broken promise. |
| Tier | Studio. This is the ladder's visible top rung. |
| Placement character | Default uniform spacing; focal-wall hero treatment (IT6) earns its keep on the 6 m end wall. |

## 5. How the build itself must proceed (§16.7 pipeline, no exceptions)

1. **Clone** the closest family sibling in the admin suite (Salon ← Zen Gallery; Grand Hall ← Dark Museum).
2. **Descriptors**, never code: shell, structure, and lighting live entirely in `visual_config` (the IT6 contract — zero slug-keyed JS, verified by `VenueConsolidationIterationTest`'s scans).
3. **Preview** on `/venues/{slug}/preview` with a curated CC0 sample set before publishing — the chooser test extends to 12 or the venue does not ship.
4. **Publish** with pricing copy, plan arithmetic, and picker claims updated in the same release; the honesty contract (11/11 → 12/12 Promise test) applies from day one.
5. **Re-run the quality gates** (§13): promise walk, lineup still, tier matrix, draw-call audit — recorded in the iteration's test notes.

## 6. Doors this brief explicitly keeps shut

- **No retirement** (roadmap DO NOT DO #2): the rollup may show a weak venue; retirement stays closed — migration cost on pricing copy, plan arithmetic, SEO pages, and customer galleries exceeds maintenance savings. The data informs growth only.
- **No effects-premium** (DO NOT DO #5): neither candidate earns its identity from new particle systems or post-processing. One idea, composed.
- **No smart placement by default** (DO NOT DO #6): the salon uses IT6's density presets *as venue character* (authoring decision), not as an auto-magic layout engine.
- **No seeder reliance** (DO NOT DO #13): venue #12 ships as a migration/seed addition for fresh installs and as an admin-built venue for existing ones; production tuning happens through the authoring suite, never by re-seeding.

## 7. Decision record (completed at build time)

- Rollup JSON (pasted from `venues:catalog-report --json` on production): **not available in the build environment** — the sandbox has no production data. Per brief §3, the rule's no-data branch applies by construction.
- Studio-tier view share: **unknown (no data)** → the rule's explicit fallback.
- Rule outcome: ☐ Grandeur (≥50%) ☒ **Salon (<50% / no data)**
- Built by / reviewed by / shipped in: Exospace roadmap program · reviewed against §18 standing rule (family: Room · one idea: close-hung warmth · flatters: small-format and portrait work · mobile cost: lowest since the White Cube — every element `tier_floor: 'low'` · fallback: remove the structure/placement keys → plain default room, live) · **shipped in Iteration 8 "The Salon"** (`EXOSPACE_DOWNLOAD_9_SALON.zip`).
- Post-build note for the operator: run `php artisan venues:catalog-report --json` on production and paste the JSON here when available. If the ≥50% branch fires, open the Grand Hall build per §4 candidate B — the Salon is additive, not either/or.
