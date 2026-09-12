@php
    $overrides = $gallery->visualOverridesArray();
    $previewUrl = route('admin.galleries.preview', $gallery);

    if (!empty($overrides['visual_config'])) {
        foreach (array_keys($overrides['visual_config']) as $lpKey) {
            if (\App\Services\VenueConfigExporter::isVenueOwnedKey((string) $lpKey)) {
                unset($overrides['visual_config'][$lpKey]);
            }
        }
    }
    if (!empty($overrides['material_config'])) {
        foreach (\App\Services\VenueConfigExporter::VENUE_OWNED_MATERIAL_KEYS as $lpOwned) {
            unset($overrides['material_config'][$lpOwned]);
        }
    }
    unset($overrides['post_fx']); // legacy sibling bucket — venue-owned presentation

    $venueDefaults = [
        'wall_height'           => $gallery->venueTemplate?->visual_config['wall_height']           ?? 4,
        'fog_near'              => $gallery->venueTemplate?->visual_config['fog_near']              ?? 10,
        'fog_far'               => $gallery->venueTemplate?->visual_config['fog_far']               ?? 30,
        'fog_color'             => $gallery->venueTemplate?->visual_config['fog_color']             ?? '0x0a0a0a',
        'ambient_intensity'     => $gallery->venueTemplate?->visual_config['ambient_intensity']     ?? 0.20,
        'spot_intensity'        => $gallery->venueTemplate?->visual_config['spot_intensity']        ?? 0.45,
        'tone_mapping_exposure' => $gallery->venueTemplate?->visual_config['tone_mapping_exposure'] ?? 0.50,
    ];

    // Current effective value = override ?? venue default
    $current = [
        'wall_height'           => $overrides['visual_config']['wall_height']           ?? $venueDefaults['wall_height'],
        'fog_near'              => $overrides['visual_config']['fog_near']              ?? $venueDefaults['fog_near'],
        'fog_far'               => $overrides['visual_config']['fog_far']               ?? $venueDefaults['fog_far'],
        'fog_color'             => $overrides['visual_config']['fog_color']             ?? $venueDefaults['fog_color'],
        'ambient_intensity'     => $overrides['visual_config']['ambient_intensity']     ?? $venueDefaults['ambient_intensity'],
        'spot_intensity'        => $overrides['visual_config']['spot_intensity']        ?? $venueDefaults['spot_intensity'],
        'tone_mapping_exposure' => $overrides['visual_config']['tone_mapping_exposure'] ?? $venueDefaults['tone_mapping_exposure'],
        // (s3: retired material/post-fx currents removed with their sliders.)
    ];
@endphp

{{-- The hidden input that gets submitted with the main form --}}
<input type="hidden" name="visual_overrides_json" id="visual_overrides_json" value="{{ json_encode($overrides) }}" />

<div class="mb-6 mt-6 p-6 bg-gray-900/50 rounded-lg border border-gray-600" id="live-preview-panel">
    <div class="flex items-center justify-between mb-4">
        <div>
            <h3 class="text-lg font-semibold text-gray-100 flex items-center gap-2">
                <svg class="w-5 h-5 text-brand-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                Live Preview & Template Controls
            </h3>
            <p class="text-sm text-gray-400 mt-1">The venue template curates the space. Reset strips any leftover override layer; save to persist.</p>
        </div>
        <div class="flex items-center gap-2">
            <button type="button" id="lp-reset-all"
                    class="btn btn-sm btn-secondary">
                Reset all overrides
            </button>
            <button type="button" id="lp-open-public"
                    class="btn btn-sm btn-brand-tint"
                    data-click="openNewTab" data-arg="{{ route('gallery.view', $gallery->slug) }}">
                Open public view
            </button>
        </div>
    </div>

    {{-- Two-column layout: iframe (left) + controls (right) --}}
    <div class="grid grid-cols-1 lg:grid-cols-[1fr_360px] gap-4">

        <div class="relative bg-black rounded-lg overflow-hidden border border-gray-700" style="aspect-ratio: 16/10; min-height: 400px;">
            <iframe id="live-preview-iframe"
                    src="{{ $previewUrl }}"
                    class="w-full h-full"
                    style="border: 0; display: block;"
                    allow="autoplay; fullscreen"
                    title="Live preview of {{ e($gallery->title) }}"></iframe>

            {{-- Overlay shown until the iframe signals ready --}}
            <div id="lp-iframe-overlay" class="absolute inset-0 flex items-center justify-center bg-gray-900/80 backdrop-blur-sm transition-opacity">
                <div class="text-center">
                    <div class="inline-block w-8 h-8 border-2 border-brand-500 border-t-transparent rounded-full animate-spin"></div>
                    <div class="text-xs text-gray-400 mt-3" id="lp-iframe-status">Loading 3D preview…</div>
                </div>
            </div>
        </div>

        <div class="bg-gray-800/60 rounded-lg border border-gray-700 p-4 space-y-5 overflow-y-auto" style="max-height: 700px;">

            <div>
                <div class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-3">Atmosphere</div>

                <p class="text-[11px] leading-relaxed text-gray-500">
                    Lighting, fog and wall architecture are part of the venue's
                    curated design and are managed with the venue template —
                    per-gallery edits here could recompose the venue into
                    something it isn't.
                </p>

                {{-- (retired: wall_height slider) --}}

                {{-- (retired: ambient_intensity slider — venue-owned rig) --}}

                {{-- (retired: spot_intensity slider — venue-owned rig) --}}

                {{-- (retired: tone_mapping_exposure slider — venue-owned rig) --}}

                {{-- (retired: fog_far slider — venue-owned atmosphere) --}}
            </div>

            <div>
                <div class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-3">Colors</div>

                <p class="mt-3 text-[11px] leading-relaxed text-gray-500">
                    The venue's background is part of its curated design and is managed with the venue template.
                </p>
            </div>

            <div>
                <div class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-3">Materials</div>

                <p class="text-[11px] leading-relaxed text-gray-500">
                    Surface materials and finishes are part of the venue's
                    curated design and are managed with the venue template.
                </p>
            </div>

            <div>
                <div class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-3">Post-Processing</div>

                <p class="text-[11px] leading-relaxed text-gray-500">
                    Glow and vignette are part of the venue's curated mood and
                    are managed with the venue template.
                </p>
            </div>

        </div>
    </div>

    {{-- Status line showing how many overrides are active --}}
    <div class="mt-3 text-xs text-gray-500" id="lp-status-line">
        <span id="lp-override-count">0</span> overrides active.
        Click <strong class="text-gray-300">Update Settings</strong> below to save them.
    </div>
</div>

{{-- ── Inline script: postMessage host + slider state management ─────── --}}
<script nonce="@nonce">
// CSP-safe helper for "open in new tab" buttons (replaced inline onclick)
window.openNewTab = function(url, e) { window.open(url, '_blank'); };

(function () {
    const iframe = document.getElementById('live-preview-iframe');
    const overlay = document.getElementById('lp-iframe-overlay');
    const statusEl = document.getElementById('lp-iframe-status');
    const hiddenInput = document.getElementById('visual_overrides_json');
    const overrideCountEl = document.getElementById('lp-override-count');
    const resetAllBtn = document.getElementById('lp-reset-all');

    let state = JSON.parse(hiddenInput.value || '{}');
    if (!state.visual_config)   state.visual_config = {};
    if (!state.material_config) state.material_config = {};
    if (!state.post_fx)         state.post_fx = {};

    const structuralKeys = new Set(['wall_height']); // others can be added

    let iframeReady = false;
    let pendingReload = false;

    function syncHidden() {
        const clean = {};
        ['visual_config', 'material_config', 'post_fx'].forEach(bucket => {
            const filtered = {};
            Object.entries(state[bucket] || {}).forEach(([k, v]) => {
                if (v !== null && v !== undefined && v !== '') filtered[k] = v;
            });
            if (Object.keys(filtered).length) clean[bucket] = filtered;
        });
        hiddenInput.value = JSON.stringify(clean);
        const total = (clean.visual_config ? Object.keys(clean.visual_config).length : 0)
                    + (clean.material_config ? Object.keys(clean.material_config).length : 0)
                    + (clean.post_fx ? Object.keys(clean.post_fx).length : 0);
        overrideCountEl.textContent = total;
        // Mark dirty so the unsaved-changes guard catches a navigation
        if (typeof dirty !== 'undefined') dirty = true;
    }

    // ── Send a live patch to the iframe (no reload) ─────────────────────
    function sendPatch(group, key, value) {
        if (!iframeReady || !iframe.contentWindow) return;
        const patch = { [group]: { [key]: value } };
        iframe.contentWindow.postMessage({
            type: 'exospace-preview-patch',
            patch,
        }, window.location.origin);
    }

    // ── Reload the iframe with the full state baked into the URL ────────
    let _reloadTimer = null;
    function scheduleReload() {
        clearTimeout(_reloadTimer);
        _reloadTimer = setTimeout(() => {
            if (!iframe.contentWindow) return;
            iframe.contentWindow.postMessage({
                type: 'exospace-preview-reload',
                overrides: state,
            }, window.location.origin);
            // The iframe will reload itself; show the loading overlay again.
            iframeReady = false;
            if (overlay) overlay.style.opacity = '1';
            if (statusEl) statusEl.textContent = 'Reloading with new structural config…';
        }, 400); // debounce 400ms so dragging a slider doesn't spam reloads
    }

    // ── Listen for ready/pong messages from the iframe ──────────────────
    window.addEventListener('message', (e) => {
        if (e.origin !== window.location.origin) return;
        const msg = e.data;
        if (!msg || typeof msg !== 'object') return;

        if (msg.type === 'exospace-preview-ready') {
            iframeReady = true;
            if (overlay) {
                overlay.style.opacity = '0';
                overlay.style.pointerEvents = 'none';
            }
            Object.entries(state.visual_config || {}).forEach(([k, v]) => sendPatch('visual_config', k, v));
            Object.entries(state.material_config || {}).forEach(([k, v]) => sendPatch('material_config', k, v));
            Object.entries(state.post_fx || {}).forEach(([k, v]) => sendPatch('post_fx', k, v));
        }
        if (msg.type === 'exospace-preview-pong' && msg.ready) {
            iframeReady = true;
            if (overlay) {
                overlay.style.opacity = '0';
                overlay.style.pointerEvents = 'none';
            }
        }
    });

    // ── Wire up every slider + color picker in the panel ────────────────
    document.querySelectorAll('[data-lp-control]').forEach(ctrl => {
        const key = ctrl.dataset.lpKey;
        const group = ctrl.dataset.lpGroup;
        const requiresReload = ctrl.dataset.lpRequiresReload === 'true';
        const defaultVal = ctrl.dataset.lpDefault;
        const resetBtn = document.querySelector(`[data-lp-reset-for="${key}"]`);
        const valueLabel = document.querySelector(`[data-lp-value-for="${key}"]`);

        const updateValueDisplay = (v) => {
            if (valueLabel && ctrl.type !== 'color') {
                // Numeric formatting
                const num = parseFloat(v);
                valueLabel.textContent = Number.isInteger(num) ? num.toString() : num.toFixed(2);
            }
        };

        // Initial display
        updateValueDisplay(ctrl.value);

        let _patchTimer = null;
        ctrl.addEventListener('input', () => {
            const raw = ctrl.value;
            const value = ctrl.type === 'color'
                ? '0x' + raw.slice(1).toUpperCase()
                : (ctrl.step && ctrl.step.includes('.') ? parseFloat(raw) : parseInt(raw, 10));

            state[group] = state[group] || {};
            state[group][key] = value;
            updateValueDisplay(raw);
            syncHidden();

            if (requiresReload) {
                scheduleReload();
            } else {
                clearTimeout(_patchTimer);
                _patchTimer = setTimeout(() => sendPatch(group, key, value), 80);
            }
        });

        if (resetBtn) {
            resetBtn.addEventListener('click', () => {
                state[group] = state[group] || {};
                state[group][key] = null;
                ctrl.value = defaultVal;
                updateValueDisplay(defaultVal);
                syncHidden();
                if (requiresReload) scheduleReload();
                else sendPatch(group, key, null);
            });
        }
    });

    if (resetAllBtn) {
        resetAllBtn.addEventListener('click', (e) => {
            window.exospaceConfirm(e, 'Reset all visual overrides to the venue defaults? This affects all controls in this panel.').then((ok) => {
                if (!ok) return;
                resetOverrides();
            });
        });

        function resetOverrides() {
            state = { visual_config: {}, material_config: {}, post_fx: {} };
            syncHidden();
            // Reload the iframe fresh so no stale patches linger
            scheduleReload();
            // Reset all slider positions to their data-lp-default values
            document.querySelectorAll('[data-lp-control]').forEach(ctrl => {
                ctrl.value = ctrl.dataset.lpDefault;
                const valueLabel = document.querySelector(`[data-lp-value-for="${ctrl.dataset.lpKey}"]`);
                if (valueLabel && ctrl.type !== 'color') {
                    const num = parseFloat(ctrl.dataset.lpDefault);
                    valueLabel.textContent = Number.isInteger(num) ? num.toString() : num.toFixed(2);
                }
            });
        }
    }

    // ── Initial sync so the override count + hidden input are correct ──
    syncHidden();
})();
</script>
