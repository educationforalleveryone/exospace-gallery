# Exospace Design System

**Status:** Canonical — Iteration 2 of the premium-SaaS UI rework (layout, navigation, responsive UX).
**Location of the kit:** `resources/css/app.css` (component classes) + `tailwind.config.js` (tokens).
**Applies to:** admin app, auth, billing, profile, Master Control (super-admin), OpsCenter, Control Center, public marketing pages, auth pages.

This document is the single source of truth for anyone (human or AI) building UI in Exospace. If a pattern you are about to write is not here, extend the system — do not invent a parallel one.

---

## 1. Design principles

1. **One accent.** Brand purple is the only accent. Status hues (below) are the only second voices. If a screen feels like it needs another accent color, the problem is hierarchy, not palette.
2. **Premium = restraint.** No decorative gradients, no glow-everything, no glass panels in the product UI. Depth comes from the elevation ladder (§4) and typography, not effects.
3. **Fix root causes.** A repeated pattern becomes a class in `app.css`. Thirty pages with inconsistent buttons means the *button system* is wrong, not the pages.
4. **Dark-native.** The product ships one theme. Do not introduce light-surface UI (the white MFA backup-codes page is a known legacy defect, scheduled for Iteration 2).
5. **Accessible by default.** Every interactive element has a visible focus state; every icon-only control has `aria-label`; text never goes below 12px.

---

## 2. Design tokens (tailwind.config.js)

### 2.1 Color

| Token | Value | Role |
|---|---|---|
| `ink-950` | `#08090d` | Tooltips, highest-contrast dark surfaces |
| `ink-900` | `#0f1117` | **Page canvas** (body background, nav chrome) — replaces every `bg-[#0f1117]` |
| `ink-800` | `#16181f` | Secondary canvas tint (header band) |
| `gray-800` | `#1f2937` | **Card / panel surface** (dominant surface) |
| `gray-900/50` | — | Inset wells: table headers, input fills, code blocks |
| `brand-600` / `brand-500` | `#7c3aed` / `#8b5cf6` | Primary CTA fill / hover |
| `brand-400` / `brand-300` | `#a78bfa` / `#c4b5fd` | Accent text / links on dark (AA on gray-800) |
| `surface-900/800/700` | — | Opt-in card tints (skeletons, command palette) |

**Status hue mapping (semantic):**

| Meaning | Family | Class set |
|---|---|---|
| success / live / active | **emerald** | `.badge-success`, `.alert-success`, `text-emerald-400` |
| warning / near-limit | **amber** | `.badge-warning`, `.alert-warning` |
| danger / destructive | **red** | `.badge-danger`, `.alert-error`, `.btn-danger` |
| info / neutral notice | **blue** | `.badge-info`, `.alert-info` |
| brand / plan tier | **brand** | `.badge-brand` (`.badge-pro` alias kept) |

Legacy `green-*`/`yellow-*` classes mean success/warning and are retired (public surface in iteration 4, app surface in iteration 6): use `emerald-*` / `amber-*`. `purple-*` and `indigo-*` are retired from product UI (they fought brand purple); remapping completed iteration 6, with the documented exceptions in §10.

### 2.2 Radii ladder

| Class | Size | Use |
|---|---|---|
| `rounded-md` | 6px | small chips, dense controls (`.btn-sm`) |
| `rounded-lg` | 8px | **all controls**: buttons, inputs, selects, table wrappers |
| `rounded-xl` | 12px | **all surfaces**: cards, panels, menus, modals |
| `rounded-full` | pill | badges, status dots, avatars |

`rounded-2xl` is reserved for large marketing surfaces (pricing cards, heroes). Never in admin UI.

### 2.3 Elevation shadows

| Token | Use |
|---|---|
| `shadow-card` | resting cards (ambient, subtle) |
| `shadow-card-hover` | hover lift on interactive cards |
| `shadow-menu` | dropdowns, popovers, toasts |
| `shadow-modal` | modal dialogs |
| `shadow-glow` | brand-tinted hover glow — used **only** by `.card-lift` |

Never stack `shadow-lg shadow-purple-900/40`-style colored shadows on arbitrary elements.

### 2.4 Spacing, controls, motion

- **Page containers (Iteration 2 — three sanctioned wrappers, nothing ad hoc):**

  | Class | Width | Use |
  |---|---|---|
  | `.page-shell` | `max-w-page` (1280px) | dashboards, list/table pages, editors, billing |
  | `.page-shell-mid` | `max-w-4xl` (896px) | detail pages, settings (profile), secondary lists |
  | `.page-shell-narrow` | `max-w-2xl` (672px) | focused single-purpose forms |

  All three share the horizontal padding ramp `px-4 sm:px-6 lg:px-8` and vertical rhythm `py-8 sm:py-10` (narrow/mid: `py-8`) — identical to the layout's header band, so page titles and content always share a left edge. Marketing/public pages compose via `layouts/public` (different rhythm by design).
- **Section stacks:** `space-y-6`; card grids `gap-4`–`gap-6`.
- **Control heights:** `h-8` (dense) · `h-10` (default buttons/inputs) · `h-11` (hero CTA) · `h-9` (icon buttons, nav controls).
- **Icon sizes:** 16px inline / 18px card icons / 20px nav icons.
- **Motion:** `duration-150` interactions, `duration-200` panels, `active:scale-[0.98]` on buttons only. `prefers-reduced-motion` is globally respected in `app.css`.

### 2.5 Typography (Inter, weights 400/500/600 only in product UI)

| Class | Recipe | Use |
|---|---|---|
| `.page-title` | 20→24px / semibold / tight / gray-50 | one per page, in the `$header` slot |
| `.page-subtitle` | 14px / gray-400 | supporting line under the page title |
| `.section-title` | 16px / semibold / gray-100 | card + panel headers (`h3`) |
| `.eyebrow` / `.section-header` | 12px / semibold / uppercase / wide / gray-500 | overline labels |
| body | 14px (`text-sm`) / gray-300 | default |
| `.label-text` | 14px / medium / gray-300 | form labels |
| `.hint-text` | 12px / gray-500 | helper text, metadata |
| `.text-numeric` | + tabular-nums | all metric values |

Rules: minimum UI size `text-xs` (12px) — no `text-[10px]`/`text-[11px]` in new code. Never bold body text; weight is hierarchy, not emphasis. Stat values use `.text-numeric` + `font-semibold` (not bold).

---

## 3. Buttons

One base, six product variants + eleven ops variants, three sizes. One **primary** button per view. **Variants are color-only** — geometry comes from `.btn` + the size class; never omit `.btn` (the iteration-8 cascade fix: variants used to re-declare the full base, which silently killed `.btn-sm`/`.btn-lg`/`.btn-icon` on ~104 controls).

```blade
<button class="btn btn-primary">Save changes</button>      {{-- primary action --}}
<button class="btn btn-secondary">Cancel</button>          {{-- solid quiet --}}
<button class="btn btn-ghost">Advanced</button>            {{-- quietest --}}
<button class="btn btn-danger">Delete gallery</button>     {{-- destructive --}}
<button class="btn btn-danger-ghost btn-sm">Remove</button>{{{{-- quiet destructive (rows) --}}
<button class="btn btn-brand-tint">Pro — $29</button>      {{-- brand-flavored quiet (iteration 7):
     plan upsells, workspace switch, "open live" — brand accent without
     the weight of a second primary. Replaces the 9 hand-rolled idioms. --}}
<button class="btn btn-primary btn-lg">Create gallery</button>
<button class="btn btn-icon btn-ghost" aria-label="Copy link"><svg…></button>

{{-- ops sub-brand (iteration 8): same geometry ladder, slate voice.
     primary/secondary/danger/amber = solids; ghost = slate bordered;
     {hue}-ghost = the section-hue row action (emerald/amber/sky/cyan/red);
     muted = non-interactive viewer-state marker (span, never a button). --}}
<button class="btn btn-ops-primary">Resolve</button>
<button class="btn btn-sm btn-ops-amber-ghost">Replay…</button>
<span class="btn btn-sm btn-ops-muted">Run checks — viewer (read-only)</span>

{{-- loading — always pair with disabled --}}
<button class="btn btn-primary" disabled>
    <span class="btn-spinner"></span> Saving…
</button>
```

Disabled = `disabled:opacity-50 disabled:pointer-events-none` (built into `.btn`). Never fake-disable with inline opacity styles; for links that must look disabled, add `aria-disabled="true"` — or better, use a real `disabled` button when the control is state-shaped (iteration 7 migrated the pricing "Your Current Plan ✓" markers from `aria-disabled` spans to real disabled buttons).

**Quiet inline controls (iteration 7).** Two sanctioned non-`.btn` idioms remain:
- Text-style links that are form submits or row toggles inside dense meta lines / table rows get a hit-area wrapper: `class="p-1.5 -m-1 rounded … hover:bg-white/[0.06]"` — keeps the quiet look, brings the effective target to ≈32px.
- Everything else graduates to the kit. No new bare `bg-brand-600 … rounded-lg px-4 py-2` buttons anywhere.

---

## 4. Cards

```blade
<div class="card card-pad">…</div>                       {{-- static --}}
<div class="card card-pad card-interactive">…</div>      {{-- clickable --}}
<div class="card card-interactive card-selected">…</div> {{-- selected --}}
```

- `.card` = `bg-gray-800 rounded-xl border-gray-700/60` — never re-type this recipe by hand.
- Headers inside cards: `.section-title`, action link on the right: `.action-link`.
- `.card-lift` (global) adds the brand-tinted hover lift — the only colored shadow in the system.
- Metric/stat cards: use `<x-dashboard.stat-card>` — value `.text-numeric text-2xl font-semibold`, label `text-sm text-gray-400`.

---

## 5. Forms

```blade
<label for="name" class="label-text mb-1.5">Gallery name</label>
<input id="name" type="text" class="input-base" placeholder="Spring Exhibition">
<input class="input-base input-error" aria-invalid="true" aria-describedby="name-error">
<p id="name-error" class="text-sm text-red-400">…</p>
<p class="hint-text mt-1.5">Shown on your public gallery page.</p>
```

- `.input-base` is an inset well (`bg-gray-900/60`) so it reads on both canvas and cards. All inputs, selects, textareas share it.
- Blade components: `<x-text-input>`, `<x-input-label>`, `<x-input-error>` wrap the same classes.
- Autocomplete on dark is handled globally (no white flash).

**Checkboxes, radios, file inputs (iteration 4):**

```blade
{{-- checkbox: pair with items-center; items-start + mt-1 for multi-line text --}}
<label class="flex items-center gap-2">
    <input type="checkbox" name="is_active" value="1" class="checkbox-base">
    <span class="text-sm text-gray-300">Published</span>
</label>
{{-- radio: same shape, .radio-base --}}
{{-- file: replaces every hand-rolled file: recipe --}}
<input type="file" class="file-base">
```

- `.checkbox-base` / `.radio-base` — 16px, form-plugin accent via `text-brand-600`, brand focus ring, `disabled:` variants. Never hand-roll `rounded bg-gray-700 border-gray-600 text-purple-600 …` again.
- `.file-base` — one file-input recipe (neutral `file:` button); the purple `file:bg-purple-600` variant is retired.
- **Error wiring is mandatory wherever `@error` feedback exists**: `class="input-base {{ $errors->has('x') ? 'input-error' : '' }}"` — a red message under a field that itself shows no error state is a broken pattern (iteration-4 sweep fixed 35 such fields).
- OpsCenter/Control Center fields use **`.input-ops` / `.input-ops-sm`** (iteration 8) — the same h-10/h-8 geometry ladder as `.input-base` in the slate voice (`bg-slate-900 border-slate-700`). Focus hue bakes the ops default (emerald); section voices override with a plain `focus:border-*` utility (sky=credentials, amber=action-confirm, cyan=Sentry mapping, brand=Control Center) — utilities outrank the component layer. Per-instance overrides (`bg-slate-950`, `font-mono`, `w-52`) ride on top the same way. Hand-rolled slate field recipes are retired.

---

## 6. Badges, alerts, tables, menus, modals

**Badges** — `<span class="badge badge-success">Live</span>` (+ `warning|danger|info|neutral|brand`).

**Alerts** — `<div class="alert alert-warning">…</div>`; icons `w-4 h-4`, matching status hue.

**Tables** — the canonical wrapper:

```blade
<div class="table-wrap">
    <table class="table-base">
        <thead class="table-head">
            <tr><th class="table-head-cell">Gallery</th>…</tr>
        </thead>
        <tbody>
            <tr class="table-row-base"><td class="table-cell">…</td></tr>
        </tbody>
    </table>
</div>
```

Every table sits in `.table-wrap` (scroll on mobile — content is never clipped). Empty state: `<td colspan class="table-empty">` or `<x-empty-state>`.

**Menus** — panels `.menu-panel`, rows `.menu-item` (or `<x-dropdown-link>`), group labels `.menu-header`, dividers `.menu-separator`. Trigger buttons carry `aria-haspopup`, `:aria-expanded`, `aria-controls`.

**Modals** — scrim `.modal-backdrop`, panel `.modal-panel` with `.modal-header` / `.modal-body` / `.modal-footer`. Widths: `max-w-md` confirm · `max-w-lg` form · `max-w-2xl` large. Destructive confirmations use `<x-confirm-modal>` (type-to-confirm). Ad-hoc `style="display:none"` overlays are legacy and migrate to `<x-modal>`. The chrome X is `.modal-close` (iteration 7 — one definition; was re-declared inline on 9 dialogs with drifting text colors). Inline `style="z-index:…"` on overlays is forbidden — use the safelisted ladder classes (`z-[45]`, `z-[60]`…).

**Operational status language (Iteration 2)** — the four-state vocabulary for OpsCenter, Control Center and Master Control. Always dot + word — never color alone. Rendered via `<x-status-badge>` (alias map included: ok/passed→healthy, degraded/flaky→warning, failed/overdue/down→critical, queued/pending→unknown, running→info):

```blade
<x-status-badge state="healthy" />                 {{-- ● Healthy (emerald) --}}
<x-status-badge state="degraded" label="Degraded" />{{-- ● Degraded (amber) --}}
<x-status-badge state="failed" label="FAILED" />    {{-- ● FAILED (red) --}}
<x-status-badge state="unknown" />                  {{-- ● Unknown (gray) --}}
```

Classes (`.status`, `.status-dot`, `.status-healthy|warning|critical|info|unknown`) live in `app.css`; square-ish `rounded-md` distinguishes operational chips from customer-facing `.badge` pills. State vocabulary per domain may vary (incident severity, credential rotation, backup health) — the *visual* result is always one identical pill language.

**Pagination** — `{{ $x->links() }}` renders the dark override in `resources/views/vendor/pagination/tailwind.blade.php` (result count + `btn-sm` page buttons, `aria-current` on the current page). Do not hand-roll pagination markup.

---

## 7. Navigation & layout

- Admin shell: `layouts/app.blade.php` + `layouts/navigation.blade.php` — canvas `bg-ink-900`, top nav `bg-ink-900/95 backdrop-blur`, hairline `border-gray-800`.
- Page header band: `bg-ink-800/60 border-b border-gray-800`, container `max-w-page … py-5`.
- **Page composition (Iteration 2):** every authenticated page renders its title block through `<x-page-header>` inside the `$header` slot:

  ```blade
  <x-slot name="header">
      <x-page-header :title="$gallery->title" description="…" :back="route('…')" backLabel="All galleries">
          <x-slot:actions>
              <a href="…" class="btn btn-primary">Primary action</a>
          </x-slot:actions>
      </x-page-header>
  </x-slot>
  ```

  It renders: back link / breadcrumb row → visible `h1.page-title` → description → meta row → action area (right-aligned ≥lg, stacked on mobile, `flex-wrap`). Pages that only supply an `h2` in the slot had **no h1 at all** (the layout's sr-only fallback doesn't fire when a slot exists) — `<x-page-header>` fixes this by emitting the real `h1`.
- **Primary nav:** Dashboard / Galleries / Artists / Teams (+ Master Control for super-admins). Do not add more items — extend the user dropdown or the page-level tool row instead. Free-plan upgrade chip is `lg`-only to prevent collisions at 1024px; Billing + Upgrade are in the mobile menu.
- **Section tool rows** (Master Control): `flex flex-wrap gap-2` of `.btn.btn-sm.btn-ghost` links inside the header band — never a fixed-width row.
- **Back navigation:** detail/edit pages pass `:back` to `<x-page-header>` — one consistent ← pattern instead of ad-hoc links.
- Brand wordmark: `.logo-text` (single definition — never define per-page gradients).
- OpsCenter / Control Center keep their slate identities but share the font (Inter), the global focus ring, `noindex`, the container ramp, and (Iteration 2) the `.status-*` language. Clickable table rows use `data-href` + a delegated nonced script (CSP-safe, keyboard operable) — inline `onclick` handlers are forbidden.

---

## 8. Accessibility rules

1. **Focus:** global `:focus-visible` ring (brand, 2px, offset 2) in `app.css` covers *everything*, including hand-rolled controls. Do not remove visible focus for aesthetics; inputs opt out (border+ring instead).
2. **Touch targets:** minimum 32px (`btn-sm`), preferably 40px (`h-9`/`h-10`).
3. **Icon-only buttons** always carry `aria-label`.
4. **Semantics:** `<button>` for actions, `<a>` for navigation — never clickable `<div>`s, never `<span class="btn">` (the pricing-page legacy was migrated to real `disabled` buttons in iteration 7).
5. **Dialogs:** `role="dialog"`, `aria-modal`, labelled; Escape and backdrop click close; focus is trapped (`<x-modal>`, `<x-confirm-modal>`).
6. **Menus:** `role="menu"` + arrow-key navigation (`<x-dropdown>`).
7. **Active nav:** `aria-current="page"` (`<x-nav-link>`).
8. **Toasts:** `<x-toast>` — `role="alert"` for errors, `role="status"` otherwise, announced politely.
9. **Reduced motion:** globally honored; don't add motion that conveys information.
10. **Contrast:** gray-500 is the faintest text allowed (gray-600 only for decorative timestamps); never below 12px.

---

## 9. Z-index ladder (iteration 3 — ONE layering system)

Every floating layer uses one of these tiers. No ad-hoc `z-[9999]` — if a layer doesn't fit, extend the ladder, not the element.

| Tier | Value | Contains |
|---|---|---|
| In-card | `z-10` / `z-20` | badges, hover controls *inside* a card |
| Sticky page | `z-30` | reorder bars, in-page sticky toolbars |
| Site chrome | `z-40` | top navs (app/public/ops), impersonation banner |
| Persistent overlay | `z-[45]` | cookie banner, feedback FAB |
| Dropdown | `z-50` | `<x-dropdown>`, notification + team panels, popovers |
| Tooltip | `z-[55]` | `[data-tooltip]::after` (CSS-only; the unused `<x-tooltip>` component was deleted in iteration 8) |
| **Modal** | `z-[60]` | `<x-modal>`, confirm dialogs, `exospaceConfirm`, every hand-rolled page modal |
| Command palette | `z-[70]`/`z-[71]` | palette backdrop + panel (may open above a modal) |
| Toast | `z-[100]` | `<x-toast>` — always on top, never covers blocking controls |

**Rules:**

1. Modals sit *above* dropdowns — a `z-[60]` scrim must bury any open menu.
2. Toasts are topmost; feedback must never be buried by the thing it reports on.
3. Avoid creating stacking contexts between a floating layer and `<body>`: no `transform` / `filter` / `backdrop-blur` on ancestors of dropdowns/modals. (`.card-lift` hover transforms, `.pageIn` animation — keep them opacity-only or dropdown-free.)
4. `overflow: hidden/auto` on an ancestor clips `absolute` popovers regardless of z — render such popovers in-flow (see live-preview hints) or fix the container.

## 9.1 Focus containment for dialogs (iteration 4)

There are two dialog systems and both must trap Tab:

1. **Kernel-managed** (`role="dialog"` + opened via `window.openModal`) — trap, focus-in, focus-restore, scroll lock and Escape are automatic. No markup needed beyond the existing contract.
2. **Alpine-managed** (`x-data` overlays) — add **`data-focus-trap`** to the overlay root. The kernel binds one delegated `keydown` listener: while the focused element lives inside a visible `[data-focus-trap]`, Tab is cycled within it. No Alpine state is touched, so open/reopen behavior is untouched. Never add `data-focus-trap` to a component that already self-traps (`<x-modal>` binds `x-on:keydown.tab.prevent` — double-trapping makes focus jump twice per keypress).

Marked so far: feedback widget, command palette, Master Control delete/admin type-to-confirm modals (also gained the standard body scroll lock), dashboard welcome modal, `<x-confirm-modal>` (canonical, for future adoption).

### Modal architecture

- **Alpine dialogs:** `<x-modal>` (event-driven, focus trap, scroll lock) and `<x-confirm-modal>` (type-to-confirm). The kit `.btn-spinner` + `disabled` is the loading story.
- **Imperative dialogs:** any element with `role="dialog"` + `id` + `aria-modal` + the `hidden`/`flex` pattern works with the shared `openModal(id)` / `closeModal(id)` helpers in `resources/js/app.js` — they add body scroll lock, focus movement (prefer a `[data-autofocus]` child), Tab trap, backdrop click, Escape (top-most first), and focus restore. Page-local modal JS is a bug, not a pattern.

### Interaction reliability rules (iteration 3)

1. **Confirms:** one mechanism — `window.exospaceConfirm()` (styled, focus-restoring, double-submit-guarded). Wire via `form[data-confirm]` / `[data-confirm-click]` / `form[data-submit="exospaceConfirmWrapper"] data-confirm-message="…"`. Never `onsubmit="return confirm(…)"`, never `window.confirm` in page scripts.
2. **Double-submit:** opt-in guard — `form[data-busy] [data-busy-label="Publishing…"]`. Every POST that spends money, mutates state irreversibly, or runs longer than ~1s carries it. Confirmed forms are guarded by `exospaceConfirm` automatically.
3. **Turbo safety:** Turbo Drive swaps `<body>` without re-firing `DOMContentLoaded`. All global listeners are delegated on `document` inside one-time `window.__exospace*Init` guards (see `layouts/app.blade.php`, `resources/js/app.js`). Page scripts that bind per-element must not rely on DOMContentLoaded; page-local `addEventListener` on `document` needs a guard or will stack per visit.
4. **Feedback:** transient confirmation = toast only (pages must not re-render flash banners — the toast owns session flashes). Persistent context = banner/alert. Async actions disable their trigger + show `.btn-spinner`. `alert()` is banned.
5. **Toasts:** identical toasts within 900ms are suppressed; error/warning are assertive (`role=alert`); success/info polite.

---

## 10. Token discipline for future work

- **No hex literals in Blade.** Use tokens; if a token is missing, add it to the scale in `tailwind.config.js` — deliberately, not ad hoc.
- **New pattern → new class.** If you write the same recipe twice, promote it to `app.css` and document it here.
- **Safelist is intentional.** All kit classes are safelisted in `tailwind.config.js` so a future adoption can never deploy with the class missing from the compiled CSS.
- **Deprecation path:** old names (`.badge-warn`, `.badge-pro`, `.section-header`) remain as aliases; use the semantic names in new code.

### Public-surface vocabulary (iteration 4)

- **One accent: `brand-*`.** `purple-*` and `indigo-*` are retired everywhere (193 public-surface tokens remapped in iteration 4). Status hues stay semantic: `emerald-*` (never `green-*`), `amber-*` (never `yellow-*`), `red-*` danger, `blue-*` info.
- **`.gradient-text` / `.logo-text` have exactly one definition** (app.css). Page-local overrides are deleted; a page that redefines a kit class is a bug, not a theme.
- **Page `<style>` blocks own page classes only.** `*`, `body`, `nav`, or element-selector rules leak past the page onto the shared layout (this shipped on contact — `*` reset + body override killed the layout's canvas on that page). Fixed and documented; keep it that way.
- **Marketing pages use `.badge`, `.card`, `.status-*`, `.btn` like the product** — changelog and status are the reference conversions.
- **Standalone no-`app.css` pages** (gallery/view, closed, coming-soon, pin) keep their own coordinate systems; gallery/view's z-scale (10→200) is a documented exception (isolated WebGL document).

### App-surface vocabulary (iteration 6)

- **The one-accent rule now holds app-wide.** The remaining `purple-*`/`indigo-*`/`green-*`/`yellow-*` tokens on the app surface (admin, Master Control, dashboard, billing, profile, auth, shared components) were remapped same-shade to `brand-*` / `emerald-*` / `amber-*` in iteration 6 (~450 tokens, 52 files). Same-hue-family shifts only — no layout, geometry, or component recipes changed.
- **Categorical data colors are exempt from the one-accent rule.** Where a hue distinguishes data categories (Master Control's `$statTones` tone keys, the galleries-analytics stat-icon map, QA flaky/perma-red kind colors, Chart.js canvas series), hues stay distinct — a second voice that labels a *data category* is doing real work. Never remap a categorical map mechanically; check for key collisions first (the `$statTones` `indigo` key was kept precisely because its `purple` sibling already resolves to brand).
- **Documented sub-brands unchanged:** OpsCenter/Control Center slate skins with their per-section hue coding (Actions=amber, Credentials=sky, Access=indigo in ops/layout); the 3D runtime (`gallery/view`).
- **Dead-brand watch:** if a component carries a retired gradient (the pre-iteration-1 `purple-400→indigo-400` wordmark survived on `teams/invitation-expired` and the unused `application-logo` until iteration 6), sweep for it when touching the component family — the logo treatment is `.logo-text`, never a page-local gradient. The same failure mode hides in page `<style>` blocks and JS template literals: iteration 7 retired the reorder-bar `linear-gradient(#9333ea, #6366f1)` save button, the `#a855f7` dropzone/venue-selection hexes, and the dead `.custom-checkbox` rule in the gallery editor.

### Button-kit completion & quiet-control contract (iteration 7)

- **`.btn-brand-tint`** is the sixth variant: brand-flavored quiet action (`bg-brand-600/15 border-brand-600/40 text-brand-300`). Use it where `.btn-secondary` is too neutral and a second primary would over-weight the view — plan upsells, "Switch here", "Open public view".
- **`.modal-close`** is the one dialog X. Never re-declare the 32px rounded ghost square inline again.
- **Button graduation is complete on the app surface.** Every `<button>`/`<a>` styled as a control uses the kit, except the two sanctioned quiet-inline idioms (§3). Sub-32px form submits are bugs (profile OAuth "Unlink" was a ~16px submit — now `btn-sm btn-danger-ghost`).
- **Text floor is global, including ops partials and standalone page CSS** (venue-picker badges were 9px in edit and 10px in create with two drifting recipes — now one 12px recipe). `invoices/pdf` 11px print body remains exempt.
- **Native dialogs are banned everywhere, including the 3D runtime**: `window.prompt`/`alert()`/`confirm()` → toast / clipboard fallback / `exospaceConfirm` (gallery share fallback now uses select-and-copy; the tour uses a toast).
- **One heading per view:** auth pages carry `sr-only` h1s (or a real one, register); MFA setup/verify merged their duplicate h2+h1 into `<x-page-header>`; `mfa-backup-codes`' `<h1>…</h2>` tag mismatch fixed.

### Ops-native pass & cascade contract (iteration 8)

- **Variants are color-only — the cascade contract.** Every `.btn-*` variant (product and ops) carries colors only; geometry (height, padding, radius, focus ring, active scale, disabled) comes from `.btn` + the optional size class. Before iteration 8 each variant `@apply`ed the full `.btn` base, so its `h-10 px-4 text-sm` landed after `.btn-sm`/`.btn-lg`/`.btn-icon` in the compiled cascade and silently killed those size classes on **104 controls** (every `btn btn-sm btn-*` rendered 40px since the kit shipped). If you add a variant, never `@apply btn` into it.
- **`.btn-ops-*` is the ops/Control Center voice of the kit** — same geometry ladder, slate color language, documented hue map (Actions=amber · Credentials=sky · Access=cyan/amber categorical · emerald=global positive/Incidents · red=destructive). The four section-hue `*-ghost` classes replaced 7 near-identical hand-rolled strings that had drifted in radius, border shade, hover shade, and padding; `.btn-ops-muted` is the non-interactive viewer-state marker (a span, never focusable). Ops controls pair with `.input-ops`/`.input-ops-sm` (§5) — buttons and their neighboring filter fields share heights (40/40 form rows, 32/32 filter rows).
- **Control Center is now shell-compatible with OpsCenter**: sticky `z-40` header with backdrop blur, `text-slate-100` body, `← App` and bordered nav actions on `.btn-ops-ghost`, brand Run buttons on the kit `.btn-primary` (its brand accent predates the pass and stays — CC is Master Control's QA sub-brand, not an ops hue).
- **Width ladder sanction (§6.6 closed):** `max-w-page` is the app-shell width; standalone public pages (welcome sections, artworks/show, artists/show, gallery/events, seo pages) compose their own editorial ladder of `max-w-6xl` full-bleed sections and `max-w-5xl` columns inside full-screen layouts. This is deliberate composition, not drift — do not "normalize" it.
- **Tooltip resolution (§6.7 closed):** the `[data-tooltip]` CSS mechanism stays (3 live uses on dashboard gallery cards); the unused `<x-tooltip>` component is deleted. The z-[55] tooltip tier is CSS-only now.
- **Ops chip language stays in Blade, deliberately.** The status/chip maps (11 maps across ops/CC) share one formula — `bg-{hue}-950/60 text-{hue}-300 border-{hue}-700/50` + `bg-{hue}-400` dot — which is the documented ops counterpart of `.badge-*`/`.status-*` (darker tint for the slate canvas). The maps themselves are the semantic layer (status → hue); don't relocate them into CSS. `class="status …chip-string"` composition is sanctioned (kit shape + ops colors).
- **Nav/filter chips and queue chips** (`rounded-md px-3 py-1.5` with hue-tinted active state; queue's `rounded-full font-mono` pills) are the sanctioned ops chip idiom — not buttons, do not migrate.
