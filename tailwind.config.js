import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/**
 * Exospace Tailwind configuration.
 *
 * ITERATION-2 (AUDIT-P1-2.1 FIX): Established a real design-token system.
 * Previously the config had only the default Tailwind palette + Inter font
 * — the "brand purple" was Tailwind's default `purple-500`, and ad-hoc hex
 * literals like `#0f1117`, `#0a0a0f`, `#0a0a0a` were scattered across views.
 *
 * Now the design language is centralized:
 *   - colors.brand (50-900) — the purple accent (curated, not default Tailwind)
 *   - colors.ink (900/800/700) — dark page backgrounds (replaces #0f1117 / #0a0a0f / #0a0a0a)
 *   - colors.surface (900/800/700) — card / panel backgrounds (gray-900 / gray-800 / gray-700 equivalents)
 *   - boxShadow.glow — the purple hover glow used by .card-lift
 *   - animation / keyframes — pageIn, slideDown, shimmer (for skeletons)
 *   - maxWidth.page — the standard page container (replaces ad-hoc max-w-7xl)
 *
 * Existing views that use Tailwind's default palette (e.g. `bg-gray-900`, `text-purple-400`)
 * CONTINUE TO WORK — Tailwind merges default tokens with extended ones. The new
 * tokens are opt-in: views can be migrated incrementally to use the new tokens
 * (`bg-ink-900`, `text-brand-400`, etc.) without breaking anything that uses the
 * old defaults.
 *
 * Iteration 2 ships with the analytics page + dashboard migrated to the new
 * tokens as proof of concept. Subsequent iterations will migrate remaining
 * views incrementally — no big-bang rewrite.
 */

/** @type {import('tailwindcss').Config} */
export default {
    darkMode: 'class', // Enable dark mode via class

    // C-10 FIX (Iter-005): Added './resources/js/**/*.{js,ts,vue,blade.php}'
    // to the content array. Previously, only Blade files were scanned — any
    // Tailwind class that exists only inside resources/js/** (Alpine
    // components, gallery JS DOM injection, the toast helper in layouts/app.blade.php)
    // would be purged from production CSS.
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

            // ── ITERATION-2: Design tokens ───────────────────────────────────
            colors: {
                // The brand accent — purple. Curated to feel premium without
                // being loud. `brand-500` is the canonical CTA color.
                // (Same hue family as the original `purple-500` default, so
                // existing CTAs look identical when migrated to `bg-brand-500`.)
                brand: {
                    50:  '#f5f3ff',
                    100: '#ede9fe',
                    200: '#ddd6fe',
                    300: '#c4b5fd',
                    400: '#a78bfa',
                    500: '#8b5cf6', // canonical CTA
                    600: '#7c3aed',
                    700: '#6d28d9',
                    800: '#5b21b6',
                    900: '#4c1d95',
                },

                // Page backgrounds — the dark canvas. `ink-900` is the
                // primary page background (replaces the ad-hoc `#0f1117`
                // literal seen across many views).
                ink: {
                    950: '#08090d', // deepest background (modals, dropdowns)
                    900: '#0f1117', // primary page background
                    800: '#16181f', // secondary panel background
                    700: '#1f2937', // tertiary surface (was gray-800)
                    600: '#374151', // borders / dividers (was gray-700)
                },

                // Surfaces — cards, panels, table rows. Maps to the gray-900 /
                // gray-800 / gray-700 family but with a slightly cooler tone
                // to harmonize with the ink scale.
                surface: {
                    900: '#0a0a0f', // card background on dark page (was #0a0a0a / #0a0a0f)
                    800: '#1a1d27', // card background on light page
                    700: '#252836', // hover state on cards
                },
            },

            // ── ITERATION-2: Premium shadows ─────────────────────────────────
            boxShadow: {
                // The purple hover glow used by `.card-lift`. Previously
                // inlined as `0 8px 32px rgba(139,92,246,0.12)` in app.blade.php.
                glow: '0 8px 32px rgba(139, 92, 246, 0.12)',
                // Card elevation — softer than the default `shadow-lg`.
                'card': '0 1px 3px rgba(0, 0, 0, 0.4), 0 1px 2px rgba(0, 0, 0, 0.2)',
                'card-hover': '0 8px 24px rgba(0, 0, 0, 0.5)',
            },

            // ── ITERATION-2: Animation keyframes ──────────────────────────────
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
                // Skeleton shimmer — used by <x-skeleton>.
                shimmer: {
                    '0%':   { backgroundPosition: '-200% 0' },
                    '100%': { backgroundPosition: '200% 0' },
                },
            },

            // ── ITERATION-2: Page container ───────────────────────────────────
            // The consistent page-width pattern used by every admin page.
            // Replaces the ad-hoc `max-w-7xl mx-auto px-4 sm:px-6 lg:px-8`.
            maxWidth: {
                page: '80rem', // 1280px — slightly wider than max-w-7xl (which is 80rem)
            },
        },
    },

    plugins: [forms],
};
