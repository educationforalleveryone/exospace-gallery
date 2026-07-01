// ─────────────────────────────────────────────────────────────────────────────
// RoomBuilder — room shell (walls, floor, ceiling) for all layouts
//
// Layouts:
//   - square      (4 walls, default)
//   - corridor    (long rectangle, 3:1 ratio)
//   - l-shape     (two wings)
//   - rotunda     (cylinder)
//   - circular    (NEW — for sculpture garden + void venues)
//
// SCULPTURE GARDEN FIX:
//   Old code: sculpture-garden used l-shape layout (huge 8m walls, no ceiling,
//   missing GLBs). Looked like an empty warehouse, not a garden.
//   New code: sculpture-garden uses circular layout — grass floor, no walls,
//   hedge boundary (added by VenueDecorator), sky dome, trees, stone path,
//   artworks on easels (see ArtworkPlacer).
//
// VOID VENUES (infinite-void, crystal-cathedral, nebula-drift, mirror-lake):
//   All use circular layout with no walls, no ceiling, no fog (or very subtle).
//   Each gets bespoke decorations from VenueDecorator.addVoidVenueStructure.
// ─────────────────────────────────────────────────────────────────────────────

import * as THREE from 'three';
import { CONFIG, OPEN_AIR_VENUES, CIRCULAR_VENUES } from './config.js';

// ── Top-level dispatcher ────────────────────────────────────────────────────
export function buildGallery() {
    const data = window.GALLERY_DATA;

    // Apply venue overrides BEFORE building
    this.applyVenueOverrides(data.venue_slug || 'white-cube');

    this.lightingPreset = data.lighting_preset;
    this.setupLighting(data.lighting_preset);

    // Pick the right builder
    const slug = this._venueSlug;
    const layout = data.room_layout || 'square';

    if (CIRCULAR_VENUES.has(slug)) {
        // Sculpture garden + all void venues use circular layout regardless
        // of the layout field — the venue's character dictates the shape.
        this.createRoomCircular(data);
    } else if (layout === 'corridor')   this.createRoomCorridor(data);
    else if (layout === 'l-shape')      this.createRoomLShape(data);
    else if (layout === 'rotunda')      this.createRoomRotunda(data);
    else                                this.createRoom(data); // square (default)

    this.placeArtworks(data);
    this.animate();
    this.loadEnvironmentMap();
}

// ── SQUARE ────────────────────────────────────────────────────────────────────
export function createRoom(data) {
    const imageCount = data.imageCount;
    const spacing    = CONFIG.room.artworkSpacing;
    const minWallLen = CONFIG.room.minWallLength;
    const imagesPerWall  = Math.ceil(imageCount / 4);
    const calculatedLen  = (imagesPerWall * spacing) + spacing;
    const wallLength = Math.max(minWallLen, calculatedLen);
    const wallHeight = CONFIG.room.wallHeight;

    // Floor
    const floorMaterial = this.getFloorMaterial(data.floor_material);
    if (floorMaterial.map) {
        const repeatX = (wallLength * 2) / 2;
        const repeatY = (wallLength * 2) / 2;
        floorMaterial.map.repeat.set(repeatX, repeatY);
        floorMaterial.map.needsUpdate = true;
    }
    const floor = new THREE.Mesh(
        new THREE.PlaneGeometry(wallLength * 2, wallLength * 2),
        floorMaterial
    );
    floor.rotation.x = -Math.PI / 2;
    floor.receiveShadow = !this.isLowEnd;
    this.scene.add(floor);

    // Walls (shared geometry)
    const wallMaterial = this.getWallMaterial(data.wall_texture);
    if (wallMaterial.map) {
        wallMaterial.map.repeat.set(wallLength / 2.5, wallHeight / 2.5);
        wallMaterial.map.needsUpdate = true;
    }
    const sharedWallGeo = new THREE.BoxGeometry(wallLength, wallHeight, CONFIG.room.wallDepth);
    const wallConfigs = [
        { name: 'front', pos: [0, wallHeight/2, -wallLength/2], rot: [0, 0, 0] },
        { name: 'back',  pos: [0, wallHeight/2,  wallLength/2], rot: [0, Math.PI, 0] },
        { name: 'left',  pos: [-wallLength/2, wallHeight/2, 0], rot: [0, Math.PI/2, 0] },
        { name: 'right', pos: [ wallLength/2, wallHeight/2, 0], rot: [0, -Math.PI/2, 0] },
    ];
    wallConfigs.forEach(cfg => {
        const wall = new THREE.Mesh(sharedWallGeo, wallMaterial);
        wall.position.set(...cfg.pos);
        wall.rotation.set(...cfg.rot);
        wall.receiveShadow = !this.isLowEnd;
        wall.castShadow    = !this.isLowEnd;
        wall.name = `wall_${cfg.name}`;
        this.scene.add(wall);
    });

    // Ceiling (venue-aware)
    this.addVenueCeiling(wallLength * 2, wallLength * 2, wallHeight);

    // Ceiling lights (2x2 grid on high-end)
    if (!this.isLowEnd) {
        const gridSize = 2;
        const startX = -(wallLength / 2) + (wallLength / (gridSize + 1));
        const startZ = -(wallLength / 2) + (wallLength / (gridSize + 1));
        const stepX = wallLength / (gridSize + 1);
        const stepZ = wallLength / (gridSize + 1);
        for (let i = 0; i < gridSize; i++) {
            for (let j = 0; j < gridSize; j++) {
                const fill = new THREE.PointLight(0xfff8e8, this.lightingConfig.fillLight * 2.0, wallLength * 1.2);
                fill.position.set(startX + i * stepX, wallHeight - 0.5, startZ + j * stepZ);
                fill.castShadow = false;
                this.scene.add(fill);
            }
        }
    }

    // Venue-specific structure (beams, dividers, etc.)
    this.addVenueStructure(data);

    // Collision bounds
    this.roomBounds = {
        minX: -wallLength / 2, maxX: wallLength / 2,
        minZ: -wallLength / 2, maxZ: wallLength / 2,
    };
    this._layoutMeta = { type: 'square', wallLength };
}

// ── CORRIDOR ──────────────────────────────────────────────────────────────────
export function createRoomCorridor(data) {
    const imageCount = data.imageCount;
    const spacing    = CONFIG.room.artworkSpacing;
    const wallHeight = CONFIG.room.wallHeight;
    const imagesPerLongWall = Math.ceil(imageCount / 2);
    const length = Math.max(16, (imagesPerLongWall * spacing) + spacing);
    const width  = 6;

    const wallMat  = this.getWallMaterial(data.wall_texture);
    const floorMat = this.getFloorMaterial(data.floor_material);
    if (wallMat.map)  { wallMat.map.repeat.set(length / 2.5, wallHeight / 2.5); wallMat.map.needsUpdate = true; }
    if (floorMat.map) { floorMat.map.repeat.set(length / 2, width / 2); floorMat.map.needsUpdate = true; }

    const sharedWallGeo = new THREE.BoxGeometry(1, wallHeight, CONFIG.room.wallDepth);

    const floor = new THREE.Mesh(new THREE.PlaneGeometry(length, width), floorMat);
    floor.rotation.x = -Math.PI / 2;
    floor.receiveShadow = !this.isLowEnd;
    this.scene.add(floor);

    this.addVenueCeiling(length, width, wallHeight);

    [
        { pos: [0, wallHeight/2, -width/2],  ry: 0,          sx: length },
        { pos: [0, wallHeight/2,  width/2],  ry: Math.PI,    sx: length },
        { pos: [-length/2, wallHeight/2, 0], ry: Math.PI/2,  sx: width  },
        { pos: [ length/2, wallHeight/2, 0], ry: -Math.PI/2, sx: width  },
    ].forEach(cfg => {
        const m = new THREE.Mesh(sharedWallGeo, wallMat);
        m.scale.set(cfg.sx, 1, 1);
        m.position.set(...cfg.pos);
        m.rotation.y = cfg.ry;
        m.receiveShadow = !this.isLowEnd;
        m.castShadow    = !this.isLowEnd;
        this.scene.add(m);
    });

    if (!this.isLowEnd) {
        [-length / 4, length / 4].forEach(xp => {
            const l = new THREE.PointLight(0xfff8e8, this.lightingConfig.fillLight * 2.5, length * 0.7);
            l.position.set(xp, wallHeight - 0.3, 0);
            l.castShadow = false;
            this.scene.add(l);
        });
    }

    this.addVenueStructure(data);

    this.camera.position.set(-length / 2 + 1.5, CONFIG.camera.height, 0);
    this.roomBounds = { minX: -length/2+0.5, maxX: length/2-0.5, minZ: -width/2+0.5, maxZ: width/2-0.5 };
    this._layoutMeta = { type: 'corridor', length, width };
}

// ── L-SHAPE ───────────────────────────────────────────────────────────────────
export function createRoomLShape(data) {
    const imageCount = data.imageCount;
    const spacing    = CONFIG.room.artworkSpacing;
    const wallHeight = CONFIG.room.wallHeight;
    const wd         = CONFIG.room.wallDepth;
    const wingW      = 6;

    const estCountA = Math.ceil(imageCount * 0.6);
    const lenA = Math.max(12, (Math.ceil(estCountA / 2) * spacing) + spacing);
    const jZ   = lenA / 2 - wingW;

    const zStart = -lenA / 2 + spacing;
    const zLimit = jZ - spacing / 2;
    let spillFrom = imageCount;
    let sideA = 0, rowA = 0;
    for (let i = 0; i < imageCount; i++) {
        if (zStart + rowA * spacing >= zLimit) { spillFrom = i; break; }
        sideA = 1 - sideA;
        if (sideA === 0) rowA++;
    }
    const actualCountB = imageCount - spillFrom;
    const lenB = Math.max(12, (Math.ceil(actualCountB / 2) * spacing) + spacing * 2);

    const aCX = wingW / 2,        aCZ = 0;
    const bCX = wingW + lenB / 2, bCZ = lenA / 2 - wingW / 2;

    const wallMat  = this.getWallMaterial(data.wall_texture);
    const floorMat = this.getFloorMaterial(data.floor_material);
    if (wallMat.map) {
        wallMat.map.wrapS = wallMat.map.wrapT = THREE.RepeatWrapping;
        wallMat.map.repeat.set(lenA / 2.5, wallHeight / 2.5);
        wallMat.map.needsUpdate = true;
    }
    if (floorMat.map) {
        floorMat.map.wrapS = floorMat.map.wrapT = THREE.RepeatWrapping;
        floorMat.map.needsUpdate = true;
    }

    // Ceiling colour (venue-aware)
    const _ceilColor = (() => {
        const s = this._venueSlug || 'white-cube';
        if (s === 'dark-museum' || s === 'luxury-penthouse') return 0x080808;
        if (s === 'cyber-gallery')  return 0x04081a;
        if (s === 'industrial-loft') return 0x1a1a18;
        if (s === 'zen-gallery')    return 0x1e1c14;
        return 0xffffff;
    })();
    const ceilMatA = this.isLowEnd
        ? new THREE.MeshLambertMaterial({ color: _ceilColor })
        : new THREE.MeshStandardMaterial({ color: _ceilColor, roughness: 0.95, metalness: 0 });
    const ceilMatB = ceilMatA.clone ? ceilMatA.clone() : ceilMatA;

    const addPanel = (cx, cz, w, d, mat, isFloor) => {
        if (floorMat.map && isFloor) {
            floorMat.map.repeat.set(w / 2, d / 2);
            floorMat.map.needsUpdate = true;
        }
        const mesh = new THREE.Mesh(new THREE.PlaneGeometry(w, d), mat);
        mesh.rotation.x = isFloor ? -Math.PI / 2 : Math.PI / 2;
        mesh.position.set(cx, isFloor ? 0 : wallHeight, cz);
        mesh.receiveShadow = !this.isLowEnd;
        this.scene.add(mesh);
    };
    addPanel(aCX, aCZ, wingW, lenA,  floorMat,  true);
    addPanel(bCX, bCZ, lenB,  wingW, floorMat,  true);
    addPanel(aCX, aCZ, wingW, lenA,  ceilMatA,  false);
    addPanel(bCX, bCZ, lenB,  wingW, ceilMatB,  false);

    const wallGeo = new THREE.BoxGeometry(1, wallHeight, wd);
    const addWall = (cx, cz, ry, len) => {
        const m = new THREE.Mesh(wallGeo, wallMat);
        m.scale.set(len, 1, 1);
        m.position.set(cx, wallHeight / 2, cz);
        m.rotation.y = ry;
        m.receiveShadow = !this.isLowEnd;
        m.castShadow    = !this.isLowEnd;
        this.scene.add(m);
    };

    const H  = Math.PI / 2;
    const PI = Math.PI;
    const upperH    = jZ - (-lenA / 2);
    const upperMidZ = -lenA / 2 + upperH / 2;

    addWall(0,                aCZ,        H,  lenA);
    addWall(aCX,             -lenA / 2,   0,  wingW);
    addWall(wingW,            upperMidZ,  H,  upperH);
    addWall(wingW + lenB / 2, jZ,         0,  lenB);
    addWall(wingW + lenB,     bCZ,        H,  wingW);
    addWall(wingW + lenB / 2, lenA / 2,   PI, lenB);
    addWall(aCX,              lenA / 2,   PI, wingW);

    if (!this.isLowEnd) {
        const mkLight = (cx, cz) => {
            const l = new THREE.PointLight(0xfff8e8, this.lightingConfig.fillLight * 2.5, 14);
            l.position.set(cx, wallHeight - 0.3, cz);
            l.castShadow = false;
            this.scene.add(l);
        };
        mkLight(aCX, -lenA / 4);
        mkLight(aCX,  lenA / 4);
        mkLight(bCX,  bCZ);
    }

    this.camera.position.set(aCX, CONFIG.camera.height, -lenA / 2 + 1.5);

    const margin = 0.5;
    this._lShapeBounds = {
        a: { minX: 0 + margin, maxX: wingW + margin, minZ: -lenA/2 + margin, maxZ: lenA/2 - margin },
        b: { minX: wingW - margin, maxX: wingW + lenB - margin, minZ: jZ, maxZ: lenA/2 - margin }
    };
    this.roomBounds = {
        minX: 0, maxX: wingW + lenB,
        minZ: -lenA / 2, maxZ: lenA / 2
    };
    this._layoutMeta = { type: 'l-shape', wingW, lenA, lenB, jZ, zStart, zLimit, aCX, aCZ, bCX, bCZ };
}

// ── ROTUNDA (cylinder) ────────────────────────────────────────────────────────
export function createRoomRotunda(data) {
    const imageCount = data.imageCount;
    const wallHeight = CONFIG.room.wallHeight;
    const spacing    = CONFIG.room.artworkSpacing;
    const circumference = imageCount * spacing;
    const radius = Math.max(7, circumference / (2 * Math.PI));

    const wallMat = this.getWallMaterial(data.wall_texture);
    if (wallMat.map) {
        wallMat.map.wrapS = THREE.RepeatWrapping;
        wallMat.map.repeat.set(Math.max(4, imageCount / 2), wallHeight / 2.5);
        wallMat.map.needsUpdate = true;
    }
    wallMat.side = THREE.BackSide;

    const cylinderGeo = new THREE.CylinderGeometry(radius, radius, wallHeight, Math.max(32, imageCount * 2), 1, true);
    const cylinder = new THREE.Mesh(cylinderGeo, wallMat);
    cylinder.position.y = wallHeight / 2;
    this.scene.add(cylinder);

    const floorMat = this.getFloorMaterial(data.floor_material);
    if (floorMat.map) { floorMat.map.repeat.set(radius, radius); floorMat.map.needsUpdate = true; }
    const floor = new THREE.Mesh(new THREE.CircleGeometry(radius, 64), floorMat);
    floor.rotation.x = -Math.PI / 2;
    floor.receiveShadow = !this.isLowEnd;
    this.scene.add(floor);

    // Ceiling — venue-aware (void venues have no ceiling)
    if (!OPEN_AIR_VENUES.has(this._venueSlug)) {
        const _ceilColor = (() => {
            const s = this._venueSlug || 'white-cube';
            if (s === 'dark-museum' || s === 'luxury-penthouse') return 0x080808;
            if (s === 'cyber-gallery')  return 0x04081a;
            if (s === 'industrial-loft') return 0x1a1a18;
            if (s === 'zen-gallery')    return 0x1e1c14;
            return 0xffffff;
        })();
        const ceilMat = this.isLowEnd
            ? new THREE.MeshLambertMaterial({ color: _ceilColor, side: THREE.BackSide })
            : new THREE.MeshStandardMaterial({ color: _ceilColor, roughness: 0.95, metalness: 0, side: THREE.BackSide });
        const ceil = new THREE.Mesh(new THREE.CircleGeometry(radius, 64), ceilMat);
        ceil.rotation.x = -Math.PI / 2;
        ceil.position.y = wallHeight;
        this.scene.add(ceil);
    }

    if (!this.isLowEnd) {
        const cl = new THREE.PointLight(0xfff8e8, this.lightingConfig.fillLight * 3.0, radius * 2.5);
        cl.position.set(0, wallHeight - 0.4, 0);
        cl.castShadow = false;
        this.scene.add(cl);
    }

    this._rotundaRadius = radius;
    this.roomBounds = { minX: -(radius - 1), maxX: radius - 1, minZ: -(radius - 1), maxZ: radius - 1 };
    this._layoutMeta = { type: 'rotunda', radius };
}

// ── CIRCULAR (NEW — sculpture garden + void venues) ───────────────────────────
// Like rotunda but with NO walls and NO ceiling — just a circular ground plane.
// Boundary is enforced by _circularBoundsRadius (set in VenueDecorator).
export function createRoomCircular(data) {
    const imageCount = data.imageCount;
    const spacing    = CONFIG.room.artworkSpacing;
    const circumference = Math.max(imageCount * spacing, 30);
    const radius = Math.max(10, circumference / (2 * Math.PI));

    // Ground
    const floorMat = this.getFloorMaterial(data.floor_material);
    if (floorMat.map) {
        floorMat.map.wrapS = floorMat.map.wrapT = THREE.RepeatWrapping;
        floorMat.map.repeat.set(radius / 2, radius / 2);
        floorMat.map.needsUpdate = true;
    }
    const floor = new THREE.Mesh(new THREE.CircleGeometry(radius, 64), floorMat);
    floor.rotation.x = -Math.PI / 2;
    floor.receiveShadow = !this.isLowEnd;
    this.scene.add(floor);

    // No walls, no ceiling — venue's addVenueStructure() adds hedges / particles / etc.

    // Subtle ceiling light (downward) so the space isn't pitch-black
    if (!this.isLowEnd) {
        const center = new THREE.PointLight(0xffffff, this.lightingConfig.fillLight * 2.5, radius * 2);
        center.position.set(0, 8, 0);
        center.castShadow = false;
        this.scene.add(center);
    }

    // Layout meta — ArtworkPlacer uses this to arrange artworks in a circle
    this._layoutMeta = { type: 'circular', radius };
    this._circularBoundsRadius = radius - 0.5;
}

// ── Venue-aware ceiling (skipped for open-air venues) ─────────────────────────
export function addVenueCeiling(roomWidth, roomDepth, wallHeight) {
    if (OPEN_AIR_VENUES.has(this._venueSlug)) return; // no ceiling for outdoor / void

    let ceilColor = 0xffffff;
    if (this._venueSlug === 'dark-museum' || this._venueSlug === 'luxury-penthouse') ceilColor = 0x080808;
    if (this._venueSlug === 'cyber-gallery')  ceilColor = 0x04081a;
    if (this._venueSlug === 'industrial-loft') ceilColor = 0x1a1a18;
    if (this._venueSlug === 'zen-gallery')    ceilColor = 0x1e1c14;

    const ceilGeo = new THREE.PlaneGeometry(roomWidth, roomDepth);
    const ceilMat = this.isLowEnd
        ? new THREE.MeshLambertMaterial({ color: ceilColor })
        : new THREE.MeshStandardMaterial({ color: ceilColor, roughness: 0.95, metalness: 0 });
    const ceiling = new THREE.Mesh(ceilGeo, ceilMat);
    ceiling.rotation.x = Math.PI / 2;
    ceiling.position.set(0, wallHeight, 0);
    ceiling.receiveShadow = false;
    this.scene.add(ceiling);

    // Industrial Loft — steel beams across ceiling
    if (this._venueSlug === 'industrial-loft') {
        const beamMat = new THREE.MeshStandardMaterial({ color: 0x2a2a28, roughness: 0.8, metalness: 0.6 });
        const beamCount = Math.max(2, Math.floor(roomDepth / 4));
        for (let i = 0; i < beamCount; i++) {
            const beam = new THREE.Mesh(
                new THREE.BoxGeometry(roomWidth, 0.18, 0.22),
                beamMat
            );
            beam.position.set(0, wallHeight - 0.1, -roomDepth / 2 + (i + 1) * (roomDepth / (beamCount + 1)));
            this.scene.add(beam);
        }
    }

    // Cyber Gallery — neon strip lights along ceiling edges
    if (this._venueSlug === 'cyber-gallery') {
        const neonColors = [0x00ffff, 0x8800ff];
        [[-roomWidth / 2, 0], [roomWidth / 2, 0]].forEach(([x], idx) => {
            const neonGeo = new THREE.BoxGeometry(0.05, 0.05, roomDepth);
            const neonMat = new THREE.MeshStandardMaterial({
                color: neonColors[idx],
                emissive: neonColors[idx],
                emissiveIntensity: 3,
            });
            const neon = new THREE.Mesh(neonGeo, neonMat);
            neon.position.set(x, wallHeight - 0.05, 0);
            this.scene.add(neon);
        });
    }
}
