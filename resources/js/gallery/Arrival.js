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

export function computeHero(scene) {
    const camPos = scene.camera.position;
    const eye = { x: camPos.x, y: CONFIG.camera.height, z: camPos.z };
    const centre = new THREE.Vector3();
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

        const losScale = Math.max(0, (dist - 0.6) / dist);
        const losTarget = {
            x: eye.x + (centre.x - eye.x) * losScale,
            y: centre.y,
            z: eye.z + (centre.z - eye.z) * losScale,
        };

        if (segmentBlockedByBoxes(eye, losTarget, scene._obstacles)) {
            continue;
        }

        return art; // biggest + clear view — done
    }

    return distanceOnly; // may be null
}

export function playArrival(scene) {
    if (!isArrivalEnabled(scene)) return false;

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

    let teardownSkip = () => {};

    const finish = () => {
        scene.camera.position.set(end.x, end.y, end.z);
        scene.camera.lookAt(heroPosVec);
        scene.arrivalActive = false;
        scene.velocity?.set(0, 0, 0);
        scene.currentLean = 0;
        teardownSkip();
    };

    if (scene.reducedMotion || moveDistance === 0) {
        finish();
        return true;
    }

    const skip = () => {
        if (tween) tween.kill();
        finish();
    };
    const events = ['keydown', 'pointerdown', 'touchstart'];
    const options = { passive: true };

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
    teardownSkip = () => events.forEach(ev =>
        document.removeEventListener(ev, skip, options));

    setTimeout(() => { if (scene.arrivalActive) skip(); },
               (ARRIVAL.duration + 3) * 1000);

    return true;
}
