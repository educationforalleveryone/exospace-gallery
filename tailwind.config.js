import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/**
 * Exospace — Design Token Configuration (single source of truth).
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * DESIGN LANGUAGE (canonical — see docs/DESIGN-SYSTEM.md)
 * ─────────────────────────────────────────────────────────────────────────────
 * One dark theme, one accent, four status hues. Premium = clarity +
 * hierarchy + restraint, not decoration.
 *
 * COLOR ROLES
 *   brand-*     The single accent (purple family). CTAs, active states,
 *               selection, focus. If a second hue starts fighting with it,
 *               remove the second hue.
 *   ink-*       The page canvas ramp (near-black, slightly blue).
 *               ink-900 is THE page background — never hardcode hex again.
 *   surface-*   Card / panel tints (used by skeletons, command palette).
 *   gray-*      Neutral surface + text ramp (Tailwind default, kept so the
 *               thousands of existing `bg-gray-800` cards stay valid).
 *
 *   Status mapping (semantic, used by .badge-* / .alert-* / .btn-danger):
 *   success = emerald · warning = amber · danger = red · info = blue.
 *   Legacy `green-*` usages migrate to emerald incrementally.
 *
 * ELEVATION LADDER (dark UI elevates by lightness, not by shadow alone)
 *   ink-950   #08090d  tooltips, toast surfaces (highest contrast zones)
 *   ink-900   #0f1117  page canvas + nav chrome
 *   gray-800  #1f2937  cards, panels, form wells on canvas
 *   gray-900/50       inset wells (table headers, code, input fills)
 *   + border-gray-600/60 + shadow-menu/modal for floating layers
 *
 * RADII (only these three for controls/surfaces; full/pill for badges)
 *   rounded-md 6px   small chips, dense controls
 *   rounded-lg 8px   buttons, inputs, selects, table wrappers
 *   rounded-xl 12px  cards, panels, menus, modals
 *   rounded-2xl is reserved for large marketing surfaces — not admin UI.
 *
 * LEGACY NOTE
 *   `bg-[#0f1117]`, `bg-[#0a0a0f]` etc. are replaced by `bg-ink-900` /
 *   `bg-surface-900`. The commented "ITERATION-2" notes from the previous
 *   token pass are preserved in git history; this phase (ITERATION-1 of the
 *   premium-SaaS rework) finalizes the token set and puts the shared
 *   component classes in resources/css/app.css.
 */

/** @type {import('tailwindcss').Config} */
export default {
    darkMode: 'class',

    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.{js,ts,vue,blade.php}',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['Inter', ...defaultTheme.fontFamily.sans],
            },

            colors: {
                // The brand accent — one purple family, nothing else.
                // brand-600 is the canonical CTA surface, brand-400 the
                // canonical accent-text color (AA on gray-800/900).
                brand: {
                    50:  '#f5f3ff',
                    100: '#ede9fe',
                    200: '#ddd6fe',
                    300: '#c4b5fd',
                    400: '#a78bfa',
                    500: '#8b5cf6',
                    600: '#7c3aed',
                    700: '#6d28d9',
                    800: '#5b21b6',
                    900: '#4c1d95',
                    950: '#3b0764',
                },

                // Page canvas ramp. ink-900 replaces every bg-[#0f1117].
                ink: {
                    950: '#08090d',
                    900: '#0f1117',
                    800: '#16181f',
                    700: '#1f2937',
                    600: '#374151',
                },

                // Card/panel tints (opt-in; skeleton + command palette use these).
                surface: {
                    900: '#0a0a0f',
                    800: '#1a1d27',
                    700: '#252836',
                },
            },

            // Elevation shadows — soft, ambient, never colored glows except
            // the brand one reserved for .card-lift hover.
            boxShadow: {
                'menu':  '0 10px 38px -10px rgba(0, 0, 0, 0.55), 0 4px 12px -4px rgba(0, 0, 0, 0.4)',
                'modal': '0 24px 64px -12px rgba(0, 0, 0, 0.6), 0 8px 20px -8px rgba(0, 0, 0, 0.45)',
                'glow': '0 8px 32px rgba(139, 92, 246, 0.12)',
                'card': '0 1px 3px rgba(0, 0, 0, 0.4), 0 1px 2px rgba(0, 0, 0, 0.2)',
                'card-hover': '0 8px 24px rgba(0, 0, 0, 0.5)',
            },

            animation: {
                'page-in': 'pageIn 0.25s ease-out',
                'slide-down': 'slideDown 0.2s ease-out',
                'shimmer': 'shimmer 2s linear infinite',
            },
            keyframes: {
                pageIn: {
                    '0%':   { opacity: '0' },
                    '100%': { opacity: '1' },
                },
                slideDown: {
                    '0%':   { opacity: '0', transform: 'translateY(-8px)' },
                    '100%': { opacity: '1', transform: 'translateY(0)' },
                },
                shimmer: {
                    '0%':   { backgroundPosition: '-200% 0' },
                    '100%': { backgroundPosition: '200% 0' },
                },
            },

            // Standard page container (1280px) — replaces ad-hoc max-w-7xl
            // in layouts; pages may still use max-w-5xl / max-w-3xl for
            // narrow content, documented in docs/DESIGN-SYSTEM.md.
            maxWidth: {
                page: '80rem',
            },
        },
    },

    // The design-system component classes (resources/css/app.css) live in
    // @layer components, so Tailwind purges any of them that no template
    // references yet. That is correct for one-off classes but wrong for a
    // design SYSTEM: a page migrated to `.btn-ghost` / `.page-title` /
    // `.table-head-cell` in a future iteration must never deploy with the
    // class silently missing from the compiled CSS. The full UI kit is
    // therefore safelisted — it costs ~3 KB gzipped and removes an entire
    // class of "adopted class didn't compile" regressions.
    safelist: [
        // Buttons
        'btn', 'btn-sm', 'btn-lg', 'btn-icon', 'btn-primary', 'btn-secondary',
        'btn-ghost', 'btn-danger', 'btn-danger-ghost', 'btn-spinner',
        // Cards
        'card', 'card-pad', 'card-interactive', 'card-selected', 'card-lift',
        // Forms
        'input-base', 'input-sm', 'input-error', 'label-text', 'hint-text',
        'checkbox-base', 'radio-base', 'file-base', 'legal-prose',
        // Badges
        'badge', 'badge-success', 'badge-warning', 'badge-warn', 'badge-danger',
        'badge-info', 'badge-neutral', 'badge-brand', 'badge-pro',
        // Alerts
        'alert', 'alert-info', 'alert-success', 'alert-warning', 'alert-error', 'alert-brand',
        // Tables
        'table-wrap', 'table-base', 'table-head', 'table-head-cell', 'table-cell',
        'table-row-base', 'table-empty',
        // Menus
        'menu-panel', 'menu-item', 'menu-header', 'menu-separator',
        // Modals
        'modal-backdrop', 'modal-panel', 'modal-header', 'modal-body', 'modal-footer',
        'modal-title',
        // Typography & misc
        'page-title', 'page-subtitle', 'section-title', 'section-header', 'eyebrow',
        'text-numeric', 'action-link', 'empty-state', 'gradient-text', 'logo-text',
        'progress-fill', 'page-content', 'mobile-menu-open', 'well',
        // Page composition (iteration 2)
        'page-shell', 'page-shell-mid', 'page-shell-narrow', 'back-link',
        // Operational status language (OpsCenter / Control Center / Master Control)
        'status', 'status-dot', 'status-healthy', 'status-warning',
        'status-critical', 'status-info', 'status-unknown',
        // Z-index ladder (iteration 3) — the only sanctioned floating tiers.
        // Safelisted so helpers that build class strings in JS (app.js
        // exospaceConfirm, modal system) can never ship without them.
        'z-30', 'z-40', 'z-[45]', 'z-50', 'z-[55]', 'z-[60]', 'z-[70]', 'z-[71]', 'z-[100]',
    ],

    plugins: [forms],
};
