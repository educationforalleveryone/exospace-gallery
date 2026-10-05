import { Analytics } from './Analytics.js';
import { createGovernorCore, DEFAULT_LADDER } from './GovernorCore.js';

const QUALITY_LEVELS = {
    high:   { pixelRatio: 1.5,  bloom: true,  maxLights: 8, hdri: true,  label: 'High' },
    medium: { pixelRatio: 1.25, bloom: true,  maxLights: 6, hdri: true,  label: 'Medium' },
    low:    { pixelRatio: 1.0,  bloom: false, maxLights: 4, hdri: false, label: 'Low' },
    mobile: { pixelRatio: 1.25, bloom: false, maxLights: 4, hdri: false, label: 'Mobile' },
};

/**
 * Governor ladder → renderer settings. Index 0 is full quality, the last
 * entry is the floor. Each step below carries only its DELTA against the
 * device tier; _applyStep() folds steps 1..N cumulatively on top of the
 * tier defaults, so a device can never end up above its tier ceiling.
 *
 * Step order follows measured cost in this renderer: dynamic resolution is
 * the cheapest big win (fill rate), then the composer's extra full-frame
 * passes (bloom), then per-light shading, then the per-fragment environment
 * lookup + CPU-side update throttles at the floor. Antialiasing, shadows,
 * texture size/anisotropy and decor density are deliberately NOT runtime
 * steps: AA is fixed at context creation (and the composer path carries no
 * MSAA), shadows are venue/build-time, textures re-upload on change, and
 * decor density is tiered at build time (structure_pass tier_floor).
 */
const GOV_STEPS = [
    { label: 'Full' },
    { label: 'DSR 0.85', scale: 0.85 },
    { label: 'DSR 0.72', scale: 0.72 },
    { label: 'No bloom', bloom: false },
    { label: 'DSR 0.60', scale: 0.60 },
    { label: 'Lights 2', lights: 2 },
    { label: 'Floor', hdri: false, throttle: { light: 4, focus: 6 } },
];

const GOV_STORE_KEY = 'exospace_gov_tier_v1';
const TIER_CHANGE_SEND_GAP_MS = 60000;

export class PerformanceControls {
    constructor(scene) {
        this.scene = scene;
        this._frames = 0;
        this._lastFpsUpdate = performance.now();
        this._currentFps = 0;
        this._quality = this._loadSavedQuality() || 'auto';

        this._prScale = 1;

        // ── Governor wiring ────────────────────────────────────────────────
        this._gov = createGovernorCore({ now: () => performance.now() });
        this._bootAt = performance.now();
        this._bootDpr = window.devicePixelRatio || 1;
        this._govArmed = false;
        this._lastTierSentAt = 0;

        this._debugMode = window.EXOSPACE_DEBUG === true;

        // Always apply the quality setting (even if the panel is hidden)
        if (this._quality === 'auto') {
            // Low-end devices start at their static floor; the QA baseline
            // flag (?gov=0 probes) freezes auto behaviour for comparisons.
            if (scene.isLowEnd || window.__EXOSPACE_GOVERNOR_OFF === true) {
                this._gov.setOverride(true);
            } else {
                // Learned tier from a previous visit — re-validated by
                // measurement (fast downgrade if it was too optimistic).
                this._gov.restore(this._loadGovernorStep());
            }
            this._applyStep(this._gov.stepIndex(), { initial: true });
        } else {
            this._gov.setOverride(true);
            this._applyQuality(this._quality);
        }

        if (this._debugMode) {
            this._createPanel();
        }

        // DPR / window changes invalidate the measured window and the PR cap;
        // a big DPR jump (monitor move, zoom) also forgets the learned tier.
        this._onGovResize = () => {
            const dpr = window.devicePixelRatio || 1;
            if (Math.abs(dpr - this._bootDpr) > 0.25) {
                this._bootDpr = dpr;
                this._clearGovernorStep();
            }
            if (this._quality === 'auto') {
                this._applyStep(this._gov.stepIndex(), { initial: true });
            } else {
                this._applyQuality(this._quality);
            }
            this._gov.notifyResize();
            scene._forceRenderUntil = performance.now() + 500;
        };
        window.addEventListener('resize', this._onGovResize);

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
            <div id="perf-gov" style="color:#8b5cf6; font-size:10px;">Gov: --</div>
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
        this._govEl = panel.querySelector('#perf-gov');
        this._lightsEl = panel.querySelector('#perf-lights');
        this._drawsEl = panel.querySelector('#perf-draws');
        this._prEl = panel.querySelector('#perf-pr');
        this._qualitySelect = panel.querySelector('#perf-quality');
        this._qualitySelect.value = this._quality;
        this._qualitySelect.addEventListener('change', (e) => {
            this._quality = e.target.value;
            this._saveQuality(this._quality);
            if (this._quality === 'auto') {
                this._gov.restore(this._loadGovernorStep());
                this._gov.setOverride(false);
                this._applyStep(this._gov.stepIndex());
            } else {
                this._gov.setOverride(true);
                this._applyQuality(this._quality);
            }
        });
    }

    _tick() {
        const now = performance.now();

        // Governor consumes every rendered frame's delta.
        const dt = now - (this._lastFrameAt ?? now);
        this._lastFrameAt = now;

        // Safety arm for pages that never fire the Enter handler.
        if (!this._govArmed && now - this._bootAt >= 20000) this._armGovernor();
        if (this._govArmed) {
            for (const e of this._gov.frame(dt)) this._handleGovernorEvent(e);
        }

        this._frames++;
        const elapsed = now - this._lastFpsUpdate;
        if (elapsed >= 500) {
            this._currentFps = Math.round((this._frames * 1000) / elapsed);
            this._frames = 0;
            this._lastFpsUpdate = now;
            this._updateDisplay();
            this._maybeSamplePerf(this._currentFps);
        }
    }

    _armGovernor() {
        if (this._govArmed) return;
        this._govArmed = true;
        this._gov.arm();
    }

    _handleGovernorEvent(e) {
        if (e.type === 'down' || e.type === 'up-confirm' || e.type === 'up-revert') {
            this._applyStep(e.to);
        }
        if (e.type === 'down' || e.type === 'up-confirm') {
            this._saveGovernorStep(e.to);
            this._sendTierTelemetry();
        } else if (e.type === 'floor') {
            this._sendTierTelemetry();
        }
        if (this._debugMode) {
            const p95 = e.stats?.p95 != null ? `${e.stats.p95.toFixed(1)}ms` : '--';
            console.log(`⚡ Governor ${e.type} → ${e.step?.label ?? '?'} (target ${e.target}fps, p95 ${p95})`);
        }
    }

    /**
     * Fold ladder steps 1..index cumulatively on top of the device tier
     * defaults and push the result into the renderer.
     */
    _applyStep(index, { initial = false } = {}) {
        const scene = this.scene;
        const tier = scene.isLowEnd ? 'low' : (scene._isMobileTier ? 'mobile' : 'high');
        const tierCfg = QUALITY_LEVELS[tier] || QUALITY_LEVELS.high;

        let scale = 1;
        let bloom = tierCfg.bloom;
        let lights = tierCfg.maxLights;
        let hdri = tierCfg.hdri;
        let throttle = null;
        for (let k = 1; k <= index && k < GOV_STEPS.length; k++) {
            const s = GOV_STEPS[k];
            if (s.scale !== undefined)    scale = s.scale;
            if (s.bloom !== undefined)    bloom = s.bloom;
            if (s.lights !== undefined)   lights = s.lights;
            if (s.hdri !== undefined)     hdri = s.hdri;
            if (s.throttle)               throttle = s.throttle;
        }

        this._basePR = Math.min(window.devicePixelRatio || 1, tierCfg.pixelRatio);
        this._prScale = scale;
        this._applyPixelRatio();

        scene._maxActiveLights = lights;
        if (scene._postFx) scene._postFx.setBloomEnabled(bloom);

        if (!hdri) {
            scene._skipHdri = true;
            if (scene.scene?.environment) scene.scene.environment = null;
        } else if (scene._skipHdri && !scene.isLowEnd && tierCfg.hdri) {
            // Only a tier that ships HDRI may buy it back after the floor.
            scene._skipHdri = false;
            scene.loadEnvironmentMap();
        }

        scene._govThrottle = throttle;

        if (!initial) {
            // Tier changes must be visible even in an otherwise static scene.
            scene._forceRenderUntil = performance.now() + 500;
        }
        if (this._debugMode && !initial) {
            console.log(`⚡ Governor applied ${GOV_STEPS[index]?.label ?? index}: scale=${scale} bloom=${bloom} lights=${lights} hdri=${hdri}`);
        }
    }

    // ── Learned-tier persistence (per device, versioned key) ──────────────

    _loadGovernorStep() {
        try {
            const raw = localStorage.getItem(GOV_STORE_KEY);
            if (!raw) return 0;
            const parsed = JSON.parse(raw);
            const step = Number(parsed?.step);
            if (!Number.isInteger(step) || step < 0 || step >= DEFAULT_LADDER.length) return 0;
            return step;
        } catch {
            return 0;
        }
    }

    _saveGovernorStep(step) {
        try {
            localStorage.setItem(GOV_STORE_KEY, JSON.stringify({ step, t: Date.now() }));
        } catch {
            // localStorage might be blocked (private browsing)
        }
    }

    _clearGovernorStep() {
        try {
            localStorage.removeItem(GOV_STORE_KEY);
        } catch {
            // ignore
        }
    }

    // ── Aggregated telemetry (existing `perf` schema, rate-limited) ───────

    _governorQ() {
        if (this._quality !== 'auto' || this._gov.override()) {
            return String(this._quality).slice(0, 8);
        }
        const floorMarker = this._gov.atFloor() ? 'f' : '';
        return `auto:t${this._gov.stepIndex()}${floorMarker}`.slice(0, 8);
    }

    _sendTierTelemetry() {
        const now = Date.now();
        if (now - this._lastTierSentAt < TIER_CHANGE_SEND_GAP_MS) return;
        this._lastTierSentAt = now;
        const st = this._gov.stats();
        const info = this.scene.renderer?.info;
        Analytics.send('perf', {
            perf: {
                tier: this.scene.isLowEnd ? 'low' : (this.scene._isMobileTier ? 'mobile' : 'high'),
                q: this._governorQ(),
                fps: st?.median ? Math.round(1000 / st.median) : null,
                adapt: Math.round(this._prScale * 100) / 100,
                draws: info?.render?.calls ?? null,
                n: this.scene.artworks?.length ?? null,
                partial: 1,
            },
        });
    }

    startPerfSampling(enterMs) {
        if (this._perfState) return; // once per page load
        this._perfState = {
            enterMs: enterMs || Math.round(performance.now()),
            samples: [],
            sent: false,
            listener: () => this._flushPerf(true),
        };
        window.addEventListener('pagehide', this._perfState.listener, { once: true });
        this._armGovernor();
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
        const govStats = this._gov.stats();

        Analytics.send('perf', {
            perf: {
                tier: scene.isLowEnd ? 'low' : (scene._isMobileTier ? 'mobile' : 'high'),
                q: this._governorQ(),
                // Median frame time of the measured window (fallback: the
                // 500 ms FPS samples average), expressed as fps.
                fps: govStats?.median
                    ? Math.round(1000 / govStats.median)
                    : (s.length ? Math.round(s.reduce((a, b) => a + b, 0) / s.length) : null),
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

    _applyPixelRatio() {
        if (!this._basePR) return;
        this.scene.renderer.setPixelRatio(this._basePR * this._prScale);
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
        if (this._govEl && this._gov) {
            const st = this._gov.stats();
            const p95 = st?.p95 != null ? st.p95.toFixed(1) : '--';
            const stepLabel = GOV_STEPS[this._gov.stepIndex()]?.label ?? '?';
            this._govEl.textContent =
                `Gov: ${stepLabel} · tgt ${this._gov.targetHz()} · p95 ${p95}ms`;
        }
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
        // Manual pins only — auto quality routes through _applyStep().
        const cfg = QUALITY_LEVELS[quality];
        if (!cfg) return;

        this._basePR = Math.min(window.devicePixelRatio || 1, cfg.pixelRatio);
        this._prScale = 1; // manual pin — no dynamic resolution
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
            if (this.scene.scene?.environment) {
                this.scene.scene.environment = null;
            }
        } else if (this.scene._skipHdri && !this.scene.isLowEnd) {
            // Re-enable HDRI loading if it was skipped
            this.scene._skipHdri = false;
            this.scene.loadEnvironmentMap();
        }

        // Manual pin releases the governor's throttle override
        this.scene._govThrottle = null;

        if (this._debugMode) {
            console.log(`⚡ Quality set to ${quality} → pixelRatio=${cfg.pixelRatio}, bloom=${cfg.bloom}, maxLights=${cfg.maxLights}, hdri=${cfg.hdri}`);
        }
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
