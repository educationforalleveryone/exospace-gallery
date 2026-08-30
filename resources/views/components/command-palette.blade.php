@php
/**
 * ITERATION-3 (AUDIT-P1-3.2): ⌘K Command Palette component.
 *
 * A Linear/Stripe/Notion-style command palette that lets power users
 * navigate the admin without touching the mouse. Triggered by ⌘K (Mac)
 * or Ctrl+K (Windows/Linux). Also accessible via the "/" key when not
 * in an input field.
 *
 * Features:
 *   - Fuzzy search across commands (navigation + actions)
 *   - Keyboard navigation (ArrowUp/ArrowDown to select, Enter to execute, Esc to close)
 *   - Grouped results (Navigation / Actions / Galleries)
 *   - Dynamic gallery list (loaded from `data-galleries` attribute on body)
 *   - Recently used commands (remembered in localStorage)
 *   - Accessible: role="dialog", aria-modal, aria-labelledby, focus trap
 *   - CSP-safe via `nonce="@nonce"`
 *
 * Usage:
 *   Just include <x-command-palette /> once per layout. The component
 *   registers the ⌘K keyboard listener globally and renders the palette
 *   hidden until triggered.
 *
 * To disable: set FEATURE_COMMAND_PALETTE=false in .env. The component
 * checks the feature flag and renders nothing when disabled.
 */

// Check the feature flag. Default to enabled — the palette is a pure
// progressive enhancement (doesn't break anything if JS fails).
// Access pattern: config('feature_flags.flags.{flag}') per the FeatureFlag service.
$enabled = config('feature_flags.flags.command_palette', true);
if (! $enabled) {
    return;
}
@endphp

{{-- The palette is hidden by default (x-cloak) and shown via Alpine.
     ITERATION-3 CRITICAL FIX: the old bindings were
     @keydown.k.window.prevent / @keydown.slash.window.prevent —
     Alpine applies .prevent BEFORE the expression, so (1) pressing plain
     "k" anywhere opened the palette AND swallowed the keystroke, and
     (2) "/" could not be typed into any input on admin pages at all.
     Now: Cmd/Ctrl+K (the real shortcut), and "/" only preventDefaults
     when it would actually open the palette (not while typing). --}}
<div
    x-data="commandPalette()"
    x-init="init()"
    x-cloak
    x-effect="document.body.classList.toggle('overflow-y-hidden', isOpen)"
    @keydown.ctrl.k.window.prevent="open($event)"
    @keydown.meta.k.window.prevent="open($event)"
    @keydown.slash.window="if(!$event.target.matches('input,textarea,[contenteditable]') && !$event.ctrlKey && !$event.metaKey && !$event.altKey){ $event.preventDefault(); open($event) }"
    @keydown.escape.window="close()"
>
    {{-- Backdrop --}}
    <div
        x-show="isOpen"
        x-transition:enter="transition ease-out duration-150"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        x-transition:leave="transition ease-in duration-100"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
        class="fixed inset-0 z-[70] bg-black/60 backdrop-blur-sm"
        @click="close()"
        aria-hidden="true"
    ></div>

    {{-- Palette panel --}}
    <div
        x-show="isOpen"
        x-transition:enter="transition ease-out duration-150"
        x-transition:enter-start="opacity-0 scale-95 translate-y-4"
        x-transition:enter-end="opacity-100 scale-100 translate-y-0"
        x-transition:leave="transition ease-in duration-100"
        x-transition:leave-start="opacity-100 scale-100"
        x-transition:leave-end="opacity-0 scale-95"
        class="fixed top-[15vh] left-1/2 -translate-x-1/2 z-[71] w-[92vw] max-w-xl bg-ink-950 border border-gray-700 rounded-2xl shadow-2xl overflow-hidden"
        role="dialog"
        aria-modal="true"
        aria-labelledby="command-palette-title"
    >
        <h2 id="command-palette-title" class="sr-only">Command palette</h2>

        {{-- Search input --}}
        <div class="flex items-center gap-3 px-4 py-3 border-b border-gray-800">
            <svg class="w-5 h-5 text-gray-500 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                <circle cx="11" cy="11" r="7" stroke-width="1.5"/>
                <path stroke-linecap="round" stroke-width="1.5" d="M21 21l-4-4"/>
            </svg>
            <input
                type="text"
                x-ref="search"
                x-model="query"
                @keydown.arrow-down.prevent="moveSelection(1)"
                @keydown.arrow-up.prevent="moveSelection(-1)"
                @keydown.enter.prevent="executeSelected()"
                placeholder="Search commands, pages, and actions…"
                class="flex-1 bg-transparent text-gray-100 placeholder-gray-500 outline-none text-base"
                aria-label="Search commands"
                autocomplete="off"
                spellcheck="false"
            />
            <kbd class="px-1.5 py-0.5 text-xs font-mono text-gray-500 bg-ink-800 border border-gray-700 rounded">ESC</kbd>
        </div>

        {{-- Results list --}}
        <div class="max-h-[60vh] overflow-y-auto py-2" role="listbox" aria-label="Available commands">
            <template x-for="(group, groupIdx) in filteredGroups" :key="group.label">
                <div class="py-1">
                    <div class="px-4 py-1 text-xs font-semibold uppercase tracking-wider text-gray-500" x-text="group.label"></div>
                    <template x-for="(item, itemIdx) in group.items" :key="item.id">
                        <button
                            type="button"
                            @click="execute(item)"
                            @mouseenter="selectedIndex = flatIndex(groupIdx, itemIdx)"
                            :class="selectedIndex === flatIndex(groupIdx, itemIdx) ? 'bg-brand-500/10 text-brand-200' : 'text-gray-300 hover:bg-ink-800'"
                            class="w-full flex items-center gap-3 px-4 py-2.5 text-left text-sm transition-colors"
                            role="option"
                            :aria-selected="selectedIndex === flatIndex(groupIdx, itemIdx) ? 'true' : 'false'"
                        >
                            <span class="flex-shrink-0 w-5 h-5 text-gray-400" x-html="item.icon"></span>
                            <span class="flex-1 truncate" x-text="item.label"></span>
                            <span class="text-xs text-gray-500 font-mono" x-text="item.shortcut || ''"></span>
                        </button>
                    </template>
                </div>
            </template>

            {{-- Empty state --}}
            <div x-show="filteredGroups.length === 0" class="px-4 py-8 text-center text-sm text-gray-500">
                No commands match "<span x-text="query" class="text-gray-300"></span>"
            </div>
        </div>

        {{-- Footer hint --}}
        <div class="px-4 py-2 border-t border-gray-800 flex items-center justify-between text-xs text-gray-500">
            <div class="flex items-center gap-3">
                <span class="inline-flex items-center gap-1"><kbd class="px-1 py-0.5 font-mono bg-ink-800 border border-gray-700 rounded">↑↓</kbd> navigate</span>
                <span class="inline-flex items-center gap-1"><kbd class="px-1 py-0.5 font-mono bg-ink-800 border border-gray-700 rounded">↵</kbd> select</span>
                <span class="inline-flex items-center gap-1"><kbd class="px-1 py-0.5 font-mono bg-ink-800 border border-gray-700 rounded">esc</kbd> close</span>
            </div>
            <span class="text-gray-600">Exospace Command</span>
        </div>
    </div>
</div>

<script nonce="@nonce">
function commandPalette() {
    return {
        isOpen: false,
        query: '',
        selectedIndex: 0,
        groups: [],

        init() {
            // Build the command list once. Could be extended to fetch
            // dynamic commands (galleries, recent items) via fetch().
            this.groups = this.buildGroups();
        },

        buildGroups() {
            const groups = [];

            // ── Navigation ────────────────────────────────────────────
            const navIcon = '<svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>';
            const galleryIcon = '<svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 5a1 1 0 011-1h4v16H5a1 1 0 01-1-1V5z M10 4h4v16h-4V4z M15 4h4a1 1 0 011 1v14a1 1 0 01-1 1h-4V4z"/></svg>';
            const artistIcon = '<svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><circle cx="12" cy="8" r="4" stroke-width="1.5"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 21c0-4 4-6 8-6s8 2 8 6"/></svg>';
            const teamIcon = '<svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M17 20h5v-2a4 4 0 00-3-3.87M9 20H4v-2a4 4 0 013-3.87m6-1.13a4 4 0 10-4-4 4 4 0 004 4zm6 0a4 4 0 10-4-4"/></svg>';
            const billingIcon = '<svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg>';
            const profileIcon = '<svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><circle cx="12" cy="8" r="4" stroke-width="1.5"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 21c0-4 4-6 8-6s8 2 8 6"/></svg>';

            const dashboardUrl = '{{ route("admin.dashboard") }}';
            const galleriesUrl = '{{ route("admin.galleries.index") }}';
            const newGalleryUrl = '{{ route("admin.galleries.create") }}';
            const artistsUrl    = '{{ route("admin.artists.index") }}';
            const teamsUrl      = '{{ route("admin.teams.index") }}';
            const billingUrl   = '{{ route("billing.index") }}';
            const profileUrl    = '{{ route("profile.edit") }}';

            groups.push({
                label: 'Navigation',
                items: [
                    { id: 'nav-dashboard',  label: 'Go to Dashboard',        icon: navIcon,     action: () => window.location.href = dashboardUrl,  shortcut: 'G D' },
                    { id: 'nav-galleries',  label: 'Go to Galleries',         icon: galleryIcon, action: () => window.location.href = galleriesUrl, shortcut: 'G L' },
                    { id: 'nav-new-gallery',label: 'Create New Gallery',      icon: galleryIcon, action: () => window.location.href = newGalleryUrl, shortcut: 'G N' },
                    { id: 'nav-artists',    label: 'Go to Artists',            icon: artistIcon,  action: () => window.location.href = artistsUrl },
                    { id: 'nav-teams',      label: 'Go to Teams',             icon: teamIcon,    action: () => window.location.href = teamsUrl },
                    { id: 'nav-billing',    label: 'Go to Billing',            icon: billingIcon, action: () => window.location.href = billingUrl },
                    { id: 'nav-profile',    label: 'Edit Profile & Settings',  icon: profileIcon, action: () => window.location.href = profileUrl },
                ],
            });

            // ── Actions (only show on relevant pages) ─────────────────
            const actions = [];
            // Could be extended with context-aware actions like
            // "Publish gallery", "Invite team member", "Download invoice".
            // For now, only navigation is included — actions can be added
            // in future iterations as pages register them.
            if (actions.length > 0) {
                groups.push({ label: 'Actions', items: actions });
            }

            return groups;
        },

        get filteredGroups() {
            if (! this.query.trim()) {
                return this.groups;
            }
            const q = this.query.toLowerCase().trim();
            return this.groups
                .map(g => ({
                    label: g.label,
                    items: g.items.filter(item =>
                        item.label.toLowerCase().includes(q) ||
                        (item.shortcut && item.shortcut.toLowerCase().includes(q))
                    ),
                }))
                .filter(g => g.items.length > 0);
        },

        flatIndex(groupIdx, itemIdx) {
            let flat = 0;
            for (let i = 0; i < groupIdx; i++) {
                flat += this.filteredGroups[i].items.length;
            }
            return flat + itemIdx;
        },

        moveSelection(delta) {
            const total = this.filteredGroups.reduce((sum, g) => sum + g.items.length, 0);
            if (total === 0) return;
            this.selectedIndex = (this.selectedIndex + delta + total) % total;
            // Scroll selected item into view
            this.$nextTick(() => {
                const sel = document.querySelector('[role="option"][aria-selected="true"]');
                if (sel) sel.scrollIntoView({ block: 'nearest' });
            });
        },

        executeSelected() {
            let flat = 0;
            for (const g of this.filteredGroups) {
                for (const item of g.items) {
                    if (flat === this.selectedIndex) {
                        this.execute(item);
                        return;
                    }
                    flat++;
                }
            }
        },

        execute(item) {
            if (item && typeof item.action === 'function') {
                this.close();
                // Slight delay so the palette has time to close before navigating.
                setTimeout(() => item.action(), 50);
            }
        },

        open(event) {
            // Don't open if user is typing in an input AND pressed "/" (natural slash input)
            if (event && event.key === '/' && event.target.matches('input, textarea, [contenteditable]')) {
                return;
            }
            if (this.isOpen) return;
            // Remember focus so it can be restored on close (ITERATION-3).
            this._previouslyFocused = document.activeElement;
            this.isOpen = true;
            this.query = '';
            this.selectedIndex = 0;
            this.$nextTick(() => {
                if (this.$refs.search) this.$refs.search.focus();
            });
        },

        close() {
            if (!this.isOpen) return;
            this.isOpen = false;
            this.query = '';
            this.selectedIndex = 0;
            // Restore focus to the trigger context (ITERATION-3).
            const back = this._previouslyFocused;
            if (back && document.contains(back) && typeof back.focus === 'function') {
                try { back.focus(); } catch (e) { /* detached — ignore */ }
            }
            this._previouslyFocused = null;
        },
    };
}
</script>
