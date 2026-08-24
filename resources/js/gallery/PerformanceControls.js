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
//
// PERF-F31 (3D audit — iteration 6): REAL-USER PERF TELEMETRY.
// Five iterations of performance work were verified by logic + automated
// tests, but every frame-rate claim lacked field data (Sentry traces are
// disabled). startPerfSampling() — invoked by main.js at the Enter click —
// collects 500 ms FPS samples for 15 s alongside draw calls, triangles,
// pixel ratio (including any adaptive downscale), device tier, JS heap and
// network class, then sends ONE 'perf' beacon through the existing
// /gallery/{id}/track pipeline. Visitors who leave early flush a partial
// sample on pagehide (sendBeacon). One request per engaged visit — the
// 30/min throttle is untouched.
// ─────────────────────────────────────────────────────────────────────────────

import { Analytics } from './Analytics.js';

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

        // PERF-D25 (3D audit — adaptive resolution): sustained frame-time
        // based DPR scaling. Only active in 'auto' quality mode and never on
        // low-end (which already frame-skips). Scale lives in [0.6, 1.0] of
        // the tier's base pixel ratio; adjustments are small (0.1-0.15),
        // spaced by a 3s cooldown, and require THREE consecutive 500 ms
        // samples on the same side of the band — no oscillation, no visual
        // thrash. The first 6 s after load are exempt (texture uploads +
        // shader warmup would skew the samples).
        this._prScale = 1;
        this._adaptSamples = [];
        this._adaptHoldUntil = performance.now() + 6000;

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
            <div id="perf-draws" style="color:#6b7280; font-size:10px;">Draws: --</div>
            <div id="perf-pr" style="color:#6b7280; font-size:10px;">PR: --</div>
        `;

        document.body.appendChild(panel);

        this._fpsEl = panel.querySelector('#perf-fps');
        this._lightsEl = panel.querySelector('#perf-lights');
        this._drawsEl = panel.querySelector('#perf-draws');
        this._prEl = panel.querySelector('#perf-pr');
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
            this._maybeAdapt(this._currentFps, now);
            this._maybeSamplePerf(this._currentFps);
        }
    }

    // ── PERF-F31: real-user perf sampling ─────────────────────────────────
    // Started at the Enter click (engaged session). Collects 30 × 500 ms FPS
    // samples (~15 s), then fires one beacon. pagehide flushes a partial
    // sample once ≥ 5 samples exist (sendBeacon — survives unload).
    startPerfSampling(enterMs) {
        if (this._perfState) return; // once per page load
        this._perfState = {
            enterMs: enterMs || Math.round(performance.now()),
            samples: [],
            sent: false,
            listener: () => this._flushPerf(true),
        };
        window.addEventListener('pagehide', this._perfState.listener, { once: true });
    }

    _maybeSamplePerf(fps) {
        if (!this._perfState || this._perfState.sent) return;
        this._perfState.samples.push(fps);
        if (this._perfState.samples.length >= 30) {
            this._flushPerf(false);
        }
    }

    _flushPerf(early) {
        const st = this._perfState;
        if (!st || st.sent) return;
        // Early flush needs enough data to mean anything (≥ 2.5 s).
        if (early && st.samples.length < 5) return;

        st.sent = true;
        window.removeEventListener('pagehide', st.listener);

        const s = st.samples;
        const scene = this.scene;
        const info = scene.renderer?.info;
        const conn = navigator.connection || navigator.mozConnection || navigator.webkitConnection;

        Analytics.send('perf', {
            perf: {
                tier: scene.isLowEnd ? 'low' : (scene._isMobileTier ? 'mobile' : 'high'),
                q: String(this._quality).slice(0, 8),
                fps: s.length ? Math.round(s.reduce((a, b) => a + b, 0) / s.length) : null,
                fps_min: s.length ? Math.min(...s) : null,
                draws: info?.render?.calls ?? null,
                tris: info?.render ? Math.round(info.render.triangles / 1000) : null,
                pr: scene.renderer?.getPixelRatio ? Math.round(scene.renderer.getPixelRatio() * 100) / 100 : null,
                adapt: this._prScale ?? 1,
                n: scene.artworks?.length ?? null,
                heap: performance.memory ? Math.round(performance.memory.usedJSHeapSize / 1048576) : null,
                net: conn?.effectiveType ? String(conn.effectiveType).slice(0, 8) : null,
                ms: st.enterMs,
                partial: early ? 1 : 0,
            },
        });
    }

    /**
     * PERF-D25 — adaptive resolution.
     * Sustained frame-rate feedback loop: 3 consecutive 500 ms samples all
     * below 26 fps → shrink the render scale by 0.15 (floor 0.6); 3 samples
     * all above 55 fps → grow by 0.1 (ceiling 1.0). 3 s cooldown after each
     * change. Never fights an explicit user quality choice, never runs on
     * low-end (frame-skip owns that tier), skipped for the first 6 s after
     * load while textures upload and shaders warm up.
     */
    _maybeAdapt(fps, now) {
        if (this._quality !== 'auto') return;
        if (this.scene.isLowEnd) return;
        if (now < this._adaptHoldUntil) {
            this._adaptSamples.length = 0;
            return;
        }

        this._adaptSamples.push(fps);
        if (this._adaptSamples.length < 3) return;

        const s = this._adaptSamples;
        this._adaptSamples = [];

        if (s.every(f => f < 26) && this._prScale > 0.6) {
            this._prScale = Math.max(0.6, this._prScale - 0.15);
            this._applyPixelRatio();
            this._adaptHoldUntil = now + 3000;
            console.log(`⚡ Adaptive resolution: ${s.join('/')} fps → render scale ${this._prScale.toFixed(2)}`);
        } else if (s.every(f => f > 55) && this._prScale < 1) {
            this._prScale = Math.min(1, this._prScale + 0.1);
            this._applyPixelRatio();
            this._adaptHoldUntil = now + 3000;
            console.log(`⚡ Adaptive resolution: ${s.join('/')} fps → render scale ${this._prScale.toFixed(2)}`);
        }
    }

    _applyPixelRatio() {
        if (!this._basePR) return;
        this.scene.renderer.setPixelRatio(this._basePR * this._prScale);
        // Keep the post-processing chain's render targets at the same
        // resolution (PERF-D25 — see PostProcessing.syncPixelRatio).
        this.scene._postFx?.syncPixelRatio?.();
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
        // PERF-D24: true per-frame draw calls + triangles (counters accumulate
        // across composer passes; GalleryScene resets them once per frame).
        if (this._drawsEl) {
            const info = this.scene.renderer?.info;
            if (info) {
                this._drawsEl.textContent = `Draws: ${info.render.calls} · Tris: ${(info.render.triangles / 1000).toFixed(1)}k`;
            }
        }
        if (this._prEl) {
            const pr = this.scene.renderer?.getPixelRatio?.();
            if (pr) this._prEl.textContent = `PR: ${pr.toFixed(2)}${this._prScale < 1 ? ` (adapt ${this._prScale.toFixed(2)})` : ''}`;
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

        // Pixel ratio — PERF-D25: capture the tier base, then apply through
        // the adaptive path (scale 1.0 initially).
        // PERF-A8 (3D audit F8): clamp to the device's actual devicePixelRatio.
        // Previously this called setPixelRatio(cfg.pixelRatio) directly, so the
        // default 'auto' (→ high = 1.5) FORCED 1.5x rendering on standard
        // DPR-1 desktop monitors — 2.25x the fragment cost for invisible
        // supersampling. On high-DPI screens the cap still applies as intended.
        this._basePR = Math.min(window.devicePixelRatio || 1, cfg.pixelRatio);
        this._prScale = 1;
        this._applyPixelRatio();

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
