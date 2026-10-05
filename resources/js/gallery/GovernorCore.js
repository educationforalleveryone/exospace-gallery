/**
 * GovernorCore — pure adaptive-performance decision logic for the 3D gallery.
 *
 * No imports, no DOM, no three.js: state machine fed with frame deltas from an
 * injectable clock so it can be unit-tested without WebGL. The consumer
 * (PerformanceControls) maps step indices onto renderer settings; this module
 * only decides WHEN and WHICH WAY to move, and estimates the display refresh.
 *
 * Contract: the governor trades visual quality for frame rate down to the last
 * ladder step (the floor). A device that still misses the target at the floor
 * runs at the floor and reports `atFloor: true` — that is the honest limit of
 * what software can guarantee on unknown hardware.
 */

export const DEFAULT_TUNABLES = {
    evalIntervalMs: 250,     // decision cadence (wall time, via injected clock)
    windowSize: 180,         // rolling frame window: count cap (~3 s at 60 fps)
    windowSpanMs: 5000,      // …and wall-time cap, so slow fps still evict history
    minSamplesDown: 30,      // ~0.5 s of frames needed to judge a miss
    minSamplesUp: 60,        // ~1 s of frames needed to judge a hit
    // Very slow devices (below ~4 fps) cannot reach the sample counts in any
    // reasonable time — a small count spanning enough wall time also counts.
    minSamplesFloor: 6,
    minDownWallMs: 4000,
    maxFrameMs: 5000,        // dt above this is tab suspension, not a frame
    spikeRatio: 8,           // dt above median×this is an outlier (GC, decode)
    // Miss: tail or average clearly over budget. Hit: comfortably within it.
    // The wide bands embrace vsync quantisation (frames land on 16.7/33.3 ms).
    missP95Ratio: 1.30,
    missAvgRatio: 1.10,
    hitP95Ratio: 1.15,
    hitAvgRatio: 1.06,
    downStreakNeeded: 4,     // consecutive miss evals before a downgrade (~1 s)
    downCooldownMs: 1500,    // min spacing between downgrades
    upHoldMs: 8000,          // quiet period after any change before probing up
    probeSettleMs: 5000,     // a promoted step must hold this long to confirm
    upCooldownStartMs: 12000,
    upCooldownGrow: 1.75,
    upCooldownMaxMs: 90000,  // failed probes back off; no oscillation
    armDelayMs: 6000,        // loading/first-seconds period is never judged
    refreshMinSamples: 40,
    retargetMedianLoMs: 30,  // 30 Hz power-save discovered late (median band)
    retargetMedianHiMs: 40,
    retargetSpreadMaxMs: 6,  // p90−p10 tightness required to retarget
};

/**
 * Degradation ladder, ordered by visual cost versus expected gain for THIS
 * renderer. Index 0 = full quality; last index = floor.
 *  t0  full:          dynamic resolution 1.0, bloom, tier lights, HDRI
 *  t1/t2  DSR:        render scale 0.85 / 0.72 — cheapest big win (fill rate)
 *  t3  bloom off:     drops the composer's extra full-frame passes
 *  t4  DSR floor:     0.60 render scale (matches the historic adapt floor)
 *  t5  lights:        pooled point lights 8/4 → 2 (per-light shading cost)
 *  t6  floor:         HDRI off + doubled update throttles (CPU-side relief)
 * Antialiasing, shadows, texture size/anisotropy and decor density are NOT
 * runtime steps — see PerformanceControls for why (fixed at context creation
 * or load time in this codebase).
 */
export const DEFAULT_LADDER = [
    { id: 't0', label: 'Full' },
    { id: 't1', label: 'DSR 0.85' },
    { id: 't2', label: 'DSR 0.72' },
    { id: 't3', label: 'No bloom' },
    { id: 't4', label: 'DSR 0.60' },
    { id: 't5', label: 'Lights 2' },
    { id: 't6', label: 'Floor' },
];

/**
 * Estimate the display refresh from raw frame deltas: keep plausible vsync
 * intervals, take the mode of their rounded values (robust against jitter and
 * jank), and clamp the target to min(60, measured). Returns null when there
 * is not enough clean data (caller defaults to 60).
 */
export function estimateRefreshHz(dts, opts = {}) {
    const minSamples = opts.minSamples ?? DEFAULT_TUNABLES.refreshMinSamples;
    const lo = opts.lo ?? 2;
    const hi = opts.hi ?? 45;

    const clean = [];
    for (let i = 0; i < dts.length; i++) {
        const d = dts[i];
        if (d >= lo && d <= hi) clean.push(Math.round(d));
    }
    if (clean.length < minSamples) return null;

    const counts = new Map();
    for (const d of clean) counts.set(d, (counts.get(d) || 0) + 1);

    let mode = null;
    let modeCount = 0;
    const sorted = [...counts.keys()].sort((a, b) => a - b);
    const median = sorted[Math.floor(sorted.length / 2)];
    for (const [value, count] of counts) {
        if (count > modeCount || (count === modeCount && Math.abs(value - median) < Math.abs(mode - median))) {
            mode = value;
            modeCount = count;
        }
    }
    if (!mode || modeCount < Math.max(8, clean.length * 0.15)) return null;

    // Snap to common panel rates (rounding noise must not yield "59 Hz").
    const COMMON = [24, 25, 30, 48, 50, 60, 72, 90, 120, 144];
    const raw = 1000 / mode;
    let snapped = COMMON[0];
    for (const rate of COMMON) {
        if (Math.abs(raw - rate) <= Math.abs(raw - snapped)) snapped = rate;
        else if (Math.abs(raw - rate) === Math.abs(raw - snapped)) snapped = Math.max(snapped, rate);
    }
    return Math.min(60, Math.max(24, snapped));
}

function percentile(sortedArr, p) {
    if (!sortedArr.length) return null;
    const idx = Math.min(sortedArr.length - 1, Math.max(0, Math.round((p / 100) * (sortedArr.length - 1))));
    return sortedArr[idx];
}

function sum(arr) {
    let s = 0;
    for (let i = 0; i < arr.length; i++) s += arr[i];
    return s;
}

export function createGovernorCore({ now, ladder = DEFAULT_LADDER, tunables = {} } = {}) {
    if (typeof now !== 'function') throw new Error('GovernorCore: injectable clock `now` is required');
    if (!Array.isArray(ladder) || ladder.length < 2) throw new Error('GovernorCore: ladder needs ≥ 2 steps');

    const T = { ...DEFAULT_TUNABLES, ...tunables };

    let lastNow = now();
    let armed = false;
    let armAt = 0;
    let calibrated = false;

    let stepIndex = 0;
    let targetHz = 60;
    let atFloor = false;
    let override = false;
    let calibrationHz = null;   // refresh measured while the scene was still cheap

    const window = [];            // recent frame dts (ms)
    let windowSpan = 0;           // wall time the window currently spans
    const refreshSamples = [];    // dts collected during the calibration window
    let lastEvalAt = lastNow;

    let downStreak = 0;
    let hitStreak = 0;
    let lastChangeAt = 0;
    let upCooldownUntil = 0;
    let upCooldownMs = T.upCooldownStartMs;
    let probing = false;
    let probeStartedAt = 0;
    let changes = 0;
    let floorReported = false;

    let stats = { p95: null, avg: null, median: null, p90: null, p10: null, n: 0, span: 0 };

    function clearWindow() {
        window.length = 0;
        windowSpan = 0;
    }

    function computeStats() {
        const sorted = [...window].sort((a, b) => a - b);
        if (!sorted.length) {
            stats = { p95: null, avg: null, median: null, p90: null, p10: null, n: 0, span: 0 };
            return;
        }
        const median = percentile(sorted, 50);
        // Spike rejection: discard frames far beyond the median (one-off GC /
        // decode stalls) before averaging — they must not drive decisions.
        const trimmed = median != null
            ? sorted.filter((d) => d <= median * T.spikeRatio)
            : sorted;
        let sumAll = 0;
        for (let i = 0; i < trimmed.length; i++) sumAll += trimmed[i];
        stats = {
            p95: percentile(trimmed, 95),
            p90: percentile(trimmed, 90),
            p10: percentile(trimmed, 10),
            median,
            avg: trimmed.length ? sumAll / trimmed.length : null,
            span: sum(window),
            n: trimmed.length,
        };
    }

    function miss() {
        const budget = 1000 / targetHz;
        // Enough evidence: the normal 30-frame path, or — for devices so slow
        // they cannot produce 30 frames in reasonable time — a smaller sample
        // spanning real wall-clock time.
        const enough = stats.n >= T.minSamplesDown
            || (stats.n >= T.minSamplesFloor && stats.span >= T.minDownWallMs);
        if (!enough) return false;
        return (stats.p95 != null && stats.p95 > budget * T.missP95Ratio)
            || (stats.avg != null && stats.avg > budget * T.missAvgRatio);
    }

    function hit() {
        const budget = 1000 / targetHz;
        if (stats.n < T.minSamplesUp && !(stats.n >= T.minSamplesFloor && stats.span >= T.minDownWallMs)) return false;
        return (stats.p95 != null && stats.p95 < budget * T.hitP95Ratio)
            && (stats.avg != null && stats.avg < budget * T.hitAvgRatio);
    }

    function change(toIndex, type, reason) {
        const from = stepIndex;
        stepIndex = toIndex;
        lastChangeAt = lastNow;
        changes++;
        downStreak = 0;
        hitStreak = 0;
        // Judge the new step on fresh evidence only — pre-change frames would
        // otherwise trigger one stale-data overreaction.
        clearWindow();
        atFloor = toIndex === ladder.length - 1 ? atFloor : false;
        const event = {
            type,
            from,
            to: toIndex,
            step: ladder[toIndex],
            reason: reason || null,
            target: targetHz,
            stats: { ...stats },
            atFloor,
            now: lastNow,
        };
        if (toIndex === ladder.length - 1) {
            upCooldownUntil = lastNow + upCooldownMs;
        }
        return event;
    }

    function evaluate() {
        computeStats();
        const ev = [];

        // Late discovery of a 30 Hz power-save: pinned at the floor with a
        // tight, slow frame cadence AND a calibration that never measured a
        // fast native cadence (inconclusive → 60 default) means the display,
        // not the GPU, is the limit. A device whose calibration measured 60
        // stays recorded at the floor — uniform 33 ms frames there are a GPU
        // limit, and climbing would only bounce back.
        if (calibrationHz == null
            && atFloor
            && stats.median != null
            && stats.median >= T.retargetMedianLoMs && stats.median <= T.retargetMedianHiMs
            && stats.p90 != null && stats.p10 != null
            && (stats.p90 - stats.p10) <= T.retargetSpreadMaxMs
            && targetHz > 30) {
            targetHz = 30;
            atFloor = false;
            floorReported = false;
            upCooldownMs = T.upCooldownStartMs;
            upCooldownUntil = lastNow + 2000;
            ev.push({ type: 'retarget', from: null, to: stepIndex, step: ladder[stepIndex], target: targetHz, stats: { ...stats }, atFloor: false, now: lastNow });
        }

        if (miss()) {
            downStreak++;
            hitStreak = 0;
            // A promoted step that misses reverts immediately (probe failed).
            if (probing) {
                probing = false;
                upCooldownMs = Math.min(T.upCooldownMaxMs, Math.round(upCooldownMs * T.upCooldownGrow));
                upCooldownUntil = lastNow + upCooldownMs;
                if (stepIndex < ladder.length - 1) ev.push(change(stepIndex + 1, 'up-revert', 'probe miss'));
                return ev;
            }
            if (downStreak >= T.downStreakNeeded
                && lastNow - lastChangeAt >= T.downCooldownMs
                && stepIndex < ladder.length - 1) {
                ev.push(change(stepIndex + 1, 'down', 'sustained miss'));
                return ev;
            }
            if (downStreak >= T.downStreakNeeded
                && stepIndex === ladder.length - 1
                && !floorReported) {
                floorReported = true;
                atFloor = true;
                ev.push({ type: 'floor', from: stepIndex, to: stepIndex, step: ladder[stepIndex], target: targetHz, stats: { ...stats }, atFloor: true, now: lastNow });
            }
            return ev;
        }

        downStreak = 0;

        if (probing) {
            if (lastNow - probeStartedAt >= T.probeSettleMs) {
                probing = false;
                upCooldownMs = T.upCooldownStartMs;
                upCooldownUntil = lastNow + upCooldownMs;
                ev.push({ type: 'up-confirm', from: stepIndex, to: stepIndex, step: ladder[stepIndex], target: targetHz, stats: { ...stats }, atFloor, now: lastNow });
            }
            return ev;
        }

        if (hit()) {
            hitStreak++;
            const ready = lastNow - lastChangeAt >= T.upHoldMs && lastNow >= upCooldownUntil;
            if (ready && hitStreak * T.evalIntervalMs >= T.probeSettleMs && stepIndex > 0) {
                ev.push(change(stepIndex - 1, 'up-probe', 'sustained hit'));
                probing = true;
                probeStartedAt = lastNow;
            }
            return ev;
        }

        hitStreak = 0;
        return ev;
    }

    return {
        /** Begin governance (visitor entered). Decisions start after armDelayMs. */
        arm() {
            if (armed) return;
            armed = true;
            armAt = now();
            lastNow = armAt;
            lastEvalAt = armAt;
        },

        /** Feed one rendered frame; dt = milliseconds since the previous frame. */
        frame(dt) {
            const t = now();
            const delta = t - lastNow;
            lastNow = t;
            if (delta > T.maxFrameMs || delta <= 0 || dt > T.maxFrameMs || dt <= 0) {
                // Tab suspension or clock jump: history is stale, drop it
                // instead of judging the device on it. Genuinely slow frames
                // (below maxFrameMs) still accumulate — consecutive multi-
                // hundred-ms frames are a slow device, not a gap.
                clearWindow();
                downStreak = 0;
                hitStreak = 0;
                probing = false;
                return [];
            }
            window.push(dt);
            // The window is bounded by count AND by the wall time its frames
            // span: at 4 fps a 180-frame window would cover 45 stale seconds.
            windowSpan += dt;
            while (window.length && (windowSpan > T.windowSpanMs || window.length > T.windowSize)) {
                windowSpan -= window.shift();
            }

            if (!armed || override) return [];

            if (!calibrated) {
                if (dt > 0 && dt <= T.maxFrameMs) refreshSamples.push(dt);
                if (t - armAt >= T.armDelayMs) {
                    calibrated = true;
                    calibrationHz = estimateRefreshHz(refreshSamples, { minSamples: T.refreshMinSamples });
                    if (calibrationHz != null) targetHz = calibrationHz;
                    refreshSamples.length = 0;
                    lastChangeAt = t;
                }
                return [];
            }

            if (t - lastEvalAt < T.evalIntervalMs) return [];
            lastEvalAt = t;
            return evaluate();
        },

        /** Manual quality pin — the governor stands down while set. */
        setOverride(on) {
            const was = override;
            override = !!on;
            if (override && !was) {
                clearWindow();
                downStreak = 0;
                hitStreak = 0;
                probing = false;
            }
            if (!override && was) {
                lastChangeAt = now();
                upCooldownUntil = lastChangeAt + T.upHoldMs;
            }
        },

        /**
         * Start from a previously learned step (persisted per device). The
         * restored step is re-validated by measurement: sustained misses
         * downgrade it quickly, sustained hits probe it back up.
         */
        restore(index) {
            if (!Number.isFinite(index)) return;
            stepIndex = Math.min(Math.max(Math.round(index), 0), ladder.length - 1);
            clearWindow();
            downStreak = 0;
            hitStreak = 0;
            probing = false;
            lastChangeAt = now();
        },

        /** Window/DPR change — history is measured against the old viewport. */
        notifyResize() {
            clearWindow();
            downStreak = 0;
            hitStreak = 0;
            probing = false;
        },

        isArmed: () => armed && calibrated && !override,
        atFloor: () => atFloor,
        stepIndex: () => stepIndex,
        step: () => ladder[stepIndex],
        targetHz: () => targetHz,
        ladderLength: () => ladder.length,
        changeCount: () => changes,
        stats: () => ({ ...stats, window: window.length }),
        override: () => override,
    };
}
