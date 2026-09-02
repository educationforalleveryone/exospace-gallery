// ─────────────────────────────────────────────────────────────────────────────
// Arrival — the first ten seconds (roadmap P1.4, Iteration 4 "Arrival")
//
// The curtain has just promised something; the first frame must deliver it.
// On Enter the camera opens on a COMPOSED hero frame — pulled back a step
// from the classic spawn point, looking straight at the exhibition's hero
// artwork — then eases forward (1.5 s ease-out dolly) into the exact classic
// spawn pose and hands control to the visitor.
//
// Contract (EXOSPACE_3D_MASTER_ROADMAP.md §17 Iteration 4):
//   • Hero computation is DETERMINISTIC — same gallery, same hero, every
//     load (ArrivalMath.rankHeroCandidates: area desc, hang order on ties,
//     no RNG — the seeded venue composition is the only randomness).
//   • Deep-link precedence: ?artwork=<id> skips the choreography entirely
//     (the visitor came for ONE artwork — show it, don't narrate).
//   • Reduced motion: no dolly. The camera cuts instantly to the classic
//     spawn pose already facing the hero — composed frame, zero motion
//     (same pattern as Task H37 lean-skip and the tour's 0.1 s tween).
//   • Classic spawn retained: the dolly ENDS at the exact position
//     RoomBuilder placed at build time. Flag off ⇒ today's behaviour 1:1.
//   • "Hero never spawns inside structure": candidates are validated against
//     the registered obstacle AABBs (Collisions) before a hero is accepted;
//     a blocked view falls to the next candidate; a pose that cannot be
//     composed degrades to the classic spawn (no arrival), never a clipped
//     camera.
//
// DoD rule #7 — NO slug knowledge anywhere in this module. Everything reads
// layout state (bounds, obstacles, artworks). All pure math lives in
// ArrivalMath.js (zero dependencies, CI-executable); this file is the thin
// THREE/gsap/DOM shell.
// ─────────────────────────────────────────────────────────────────────────────

import * as THREE from 'three';
import gsap from 'gsap';
import { CONFIG } from './config.js';
import {
    ARRIVAL,
    rankHeroCandidates,
    segmentBlockedByBoxes,
    composeArrivalPose,
} from './ArrivalMath.js';
// Iteration 6 (P2.3): focal-wall read for the hero bias — pure, no slugs.
import { focalWallOf } from './PlacementCuration.js';

// Fast preconditions. Cheap enough to call before any computation.
export function isArrivalEnabled(scene) {
    if (!scene || scene._disposed) return false;
    if (window.EXOSPACE_EMBED_MODE) return false;            // embeds skip the ceremony
    if (window.GALLERY_DATA?.arrival_enabled === false) return false; // flag off
    if (!Array.isArray(scene.artworks) || scene.artworks.length === 0) return false;
    if (!scene.camera || !scene.scene) return false;
    return true;
}

// Pick the hero + validate the view. Returns the artwork group or null.
// Order: biggest canvas first (ArrivalMath ranking); each candidate must
// (a) stand ≥ minHeroDistance from the spawn pose and (b) have a clear
// sight-line from the spawn pose (no registered obstacle between). If no
// candidate passes both, relax to the largest one that only meets the
// distance rule (dense rooms still deserve a composed frame); if even that
// fails — null, and the classic spawn stands.
export function computeHero(scene) {
    const camPos = scene.camera.position;
    const eye = { x: camPos.x, y: CONFIG.camera.height, z: camPos.z };
    const centre = new THREE.Vector3();
    // Iteration 6 (P2.3 §6.5): a venue-declared focal wall biases the hero
    // pick. Absent (or invalid) placement keys pass null — ranking is then
    // bit-identical to the pre-IT6 behaviour.
    const focalWall = focalWallOf(scene._venuePlacement);
    const candidates = rankHeroCandidates(scene.artworks, { focalWall })
        .slice(0, ARRIVAL.maxHeroCandidates * 4);

    let distanceOnly = null;
    for (let i = 0; i < candidates.length; i++) {
        const art = candidates[i];
        art.getWorldPosition(centre);
        const dist = Math.hypot(centre.x - eye.x, centre.z - eye.z);
        if (dist < ARRIVAL.minHeroDistance) continue;

        if (!distanceOnly) distanceOnly = art; // largest artwork at valid distance

        if (segmentBlockedByBoxes(eye, { x: centre.x, y: centre.y, z: centre.z }, scene._obstacles)) {
            continue;
        }

        return art; // biggest + clear view — done
    }

    return distanceOnly; // may be null
}

// Play the arrival. Called from main.js at the moment of Enter — after the
// audio/perf hooks, alongside the curtain fade. Returns true when the
// choreography took the camera, false when the classic spawn stands.
//
// Guarantees:
//   • movement input is ignored while arrivalActive (Movement guards) and
//     ANY key / click / touch skips the dolly to its end — control lands
//     well inside the roadmap's "skip-intro ≤ 2 s" bound;
//   • reduced motion never tweens (instant composed cut);
//   • onComplete hands off cleanly: zero velocity, zero lean, hero recorded
//     for the tour start alignment (Tour reads scene.arrivalHeroId).
export function playArrival(scene) {
    if (!isArrivalEnabled(scene)) return false;

    // Deep-link precedence (roadmap §17 testing row): a shared ?artwork=
    // link must land on that artwork immediately — no narrated approach.
    if (window.GALLERY_DATA?.deepLinkArtworkId) return false;

    const hero = computeHero(scene);
    if (!hero) return false;

    // Classic spawn pose (END) — untouched, exactly what RoomBuilder set.
    const end = {
        x: scene.camera.position.x,
        y: CONFIG.camera.height,
        z: scene.camera.position.z,
    };

    const heroPos3 = new THREE.Vector3();
    hero.getWorldPosition(heroPos3);
    const heroPos = { x: heroPos3.x, y: heroPos3.y, z: heroPos3.z };

    const { start, moveDistance } = composeArrivalPose(end, heroPos, scene);

    const heroPosVec = new THREE.Vector3(heroPos.x, heroPos.y, heroPos.z);

    scene.arrivalActive = true;
    scene.arrivalHeroId = hero.userData?.id ?? null;
    scene.velocity?.set(0, 0, 0);
    scene.currentLean = 0;

    // Frame 1: the composed hero frame.
    scene.camera.position.set(start.x, start.y, start.z);
    scene.camera.lookAt(heroPosVec);

    const finish = () => {
        scene.camera.position.set(end.x, end.y, end.z);
        scene.camera.lookAt(heroPosVec);
        scene.arrivalActive = false;
        scene.velocity?.set(0, 0, 0);
        scene.currentLean = 0;
        teardownSkip();
    };

    // Reduced motion: composed frame, zero movement (instant cut). Same for
    // a domain that leaves no room to dolly (tight corridors).
    if (scene.reducedMotion || moveDistance === 0) {
        finish();
        return true;
    }

    // Skip affordance — any intentional input lands the visitor in control
    // immediately. One-shot listeners; removed on completion.
    const skip = () => {
        if (tween) tween.kill();
        finish();
    };
    const events = ['keydown', 'pointerdown', 'touchstart'];
    const options = { passive: true };
    const teardownSkip = () => events.forEach(ev =>
        document.removeEventListener(ev, skip, options));

    let tween = gsap.to(scene.camera.position, {
        x: end.x,
        y: end.y,
        z: end.z,
        duration: ARRIVAL.duration,
        ease: ARRIVAL.ease,
        onUpdate: () => { scene.camera.lookAt(heroPosVec); },
        onComplete: finish,
    });

    events.forEach(ev => document.addEventListener(ev, skip, options));

    // Hard safety: never hold the camera hostage (a backgrounded tab can
    // starve gsap's clock). The tween itself handles tab-throttle on return;
    // this timer only covers pathological states.
    setTimeout(() => { if (scene.arrivalActive) skip(); },
               (ARRIVAL.duration + 3) * 1000);

    return true;
}
