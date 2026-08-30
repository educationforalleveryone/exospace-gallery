@php
    /**
     * Live Preview Panel — partial included in admin/galleries/edit.blade.php
     *
     * HOW TO USE:
     *   Drop this one line anywhere inside the edit page where you want the
     *   Live Preview panel to appear (typically just below the existing
     *   "Override venue materials" collapsible, or as its own section):
     *
     *       @include('admin.galleries.live-preview-panel', ['gallery' => $gallery])
     *
     *   The partial expects $gallery to be the Gallery model (already loaded
     *   in the edit view). No other variables needed.
     *
     * WHAT IT RENDERS:
     *   - A two-column layout: iframe (left) + control sidebar (right)
     *   - The iframe loads /admin/galleries/{gallery}/preview
     *   - The sidebar has 3 control groups:
     *       Atmosphere  — wall height, fog, ambient/spot intensity, tone mapping, bg color
     *       Materials   — wall/floor roughness, metalness, color
     *       Post-FX     — bloom strength, vignette darkness
     *   - Each control has:
     *       - Label + live numeric readout
     *       - Slider (or color picker)
     *       - Hover hint card (SVG mini-mockup + textual explanation)
     *       - Reset-to-venue-default button
     *   - Hidden input #visual_overrides_json holds the JSON blob that gets
     *     submitted with the form (GalleryController::update parses it).
     *
     * The slider values are persisted in the URL hash of the iframe so a
     * page refresh doesn't lose them, but the curator has to click
     * "Update Settings" to persist them to the DB.
     */
    $overrides = $gallery->visualOverridesArray();
    $previewUrl = route('admin.galleries.preview', $gallery);

    // Pull venue defaults so we can show them as the "reset target" for each slider.
    // These come from the venue template's visual_config + material_config.
    $venueDefaults = [
        'wall_height'           => $gallery->venueTemplate?->visual_config['wall_height']           ?? 4,
        'fog_near'              => $gallery->venueTemplate?->visual_config['fog_near']              ?? 10,
        'fog_far'               => $gallery->venueTemplate?->visual_config['fog_far']               ?? 30,
        'fog_color'             => $gallery->venueTemplate?->visual_config['fog_color']             ?? '0x0a0a0a',
        'background_color'      => $gallery->venueTemplate?->visual_config['background_color']      ?? '0x0a0a0a',
        'ambient_intensity'     => $gallery->venueTemplate?->visual_config['ambient_intensity']     ?? 0.20,
        'spot_intensity'        => $gallery->venueTemplate?->visual_config['spot_intensity']        ?? 0.45,
        'tone_mapping_exposure' => $gallery->venueTemplate?->visual_config['tone_mapping_exposure'] ?? 0.50,
        'wall_roughness'        => $gallery->venueTemplate?->material_config['wall_roughness']      ?? 0.85,
        'wall_metalness'        => $gallery->venueTemplate?->material_config['wall_metalness']      ?? 0.00,
        'floor_roughness'       => $gallery->venueTemplate?->material_config['floor_roughness']     ?? 0.70,
        'floor_metalness'       => $gallery->venueTemplate?->material_config['floor_metalness']     ?? 0.10,
        'bloom_strength'        => 0.6,
        'vignette_darkness'     => 0.5,
    ];

    // Current effective value = override ?? venue default
    $current = [
        'wall_height'           => $overrides['visual_config']['wall_height']           ?? $venueDefaults['wall_height'],
        'fog_near'              => $overrides['visual_config']['fog_near']              ?? $venueDefaults['fog_near'],
        'fog_far'               => $overrides['visual_config']['fog_far']               ?? $venueDefaults['fog_far'],
        'fog_color'             => $overrides['visual_config']['fog_color']             ?? $venueDefaults['fog_color'],
        'background_color'      => $overrides['visual_config']['background_color']      ?? $venueDefaults['background_color'],
        'ambient_intensity'     => $overrides['visual_config']['ambient_intensity']     ?? $venueDefaults['ambient_intensity'],
        'spot_intensity'        => $overrides['visual_config']['spot_intensity']        ?? $venueDefaults['spot_intensity'],
        'tone_mapping_exposure' => $overrides['visual_config']['tone_mapping_exposure'] ?? $venueDefaults['tone_mapping_exposure'],
        'wall_roughness'        => $overrides['material_config']['wall_roughness']      ?? $venueDefaults['wall_roughness'],
        'wall_metalness'        => $overrides['material_config']['wall_metalness']      ?? $venueDefaults['wall_metalness'],
        'floor_roughness'       => $overrides['material_config']['floor_roughness']     ?? $venueDefaults['floor_roughness'],
        'floor_metalness'       => $overrides['material_config']['floor_metalness']     ?? $venueDefaults['floor_metalness'],
        'bloom_strength'        => $overrides['post_fx']['bloom_strength']              ?? $venueDefaults['bloom_strength'],
        'vignette_darkness'     => $overrides['post_fx']['vignette_darkness']           ?? $venueDefaults['vignette_darkness'],
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
            <p class="text-sm text-gray-400 mt-1">Tweak atmosphere, materials, and post-processing in real time. Save to persist.</p>
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

        {{-- ── LEFT: Preview iframe ──────────────────────────────────────── --}}
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

        {{-- ── RIGHT: Control sidebar ────────────────────────────────────── --}}
        <div class="bg-gray-800/60 rounded-lg border border-gray-700 p-4 space-y-5 overflow-y-auto" style="max-height: 700px;">

            {{-- ── Atmosphere group ──────────────────────────────────────── --}}
            <div>
                <div class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-3">Atmosphere</div>

                @include('admin.galleries.live-preview-panel._slider', [
                    'id' => 'wall_height',
                    'label' => 'Wall Height',
                    'unit' => 'm',
                    'min' => 2.5, 'max' => 10, 'step' => 0.5,
                    'value' => $current['wall_height'],
                    'default' => $venueDefaults['wall_height'],
                    'group' => 'visual_config',
                    'requiresReload' => true,
                    'hint' => 'Taller walls feel grander (museum-scale). Lower walls feel intimate (private studio).',
                    'hintSvg' => 'wall_height',
                ])

                @include('admin.galleries.live-preview-panel._slider', [
                    'id' => 'ambient_intensity',
                    'label' => 'Ambient Light',
                    'unit' => '',
                    'min' => 0, 'max' => 0.6, 'step' => 0.01,
                    'value' => $current['ambient_intensity'],
                    'default' => $venueDefaults['ambient_intensity'],
                    'group' => 'visual_config',
                    'requiresReload' => false,
                    'hint' => 'Flat fill light. Higher = brighter but flatter; lower = moodier with more shadow contrast.',
                    'hintSvg' => 'ambient',
                ])

                @include('admin.galleries.live-preview-panel._slider', [
                    'id' => 'spot_intensity',
                    'label' => 'Artwork Spot Light',
                    'unit' => '',
                    'min' => 0, 'max' => 1.2, 'step' => 0.01,
                    'value' => $current['spot_intensity'],
                    'default' => $venueDefaults['spot_intensity'],
                    'group' => 'visual_config',
                    'requiresReload' => false,
                    'hint' => 'How strongly each artwork is lit. Higher = pieces "pop"; lower = they blend into the room.',
                    'hintSvg' => 'spot',
                ])

                @include('admin.galleries.live-preview-panel._slider', [
                    'id' => 'tone_mapping_exposure',
                    'label' => 'Tone Mapping Exposure',
                    'unit' => '',
                    'min' => 0.1, 'max' => 1.5, 'step' => 0.05,
                    'value' => $current['tone_mapping_exposure'],
                    'default' => $venueDefaults['tone_mapping_exposure'],
                    'group' => 'visual_config',
                    'requiresReload' => false,
                    'hint' => 'Global brightness multiplier applied after lighting. Like a camera exposure — affects highlights first.',
                    'hintSvg' => 'exposure',
                ])

                @include('admin.galleries.live-preview-panel._slider', [
                    'id' => 'fog_far',
                    'label' => 'Fog Distance',
                    'unit' => 'm',
                    'min' => 8, 'max' => 60, 'step' => 1,
                    'value' => $current['fog_far'],
                    'default' => $venueDefaults['fog_far'],
                    'group' => 'visual_config',
                    'requiresReload' => false,
                    'hint' => 'How far you can see before fog hides everything. Lower = claustrophobic; higher = open + distant.',
                    'hintSvg' => 'fog',
                ])
            </div>

            {{-- ── Colors group ──────────────────────────────────────────── --}}
            <div>
                <div class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-3">Colors</div>

                @include('admin.galleries.live-preview-panel._color', [
                    'id' => 'background_color',
                    'label' => 'Background',
                    'value' => $current['background_color'],
                    'default' => $venueDefaults['background_color'],
                    'group' => 'visual_config',
                    'hint' => 'The void color outside the room. Visible through windows + beyond the fog. Black feels infinite.',
                ])

                @include('admin.galleries.live-preview-panel._color', [
                    'id' => 'fog_color',
                    'label' => 'Fog Tint',
                    'value' => $current['fog_color'],
                    'default' => $venueDefaults['fog_color'],
                    'group' => 'visual_config',
                    'hint' => 'Color of the haze. Match the background for a seamless fade, or contrast it for a coloured atmosphere.',
                ])
            </div>

            {{-- ── Materials group ───────────────────────────────────────── --}}
            <div>
                <div class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-3">Materials</div>

                @include('admin.galleries.live-preview-panel._slider', [
                    'id' => 'wall_roughness',
                    'label' => 'Wall Roughness',
                    'unit' => '',
                    'min' => 0, 'max' => 1, 'step' => 0.01,
                    'value' => $current['wall_roughness'],
                    'default' => $venueDefaults['wall_roughness'],
                    'group' => 'material_config',
                    'requiresReload' => false,
                    'hint' => 'How matte the walls are. 0 = mirror-polished (reflects HDRI); 1 = chalk-flat (no reflection).',
                    'hintSvg' => 'roughness',
                ])

                @include('admin.galleries.live-preview-panel._slider', [
                    'id' => 'wall_metalness',
                    'label' => 'Wall Metalness',
                    'unit' => '',
                    'min' => 0, 'max' => 1, 'step' => 0.01,
                    'value' => $current['wall_metalness'],
                    'default' => $venueDefaults['wall_metalness'],
                    'group' => 'material_config',
                    'requiresReload' => false,
                    'hint' => 'How metallic the wall surface reads. 0 = dielectric (paint, plaster); 1 = bare metal.',
                    'hintSvg' => 'metalness',
                ])

                @include('admin.galleries.live-preview-panel._slider', [
                    'id' => 'floor_roughness',
                    'label' => 'Floor Roughness',
                    'unit' => '',
                    'min' => 0, 'max' => 1, 'step' => 0.01,
                    'value' => $current['floor_roughness'],
                    'default' => $venueDefaults['floor_roughness'],
                    'group' => 'material_config',
                    'requiresReload' => false,
                    'hint' => 'Lower = polished + reflective (mirror marble). Higher = matte (raw concrete, carpet).',
                    'hintSvg' => 'roughness',
                ])

                @include('admin.galleries.live-preview-panel._slider', [
                    'id' => 'floor_metalness',
                    'label' => 'Floor Metalness',
                    'unit' => '',
                    'min' => 0, 'max' => 1, 'step' => 0.01,
                    'value' => $current['floor_metalness'],
                    'default' => $venueDefaults['floor_metalness'],
                    'group' => 'material_config',
                    'requiresReload' => false,
                    'hint' => 'Metallic floor reads as brushed steel at high values. Pair with low roughness for a mirror.',
                    'hintSvg' => 'metalness',
                ])
            </div>

            {{-- ── Post-FX group ─────────────────────────────────────────── --}}
            <div>
                <div class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-3">Post-Processing</div>

                @include('admin.galleries.live-preview-panel._slider', [
                    'id' => 'bloom_strength',
                    'label' => 'Bloom Glow',
                    'unit' => '',
                    'min' => 0, 'max' => 2, 'step' => 0.05,
                    'value' => $current['bloom_strength'],
                    'default' => $venueDefaults['bloom_strength'],
                    'group' => 'post_fx',
                    'requiresReload' => false,
                    'hint' => 'How brightly highlights bleed into neighbours. 0 = none (clinical); 1 = soft photographic glow.',
                    'hintSvg' => 'bloom',
                ])

                @include('admin.galleries.live-preview-panel._slider', [
                    'id' => 'vignette_darkness',
                    'label' => 'Vignette Darkness',
                    'unit' => '',
                    'min' => 0, 'max' => 1, 'step' => 0.05,
                    'value' => $current['vignette_darkness'],
                    'default' => $venueDefaults['vignette_darkness'],
                    'group' => 'post_fx',
                    'requiresReload' => false,
                    'hint' => 'How dark the screen corners get. Subtle vignette focuses attention on the centre.',
                    'hintSvg' => 'vignette',
                ])
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

    // The current overrides state. Initialised from the hidden input (which
    // was hydrated from $gallery->visual_overrides by the blade).
    let state = JSON.parse(hiddenInput.value || '{}');
    if (!state.visual_config)   state.visual_config = {};
    if (!state.material_config) state.material_config = {};
    if (!state.post_fx)         state.post_fx = {};

    // Track which controls require an iframe reload vs. which can be patched live.
    // Set via the data-requires-reload attribute on each slider.
    const structuralKeys = new Set(['wall_height']); // others can be added

    let iframeReady = false;
    let pendingReload = false;

    // ── Sync hidden input + override count ──────────────────────────────
    function syncHidden() {
        // Strip null/empty values so the controller's parseVisualOverrides
        // can recognise a fully-reset state.
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
            // Apply the current state to the freshly-loaded scene so the
            // preview reflects saved overrides immediately.
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
            // Color pickers give "#aabbcc"; the viewer's parseColor accepts
            // both #aabbcc and 0xaabbcc. Coerce to 0x form so it round-trips
            // through the JSON column consistently.
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
                // Debounce live patches at 80ms so a fast drag doesn't
                // flood the iframe with postMessages.
                clearTimeout(_patchTimer);
                _patchTimer = setTimeout(() => sendPatch(group, key, value), 80);
            }
        });

        if (resetBtn) {
            resetBtn.addEventListener('click', () => {
                // Reset to venue default — set state[key] = null so the
                // exporter strips it and the venue default takes over.
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

    // ── Reset all overrides ─────────────────────────────────────────────
    if (resetAllBtn) {
        resetAllBtn.addEventListener('click', (e) => {
            // ITERATION-4: native confirm() replaced by the styled kernel dialog
            // (window.exospaceConfirm) — one confirm mechanism product-wide.
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
