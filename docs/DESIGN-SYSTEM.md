# Exospace Design System

**Status:** Canonical — Iteration 1 of the premium-SaaS UI rework.
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

Legacy `green-*` classes mean success and migrate to `emerald-*` incrementally. `indigo-*` is retired from product UI (was fighting brand purple).

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

- **Page container:** `max-w-page` (80rem) with `px-4 sm:px-6 lg:px-8`. Narrow content: `max-w-3xl`/`max-w-2xl` for forms and detail pages.
- **Page vertical rhythm:** `py-8 sm:py-10` under the header; section stacks `space-y-6`; card grids `gap-4`–`gap-6`.
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

One base, five variants, three sizes. One **primary** button per view.

```blade
<button class="btn btn-primary">Save changes</button>      {{-- primary action --}}
<button class="btn btn-secondary">Cancel</button>          {{-- solid quiet --}}
<button class="btn btn-ghost">Advanced</button>            {{-- quietest --}}
<button class="btn btn-danger">Delete gallery</button>     {{-- destructive --}}
<button class="btn btn-danger-ghost btn-sm">Remove</button>{{{{-- quiet destructive (rows) --}}
<button class="btn btn-primary btn-lg">Create gallery</button>
<button class="btn btn-icon btn-ghost" aria-label="Copy link"><svg…></button>

{{-- loading — always pair with disabled --}}
<button class="btn btn-primary" disabled>
    <span class="btn-spinner"></span> Saving…
</button>
```

Disabled = `disabled:opacity-50 disabled:pointer-events-none` (built into `.btn`). Never fake-disable with inline opacity styles; for links that must look disabled, add `aria-disabled="true"`.

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

**Modals** — scrim `.modal-backdrop`, panel `.modal-panel` with `.modal-header` / `.modal-body` / `.modal-footer`. Widths: `max-w-md` confirm · `max-w-lg` form · `max-w-2xl` large. Destructive confirmations use `<x-confirm-modal>` (type-to-confirm). Ad-hoc `style="display:none"` overlays are legacy and migrate to `<x-modal>`.

---

## 7. Navigation & layout

- Admin shell: `layouts/app.blade.php` + `layouts/navigation.blade.php` — canvas `bg-ink-900`, top nav `bg-ink-900/95 backdrop-blur`, hairline `border-gray-800`.
- Page header band: `bg-ink-800/60 border-b border-gray-800`, container `max-w-page … py-5`.
- Every page provides its title via the `$header` slot using `.page-title`; the layout injects a visually-hidden `h1` fallback for screen readers.
- Brand wordmark: `.logo-text` (single definition — never define per-page gradients).
- OpsCenter / Control Center keep their slate identities but share the font (Inter), the global focus ring, and `noindex` (ops).

---

## 8. Accessibility rules

1. **Focus:** global `:focus-visible` ring (brand, 2px, offset 2) in `app.css` covers *everything*, including hand-rolled controls. Do not remove visible focus for aesthetics; inputs opt out (border+ring instead).
2. **Touch targets:** minimum 32px (`btn-sm`), preferably 40px (`h-9`/`h-10`).
3. **Icon-only buttons** always carry `aria-label`.
4. **Semantics:** `<button>` for actions, `<a>` for navigation — never clickable `<div>`s, never `<span class="btn">` (pricing page legacy is queued for migration).
5. **Dialogs:** `role="dialog"`, `aria-modal`, labelled; Escape and backdrop click close; focus is trapped (`<x-modal>`, `<x-confirm-modal>`).
6. **Menus:** `role="menu"` + arrow-key navigation (`<x-dropdown>`).
7. **Active nav:** `aria-current="page"` (`<x-nav-link>`).
8. **Toasts:** `<x-toast>` — `role="alert"` for errors, `role="status"` otherwise, announced politely.
9. **Reduced motion:** globally honored; don't add motion that conveys information.
10. **Contrast:** gray-500 is the faintest text allowed (gray-600 only for decorative timestamps); never below 12px.

---

## 9. Token discipline for future work

- **No hex literals in Blade.** Use tokens; if a token is missing, add it to the scale in `tailwind.config.js` — deliberately, not ad hoc.
- **New pattern → new class.** If you write the same recipe twice, promote it to `app.css` and document it here.
- **Safelist is intentional.** All kit classes are safelisted in `tailwind.config.js` so a future adoption can never deploy with the class missing from the compiled CSS.
- **Deprecation path:** old names (`.badge-warn`, `.badge-pro`, `.section-header`) remain as aliases; use the semantic names in new code.
