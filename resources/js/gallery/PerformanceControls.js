// ─────────────────────────────────────────────────────────────────────────────
// PerformanceControls — FPS counter + quality toggle for the gallery viewer
//
// Shows a small floating panel in the top-left corner with:
//   - Real-time FPS (updated every 500ms)
//   - Quality selector: Auto / High / Medium / Low
//   - Active lights count (informational)
//
// The panel is always visible on the gallery viewer. In preview mode
// (?preview=1), it also shows a "Save Settings" button that POSTs the
// current quality preference to the server.
//
// Quality levels:
//   High:   pixelRatio=1.5, bloom on,  max 8 active lights, HDRI on
//   Medium: pixelRatio=1.25, bloom on,  max 6 active lights, HDRI on
//   Low:    pixelRatio=1.0,  bloom off, max 4 active lights, HDRI off
//   Auto:   uses detectLowEnd() result (high-end → High, low-end → Low)
// ─────────────────────────────────────────────────────────────────────────────

const QUALITY_LEVELS = {
    high:   { pixelRatio: 1.5,  bloom: true,  maxLights: 8, hdri: true,  label: 'High' },
    medium: { pixelRatio: 1.25, bloom: true,  maxLights: 6, hdri: true,  label: 'Medium' },
    low:    { pixelRatio: 1.0,  bloom: false, maxLights: 4, hdri: false, label: 'Low' },
    // PERF-B7 (3D audit F7): 'auto' resolves to this on touch-primary devices.
    // Bloom is the expensive part of the composer chain (5 fullscreen
    // passes); at phone canvas sizes its absence is genuinely hard to notice,
    // while its presence costs ~20-30% frame time on mid-range GPUs.
    mobile: { pixelRatio: 1.25, bloom: false, maxLights: 4, hdri: false, label: 'Mobile' },
};

export class PerformanceControls {
    constructor(scene) {
        this.scene = scene;
        this._frames = 0;
        this._lastFpsUpdate = performance.now();
        this._currentFps = 0;
        this._quality = this._loadSavedQuality() || 'auto';

        // (Task H37 / audit C4) — only show the performance panel when
        // ?debug=1 is in the URL. Previously it was visible to every
        // visitor, overlapping the in-gallery title.
        this._debugMode = window.EXOSPACE_DEBUG === true;

        // Always apply the quality setting (even if the panel is hidden)
        this._applyQuality(this._quality);

        // Only create the visible panel in debug mode
        if (this._debugMode) {
            this._createPanel();
        }

        // Always hook into the animate loop for FPS counting
        const origAnimate = scene.animate.bind(scene);
        scene.animate = () => {
            origAnimate();
            this._tick();
        };
    }

    _createPanel() {
        const panel = document.createElement('div');
        panel.id = 'perf-panel';
        panel.style.cssText = `
            position: fixed; top: 12px; left: 12px; z-index: 150;
            background: rgba(0,0,0,0.75); backdrop-filter: blur(8px);
            border: 1px solid rgba(139,92,246,0.3); border-radius: 8px;
            padding: 8px 12px; color: #e5e7eb; font-family: monospace;
            font-size: 11px; line-height: 1.6; pointer-events: auto;
            min-width: 120px; user-select: none;
        `;

        panel.innerHTML = `
            <div style="display:flex; align-items:center; gap:6px; margin-bottom:4px;">
                <span style="color:#8b5cf6; font-weight:700;">FPS</span>
                <span id="perf-fps" style="color:#4ade80; font-weight:700; font-size:13px;">--</span>
            </div>
            <div style="display:flex; align-items:center; gap:4px;">
                <span style="color:#6b7280;">Q:</span>
                <select id="perf-quality" style="background:rgba(0,0,0,0.5); color:#e5e7eb; border:1px solid rgba(139,92,246,0.3); border-radius:4px; padding:1px 4px; font-size:10px; font-family:monospace; cursor:pointer;">
                    <option value="auto">Auto</option>
                    <option value="high">High</option>
                    <option value="medium">Medium</option>
                    <option value="low">Low</option>
                </select>
            </div>
            <div id="perf-lights" style="color:#6b7280; font-size:10px; margin-top:2px;">Lights: --</div>
        `;

        document.body.appendChild(panel);

        this._fpsEl = panel.querySelector('#perf-fps');
        this._lightsEl = panel.querySelector('#perf-lights');
        this._qualitySelect = panel.querySelector('#perf-quality');
        this._qualitySelect.value = this._quality;
        this._qualitySelect.addEventListener('change', (e) => {
            this._quality = e.target.value;
            this._saveQuality(this._quality);
            this._applyQuality(this._quality);
        });
    }

    _tick() {
        this._frames++;
        const now = performance.now();
        const elapsed = now - this._lastFpsUpdate;
        if (elapsed >= 500) {
            this._currentFps = Math.round((this._frames * 1000) / elapsed);
            this._frames = 0;
            this._lastFpsUpdate = now;
            this._updateDisplay();
        }
    }

    _updateDisplay() {
        if (this._fpsEl) {
            // Color: green ≥50, yellow 30-49, red <30
            const fps = this._currentFps;
            this._fpsEl.textContent = fps;
            if (fps >= 50)      this._fpsEl.style.color = '#4ade80';
            else if (fps >= 30) this._fpsEl.style.color = '#fbbf24';
            else                this._fpsEl.style.color = '#f87171';
        }
        // PERF-B2: artwork lights are now a fixed pool assigned to the nearest
        // pieces — report assigned/total instead of scanning every artwork's
        // (now non-existent) per-artwork light.
        if (this._lightsEl) {
            const pool = this.scene._lightPool;
            if (pool) {
                let active = 0;
                for (const l of pool) if (l.intensity > 0.01) active++;
                this._lightsEl.textContent = `Lights: ${active}/${pool.length}`;
            } else {
                this._lightsEl.textContent = 'Lights: 0';
            }
        }
    }

    _applyQuality(quality) {
        let cfg;
        if (quality === 'auto') {
            // Use the existing detection result
            // PERF-B7: three-way resolution — low-end → Low, mobile tier →
            // Mobile, everything else → High.
            if (this.scene.isLowEnd)          cfg = QUALITY_LEVELS.low;
            else if (this.scene._isMobileTier) cfg = QUALITY_LEVELS.mobile;
            else                               cfg = QUALITY_LEVELS.high;
        } else {
            cfg = QUALITY_LEVELS[quality];
        }
        if (!cfg) return;

        // Pixel ratio
        // PERF-A8 (3D audit F8): clamp to the device's actual devicePixelRatio.
        // Previously this called setPixelRatio(cfg.pixelRatio) directly, so the
        // default 'auto' (→ high = 1.5) FORCED 1.5x rendering on standard
        // DPR-1 desktop monitors — 2.25x the fragment cost for invisible
        // supersampling. On high-DPI screens the cap still applies as intended.
        this.scene.renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, cfg.pixelRatio));

        // Max active lights
        this.scene._maxActiveLights = cfg.maxLights;

        // Bloom
        if (this.scene._postFx) {
            this.scene._postFx.setBloomEnabled(cfg.bloom);
        }

        // HDRI
        if (!cfg.hdri) {
            this.scene._skipHdri = true;
            // NOTE: `this.scene` is the GalleryScene instance — the THREE.Scene
            // is `this.scene.scene`. The old code assigned this.scene.environment
            // (a property that never existed), so disabling HDRI never actually
            // detached a loaded environment map.
            if (this.scene.scene?.environment) {
                this.scene.scene.environment = null;
            }
        } else if (this.scene._skipHdri && !this.scene.isLowEnd) {
            // Re-enable HDRI loading if it was skipped
            this.scene._skipHdri = false;
            this.scene.loadEnvironmentMap();
        }

        console.log(`⚡ Quality set to ${quality} → pixelRatio=${cfg.pixelRatio}, bloom=${cfg.bloom}, maxLights=${cfg.maxLights}, hdri=${cfg.hdri}`);
    }

    _loadSavedQuality() {
        try {
            return localStorage.getItem('exospace_quality') || 'auto';
        } catch {
            return 'auto';
        }
    }

    _saveQuality(quality) {
        try {
            localStorage.setItem('exospace_quality', quality);
        } catch {
            // localStorage might be blocked (private browsing)
        }
    }
}
