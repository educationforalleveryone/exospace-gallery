/**
 * GovernorCore unit tests — simulated frame-time sequences, no WebGL.
 * Run:  node --test scripts/harness/governor-core.test.mjs
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';

import {
    createGovernorCore,
    estimateRefreshHz,
    DEFAULT_LADDER,
} from '../../resources/js/gallery/GovernorCore.js';

function makeClock() {
    const state = { t: 0 };
    const now = () => state.t;
    const advance = (ms) => { state.t += ms; return state.t; };
    return { now, advance, get t() { return state.t; } };
}

/** Feed frames at a fixed interval for a wall-time span; collect events. */
function runFrames(core, clock, { dt, forMs }) {
    const events = [];
    const end = clock.t + forMs;
    while (clock.t < end) {
        clock.advance(dt);
        events.push(...core.frame(dt));
    }
    return events;
}

/** Feed an explicit sequence of [dt, gapBeforeMs] frames. */
function runSequence(core, clock, frames) {
    const events = [];
    for (const [dt, gapBefore = 0] of frames) {
        if (gapBefore) clock.advance(gapBefore);
        clock.advance(dt);
        events.push(...core.frame(dt));
    }
    return events;
}

function armedCore(clock, tunables = {}) {
    const core = createGovernorCore({ now: clock.now, tunables });
    core.arm();
    return core;
}

const ARM_MS = 6000;

test('estimateRefreshHz: 60 / 30 / 50 / high-refresh caps and sparse data', () => {
    const sixty = Array.from({ length: 300 }, (_, i) => (i % 50 === 0 ? 40 : 16.7));
    assert.equal(estimateRefreshHz(sixty), 60);

    const thirty = Array.from({ length: 200 }, () => 33.3);
    assert.equal(estimateRefreshHz(thirty), 30);

    const fifty = Array.from({ length: 200 }, () => 20.0);
    assert.equal(estimateRefreshHz(fifty), 50);

    const h120 = Array.from({ length: 300 }, () => 8.3);
    assert.equal(estimateRefreshHz(h120), 60, 'target capped to min(60, refresh)');

    const h144 = Array.from({ length: 300 }, () => 6.9);
    assert.equal(estimateRefreshHz(h144), 60);

    assert.equal(estimateRefreshHz([16.7, 16.7, 16.9]), null, 'not enough samples');
    // no dominant cadence → inconclusive (caller defaults to 60)
    const spread = Array.from({ length: 240 }, (_, i) => [17, 21, 26, 31, 36, 41, 24, 29][i % 8]);
    assert.equal(estimateRefreshHz(spread), null, 'no dominant mode');
});

test('arm gating: no decisions before arm() or during the calibration window', () => {
    const clock = makeClock();
    const core = createGovernorCore({ now: clock.now });
    let events = runFrames(core, clock, { dt: 33.3, forMs: 4000 });
    assert.equal(events.length, 0, 'unarmed governor is silent');

    core.arm();
    events = runFrames(core, clock, { dt: 33.3, forMs: ARM_MS - 100 });
    assert.equal(events.length, 0, 'calibration window never judges');
});

test('steady good: stays at full quality, no events', () => {
    const clock = makeClock();
    const core = armedCore(clock);
    runFrames(core, clock, { dt: 33.3, forMs: ARM_MS }); // calibrate on any cadence
    const events = runFrames(core, clock, { dt: 16.7, forMs: 12000 });
    assert.equal(events.length, 0);
    assert.equal(core.stepIndex(), 0);
});

test('sustained bad: fast downgrade (~1.5 s), then stepwise to the floor', () => {
    const clock = makeClock();
    const core = armedCore(clock);
    runFrames(core, clock, { dt: 16.7, forMs: ARM_MS });
    const events = runFrames(core, clock, { dt: 33.3, forMs: 20000 });
    const downs = events.filter(e => e.type === 'down');
    assert.ok(downs.length >= 2, `expected stepwise downgrades, got ${events.map(e => e.type)}`);
    assert.equal(downs[0].to, 1);
    assert.ok(core.stepIndex() >= 2);

    // keep missing → floor reached and recorded exactly once
    const rest = runFrames(core, clock, { dt: 33.3, forMs: 30000 });
    const floorEv = [...events, ...rest].filter(e => e.type === 'floor');
    assert.equal(floorEv.length, 1, `floor recorded once, got ${[...events, ...rest].map(e => e.type)}`);
    assert.equal(core.stepIndex(), DEFAULT_LADDER.length - 1);
    assert.equal(core.atFloor(), true);
    // calibration measured 60 Hz native — a GPU limit must NOT retarget
    assert.equal(core.targetHz(), 60);
});

test('single spikes and isolated stalls never cause a downgrade', () => {
    const clock = makeClock();
    const core = armedCore(clock);
    runFrames(core, clock, { dt: 16.7, forMs: ARM_MS });
    const events = runSequence(core, clock, [
        [200, 0],                    // GC-like spike (kept, then trimmed)
        ...Array.from({ length: 200 }, () => [16.7, 0]),
        [230, 0],                    // another spike
        ...Array.from({ length: 600 }, () => [16.7, 0]),
    ]);
    const downs = events.filter(e => e.type === 'down' || e.type === 'floor');
    assert.equal(downs.length, 0, `spikes must not downgrade: ${events.map(e => e.type)}`);
});

test('tab switch: long gaps clear history, no verdict from stale frames', () => {
    const clock = makeClock();
    const core = armedCore(clock);
    runFrames(core, clock, { dt: 16.7, forMs: ARM_MS });
    // hidden-tab gap (rAF paused), then one dropped-frame burst on return
    const events = runSequence(core, clock, [
        [16.7, 8000],   // suspension > maxFrameMs
        [180, 0],       // burst frame
        [16.7, 0],
        [16.7, 0],
    ]);
    assert.equal(events.filter(e => e.type === 'down').length, 0);
    const s = core.stats();
    assert.ok(s.window < 10, `window should be near-empty after gap, got ${s.window}`);
});

test('very slow device: consecutive multi-hundred-ms frames reach the floor', () => {
    const clock = makeClock();
    const core = armedCore(clock);
    runFrames(core, clock, { dt: 16.7, forMs: ARM_MS });
    // ~1.4 fps device — each frame is far over budget, and the wall-clock
    // evidence path (few samples, real time spanned) must drive the ladder.
    const events = runFrames(core, clock, { dt: 700, forMs: 60000 });
    const downs = events.filter(e => e.type === 'down');
    assert.ok(downs.length >= 2, `slow device must downgrade, got ${events.map(e => e.type)}`);
    const rest = runFrames(core, clock, { dt: 700, forMs: 90000 });
    assert.equal(core.stepIndex(), DEFAULT_LADDER.length - 1, 'reaches floor');
    assert.equal(core.atFloor(), true);
});

test('recovery: sustained hit probes upward step by step and confirms', () => {
    const clock = makeClock();
    const core = armedCore(clock);
    runFrames(core, clock, { dt: 16.7, forMs: ARM_MS });
    const bad = runFrames(core, clock, { dt: 33.3, forMs: 12000 });
    assert.ok(bad.some(e => e.type === 'down'), 'precondition: at least one downgrade');
    const downed = core.stepIndex();

    const events = runFrames(core, clock, { dt: 16.7, forMs: 30000 });
    const types = events.map(e => e.type);
    assert.ok(types.includes('up-probe'), `expected probe, got ${types}`);
    assert.ok(types.includes('up-confirm'), `expected confirm, got ${types}`);
    assert.ok(core.stepIndex() < downed, 'recovery climbs towards full quality');
});

test('probe failure: immediate revert and growing upgrade backoff', () => {
    const clock = makeClock();
    const core = armedCore(clock, { upHoldMs: 4000, probeSettleMs: 2000, upCooldownStartMs: 5000 });
    runFrames(core, clock, { dt: 16.7, forMs: ARM_MS });
    runFrames(core, clock, { dt: 26, forMs: 9000 });     // degrade
    const stepAfterDown = core.stepIndex();
    assert.ok(stepAfterDown >= 1, 'precondition: downgraded');

    // recover: probe fires, then quality collapses mid-probe → revert
    const events = runFrames(core, clock, { dt: 16.7, forMs: 14000 });
    const probe = events.find(e => e.type === 'up-probe');
    assert.ok(probe, `probe never started, got ${events.map(e => e.type)}`);
    const revert = runFrames(core, clock, { dt: 40, forMs: 2500 })
        .filter(e => e.type === 'up-revert');
    assert.equal(revert.length, 1, 'probe miss reverts immediately');
    assert.equal(core.stepIndex(), stepAfterDown, 'back to the pre-probe step');
});

test('oscillating load: tier changes stay spaced, no flip-flopping', () => {
    const clock = makeClock();
    const core = armedCore(clock);
    runFrames(core, clock, { dt: 16.7, forMs: ARM_MS });
    // alternate 1.5 s bad / 1.5 s good — marginal, pathological input
    const all = [];
    for (let i = 0; i < 20; i++) {
        all.push(...runFrames(core, clock, { dt: i % 2 === 0 ? 33.3 : 16.7, forMs: 1500 }));
    }
    const changes = all.filter(e => ['down', 'up-probe', 'up-revert'].includes(e.type));
    for (let i = 1; i < changes.length; i++) {
        const gap = changes[i].now - changes[i - 1].now;
        assert.ok(gap >= 1400, `tier changes ${gap} ms apart — hysteresis too weak`);
    }
    // upgrades need a 5 s sustained hit; a 1.5 s good phase can never trigger one
    assert.equal(changes.filter(e => e.type === 'up-probe').length, 0,
        'marginal short wins must not promote');
});

test('30 Hz power-save display: detected at calibration, never downgraded', () => {
    const clock = makeClock();
    const core = armedCore(clock);
    runFrames(core, clock, { dt: 33.3, forMs: ARM_MS });
    assert.equal(core.targetHz(), 30, 'refresh estimate should pin target to 30');
    const events = runFrames(core, clock, { dt: 33.3, forMs: 20000 });
    assert.equal(events.filter(e => e.type === 'down').length, 0);
    assert.equal(core.stepIndex(), 0);
    assert.equal(core.atFloor(), false);
});

test('144 Hz display: target clamps to 60, steady 16.7 ms frames hold full quality', () => {
    const clock = makeClock();
    const core = armedCore(clock);
    runFrames(core, clock, { dt: 6.9, forMs: ARM_MS });
    assert.equal(core.targetHz(), 60);
    const events = runFrames(core, clock, { dt: 6.9, forMs: 12000 });
    assert.equal(events.length, 0);
});

test('late 30 Hz discovery: inconclusive calibration → floor → retarget → climbs', () => {
    const clock = makeClock();
    const core = armedCore(clock);
    // Calibration is a wash of irregular jank: no dominant cadence → the
    // refresh estimate is inconclusive and the target defaults to 60.
    // (~200 frames × ~28 ms ≈ 5.6 s — ends inside the calibration window.)
    const janky = runSequence(core, clock,
        Array.from({ length: 200 }, (_, i) => [[17, 21, 26, 31, 36, 41, 24, 29][i % 8], 0]));
    assert.equal(janky.length, 0);
    assert.equal(core.targetHz(), 60);

    // steady 33.3 ms cadence afterwards → downgrades to floor → retarget
    const events = runFrames(core, clock, { dt: 33.3, forMs: 45000 });
    const types = events.map(e => e.type);
    assert.ok(types.includes('floor'), `expected floor first, got ${types}`);
    assert.ok(types.includes('retarget'), `expected retarget, got ${types}`);
    assert.equal(core.targetHz(), 30);
    assert.equal(core.atFloor(), false);
    // at the 30 Hz budget the same frames are comfortable → probes resume
    const climbed = runFrames(core, clock, { dt: 33.3, forMs: 60000 });
    const upTypes = climbed.map(e => e.type);
    assert.ok(upTypes.includes('up-probe') || core.stepIndex() < DEFAULT_LADDER.length - 1,
        `expected recovery probes, got ${upTypes}; step=${core.stepIndex()}`);
});

test('manual override: governor stands down, resumes on release', () => {
    const clock = makeClock();
    const core = armedCore(clock);
    runFrames(core, clock, { dt: 16.7, forMs: ARM_MS });
    core.setOverride(true);
    const events = runFrames(core, clock, { dt: 60, forMs: 8000 });
    assert.equal(events.length, 0);
    assert.equal(core.stepIndex(), 0);
    core.setOverride(false);
    runFrames(core, clock, { dt: 60, forMs: 25000 });
    assert.ok(core.stepIndex() > 0, 'decisions resume after override clears');
});

test('resize: history invalidated, no instant verdict from the old viewport', () => {
    const clock = makeClock();
    const core = armedCore(clock);
    runFrames(core, clock, { dt: 16.7, forMs: ARM_MS });
    runFrames(core, clock, { dt: 33.3, forMs: 2000 });
    core.notifyResize();
    const events = runFrames(core, clock, { dt: 33.3, forMs: 900 });
    assert.equal(events.filter(e => e.type === 'down').length, 0,
        'window refill period must not judge');
});

test('injectable clock: deterministic across identical runs', () => {
    const run = () => {
        const clock = makeClock();
        const core = armedCore(clock);
        runFrames(core, clock, { dt: 16.7, forMs: ARM_MS });
        const events = runFrames(core, clock, { dt: 33.3, forMs: 15000 })
            .map(e => [e.type, e.from, e.to]);
        return { events, step: core.stepIndex(), target: core.targetHz() };
    };
    assert.deepEqual(run(), run());
});
