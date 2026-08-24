# Exospace — SEO System Handoff for Keyword Strategy Implementation

**Audience:** the developer or AI who arrives with a finished keyword strategy (research, targets, priorities) and needs to implement it on Exospace.

**Good news: the machine is built.** Implementing a keyword strategy on this platform is a DATA exercise, not an engineering exercise. This document maps every strategy element to the exact place it plugs in.

---

## 1. Where each strategy element lands

| You have… | It goes… | How |
|---|---|---|
| A target query for a new marketing surface (e.g. "virtual galleries") | `seo_pages` (type `landing`) | `php artisan seo:make-page virtual-galleries --type=landing --title="…"` → edit blocks → publish via super-admin → SEO → Content pages |
| A topic for a guide/tutorial (e.g. "how to curate a virtual exhibition") | `seo_pages` (type `editorial`) | `php artisan seo:make-page how-to-curate --type=editorial --title="…"` → lives at `/resources/how-to-curate` |
| Better title/description for an existing gallery or artist | `seo_profiles` overrides | Super-admin → SEO → Galleries/Artists → Edit SEO. Curators can do title/description themselves in their edit forms. |
| A decision that a page should (not) be indexed | `seo_profiles.robots_directive` | Same console. Or the entity's `noindex` quality rules (code, config `seo.artwork_gate`). |
| Content for landing pages | `blocks` JSON on `seo_pages` | Typed allow-list: `hero`, `text`, `features`, `faq`, `cta` + live blocks `exhibitions`, `artists`, `venues` (real content — keeps pages non-thin). |
| Internal-link priorities | `config/seo.php → seo.related.*` limits + live-block headings | Link GRAPH is automatic (shared artists/venues relevance). Don't hand-place links. |
| A moved/renamed page | `seo_redirects` | Super-admin → SEO → Redirects (301). |

**Zero template rewrites. Zero new routes. Zero migrations.**

## 2. The systems you inherit (one-paragraph each)

- **Metadata engine** — `App\Support\Seo\SeoManager` builds `SeoData` per entity from real content; `seo_profiles` overrides layer on top; `<x-seo>` renders. Title templates live in `config/seo.php → seo.templates` (change brand voice there, once).
- **Canonicalization** — `App\Support\Seo\CanonicalUrl` strips tracking params everywhere; pagination self-canonicalizes; `?artwork=` deep links canonicalize to artwork pages; custom domains canonicalize to the gallery's `public_url`. Policy table: `docs/SEO_AUDIT.md §5`.
- **Structured data** — `App\Services\Seo\SchemaBuilder` (Organization, WebSite, Person, ExhibitionEvent, CollectionPage, VisualArtwork, ItemList). Real columns only; extend it if a strategy needs a new schema type — never inline JSON-LD in templates.
- **Internal linking** — `App\Services\Seo\InternalLinkingService`: exhibition ↔ exhibition (shared artists/venue), artist ↔ artist (shared shows), artwork → artist/exhibition/siblings/cross-gallery works. Capped at 6, cached 15 min.
- **Sitemaps** — grouped (static/galleries/artists/artworks/content), 2,000 URLs/page, versioned caches invalidated by entity observers, real lastmods, custom-domain single-entry sitemaps. Legacy `/sitemap-{n}.xml` 301s preserved.
- **Robots** — dynamic, host-aware (`RobotsController`), rules in `config/seo.php → seo.robots`.
- **SEO pages** — `seo_pages` + `SeoPageRenderer` (closed block allow-list, drafts never indexable, fallback-route resolution that can never shadow product routes).
- **Measurement** — first-touch acquisition (`users.acquisition_*`) + Acquisition tab in the SEO console: organic signups, organic share, galleries created by organic users, top converting landing pages.
- **Operations** — super-admin console `/master-control/seo`, daily `exospace:seo-audit` (Slack on warnings), `seo:rebuild` for cache control, curator-facing SEO fields in gallery/artist forms.

## 3. Quality rules that protect you (do not defeat them)

1. Artwork pages index only when they pass the mechanical gate (title + description ≥ 80 chars OR medium OR year OR artist). Don't loosen the gate for volume — use it as a to-do list for curators.
2. Empty galleries, empty event calendars, artist profiles without public works, and draft pages are `noindex`. This is deliberate thin-page control.
3. Live blocks on landing pages render REAL exhibitions/artists/venues — a landing page cannot be pure copy. Keep at least one live block per landing page.
4. Never add fabricated structured data (reviews, ratings, invented events). `SchemaBuilder` enforces real columns — keep it that way.
5. `sitemap_include=true` can override thin-content rules but never the access gate (PIN/schedule/private stays out, period).

## 4. Suggested workflow for a new strategy

1. Map each target query to a surface type (landing page / editorial page / existing entity optimization).
2. Create the pages (`seo:make-page`) with honest, specific copy; include one live block each.
3. Set entity-level overrides where a gallery/artist genuinely deserves a custom title (don't blanket-override — the auto-generated titles already follow good patterns).
4. Publish in batches; watch the Acquisition tab for organic signup movement; iterate on the pages that convert.
5. Record any manual external configuration (Search Console, DNS) in `docs/MASTER_MANUAL_OPERATIONS.md`.

## 5. Reference index

| Topic | Document |
|---|---|
| Full pre-implementation audit + canonical policy | `docs/SEO_AUDIT.md` |
| Manual operations (GSC, Bing, DNS, analytics) | `docs/MASTER_MANUAL_OPERATIONS.md` |
| All SEO config knobs | `config/seo.php` |
| Database surfaces | `seo_profiles`, `seo_redirects`, `seo_pages`, `users.acquisition_*` |
| Tests (run `php artisan test --filter=Seo`) | 6 files, ~130 tests across foundation/entities/schema/sitemaps/pages/admin/measurement |
