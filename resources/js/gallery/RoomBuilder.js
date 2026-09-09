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
import { CONFIG, parseColor } from './config.js';
import { mergeParts } from './GeometryUtils.js';
import { addFloorEdgeFade } from './TierEffects.js';
import { venueFillIntensity } from './Lighting.js';
import { computeFloatFieldRadius } from './PlacementMath.js';

// ── Ceiling colour from config (Iteration 6 consolidation) ──────────────────
// The per-slug ceiling chains (museum/penthouse → 0x080808,
// cyber → 0x04081a, loft → 0x1a1a18, zen → 0x1e1c14, else 0xffffff) existed
// THREE times in this file. Consolidated here: the venue declares
// visual_config.ceiling_color ('0xRRGGBB'); absence ⇒ white. Values in the
// seeder/migration are byte-equivalent to the old chain outputs.
function ceilColorFromConfig(vc) {
    const c = parseColor(vc && vc.ceiling_color);
    return c ? c.getHex() : 0xffffff;
}

// ── Top-level dispatcher ────────────────────────────────────────────────────
export function buildGallery() {
    const data = window.GALLERY_DATA;

    // ── Rebuild hygiene: obstacles are scene-scoped state ────────────────
    // A WebGL context restore re-runs init() → buildGallery() on the SAME
    // GalleryScene object, and the Live Preview iframe reloads structural
    // tweaks the same way. The obstacle list was constructor-scoped only,
    // so every rebuild silently ACCUMULATED the dead scene's AABBs — the
    // visitor collided with walls that no longer existed. One clear per
    // build keeps the list exactly as large as the current scene.
    this.clearObstacles();
    // Same rebuild-hygiene class: the artwork registry and the particle
    // registry were constructor-scoped, so a rebuild DOUBLED every entry —
    // duplicated proximity-light assignments, duplicated drift updates on
    // disposed objects. Rebuilds start from a clean registry.
    this.artworks = [];
    this._particleSystems = [];

    // Apply venue overrides BEFORE building
    this.applyVenueOverrides(data.venue_slug || 'venue');

    this.lightingPreset = data.lighting_preset;
    this.setupLighting(data.lighting_preset);

    // Pick the right builder.
    // ── Iteration 6 (P2.2): CIRCULAR_VENUES set deleted — the venue's
    // visual_config.layout_shape = 'circular' forces the circular shell now
    // (the venue's character dictates the shape, declared by the venue
    // itself, not hardcoded per slug). A gallery-level room_layout of
    // 'circular' keeps working for any venue.
    const vcBoot = this._venueVisualConfig || {};
    const layout = data.room_layout || 'square';

    if (vcBoot.layout_shape === 'circular' || layout === 'circular') {
        this.createRoomCircular(data);
    } else if (layout === 'corridor')   this.createRoomCorridor(data);
    else if (layout === 'l-shape')      this.createRoomLShape(data);
    else if (layout === 'rotunda')      this.createRoomRotunda(data);
    else                                this.createRoom(data); // square (default)

    // ── Camera far must clear the room's far corner on every layout ─────
    // The fixed far plane (100, or 20 on low-end) clipped the back wall of
    // large rooms — a 60-piece square room reaches 56 m walls, and on the
    // low-end tier everything past 20 m silently vanished into the void.
    // Scaled from the actual built bounds; never shrinks (the low-end far
    // stays a floor for small rooms).
    const bnd = this.roomBounds || { minX: 0, maxX: 0, minZ: 0, maxZ: 0 };
    const roomReach = Math.max(
        Math.abs(bnd.maxX), Math.abs(bnd.minX),
        Math.abs(bnd.maxZ), Math.abs(bnd.minZ), 6
    );
    const far = Math.ceil(roomReach * 2.5 + 10);
    if (far > this.camera.far) {
        this.camera.far = far;
        this.camera.updateProjectionMatrix();
    }

    // ── Movement-reactive artwork identity (Cyber Gallery iteration) ────
    // Initialize BEFORE placeArtworks so every canvas material created this
    // build can register into the fresh registry. Rebuild-safe (resets the
    // registry; undeclared venues resolve to null ⇒ zero cost).
    this.initArtworkReactive();

    this.placeArtworks(data);

    // ── Post-placement structure hook (museum audit) ────────────────
    // Some venue structure must anchor to the FINAL artwork transforms
    // (picture-light fixtures track the piece they light — recomputing the
    // hang here would be a second placement implementation that can drift).
    // Config-gated inside (structure_pass); no declared pass ⇒ no-op.
    if (typeof this.addVenuePostPlacementStructure === 'function') {
        this.addVenuePostPlacementStructure();
    }
    this.animate();
    this.loadEnvironmentMap();
}

// ── SQUARE ────────────────────────────────────────────────────────────────────
export function createRoom(data) {
    const imageCount = data.imageCount;
    const spacing    = CONFIG.room.artworkSpacing;
    const minWallLen = CONFIG.room.minWallLength;
    // Iteration 3: when the venue replaces a wall with glazing
    // (visual_config.glazing_wall — the Penthouse mechanism), the room is
    // sized for the THREE remaining artwork walls and that wall is not
    // built. ArtworkPlacer mirrors this exact math (no drift, ever).
    const glazing      = (this._venueVisualConfig || {}).glazing_wall === true;
    const wallCount    = glazing ? 3 : 4;
    const imagesPerWall  = Math.ceil(imageCount / wallCount);
    const calculatedLen  = (imagesPerWall * spacing) + spacing;
    const wallLength = Math.max(minWallLen, calculatedLen);
    const wallHeight = CONFIG.room.wallHeight;

    // Floor — tile density is venue-declared (material_config.
    // floor_tile_meters, default 2 m) so texture scale no longer depends on
    // which layout built the floor (§4.1 floor-scale fix; circular floors
    // previously tiled at 4 m while square tiled at 2 m).
    const floorTile = this._venueMaterialConfig?.floor_tile_meters ?? 2;
    const floorMaterial = this.getFloorMaterial(data.floor_material);
    if (floorMaterial.map) {
        const repeatX = (wallLength * 2) / floorTile;
        const repeatY = (wallLength * 2) / floorTile;
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
        { id: 'front', name: 'front', pos: [0, wallHeight/2, -wallLength/2], rot: [0, 0, 0] },
        { id: 'back',  name: 'back',  pos: [0, wallHeight/2,  wallLength/2], rot: [0, Math.PI, 0] },
        { id: 'left',  name: 'left',  pos: [-wallLength/2, wallHeight/2, 0], rot: [0, Math.PI/2, 0] },
        { id: 'right', name: 'right', pos: [ wallLength/2, wallHeight/2, 0], rot: [0, -Math.PI/2, 0] },
    ];
    wallConfigs.filter(cfg => !(glazing && cfg.id === 'front')).forEach(cfg => {
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
                const fill = new THREE.PointLight(0xfff8e8, venueFillIntensity.call(this, 2.0), wallLength * 1.2);
                fill.position.set(startX + i * stepX, wallHeight - 0.5, startZ + j * stepZ);
                fill.castShadow = false;
                this.scene.add(fill);
            }
        }
    }

    // Venue-specific structure (beams, dividers, etc.)
    // ── Iteration 3 glazing anchor: the descriptor interpreter resolves
    // 'glazing' / 'glazing_outside' against this frame (inner face plane).
    if (glazing) {
        this._glazing = {
            cx: 0,
            cz: -wallLength / 2 + CONFIG.room.wallDepth / 2,
            inward: [0, 1],                 // [x, z] — points INTO the room
            width: wallLength,
            height: wallHeight,
            wallId: 'front',
        };
    }

    // Collision bounds — inset to the wall's INNER FACE plus a body-clearance
    // skin. The walls are 0.3 m boxes centred on ±L/2, so clamping to ±L/2
    // let the camera stand 0.15 m inside the wall box; with camera.near = 0.1
    // the near plane crossed the inner face and the void behind the wall
    // showed through. Corridor/rotunda/circular all inset — square now does
    // too (ArrivalMath.clampToWalkDomain mirrors this state automatically).
    const skin = (CONFIG.room.wallDepth || 0.3) / 2 + 0.3;
    this.roomBounds = {
        minX: -wallLength / 2 + skin, maxX: wallLength / 2 - skin,
        minZ: -wallLength / 2 + skin, maxZ: wallLength / 2 - skin,
    };
    this._layoutMeta = { type: 'square', wallLength };

    // ── FIX (Iteration 3, root-cause): addVenueStructure used to run BEFORE
    // roomBounds/_layoutMeta existed, so structure code (Museum dividers,
    // White Cube respect pass) fell back to hardcoded 14 m defaults on every
    // square room. Structure now builds AFTER the layout meta — the same
    // ordering the circular/rotunda/l-shape builders already had.
    this.addVenueStructure(data);
}

// ── CORRIDOR ──────────────────────────────────────────────────────────────────
export function createRoomCorridor(data) {
    const imageCount = data.imageCount;
    const spacing    = CONFIG.room.artworkSpacing;
    const wallHeight = CONFIG.room.wallHeight;
    const imagesPerLongWall = Math.ceil(imageCount / 2);
    const length = Math.max(16, (imagesPerLongWall * spacing) + spacing);
    // Loft-proportion fix (Industrial Loft forensic audit): the aisle width
    // was hardcoded at 6 m for every venue — under the loft's 7 m ceilings
    // that reads as a canyon, not an open floor. The width is now
    // venue-declared (visual_config.corridor_width, default 6 — every
    // existing venue renders bit-identically).
    const width  = Math.max(4, (this._venueVisualConfig || {}).corridor_width || 6);

    const wallMat  = this.getWallMaterial(data.wall_texture);
    const floorMat = this.getFloorMaterial(data.floor_material);
    const floorTile = this._venueMaterialConfig?.floor_tile_meters ?? 2;
    if (wallMat.map)  { wallMat.map.repeat.set(length / 2.5, wallHeight / 2.5); wallMat.map.needsUpdate = true; }
    if (floorMat.map) { floorMat.map.repeat.set(length / floorTile, width / floorTile); floorMat.map.needsUpdate = true; }

    const sharedWallGeo = new THREE.BoxGeometry(1, wallHeight, CONFIG.room.wallDepth);

    const floor = new THREE.Mesh(new THREE.PlaneGeometry(length, width), floorMat);
    floor.rotation.x = -Math.PI / 2;
    floor.receiveShadow = !this.isLowEnd;
    this.scene.add(floor);

    this.addVenueCeiling(length, width, wallHeight);

    // Texture-density fix (Industrial Loft forensic audit): one shared wall
    // material forced ONE repeat over walls of two different lengths — the
    // long walls tiled at length/2.5 while the END walls (only `width` long)
    // displayed the same repeat, stretching their texture ~3× (glaring on a
    // 9 m-wide loft corridor). End walls get a cloned material whose maps
    // tile at their own dimensions (same GPU images, independent tiling —
    // the PERF-B17 per-panel pattern). Venues without a map are unaffected.
    let endWallMat = wallMat;
    if (wallMat.map) {
        endWallMat = wallMat.clone();
        endWallMat.map = wallMat.map.clone();
        endWallMat.map.repeat.set(width / 2.5, wallHeight / 2.5);
        endWallMat.map.needsUpdate = true;
        for (const key of ['normalMap', 'roughnessMap', 'aoMap']) {
            if (endWallMat[key]) { endWallMat[key] = endWallMat[key].clone(); endWallMat[key].needsUpdate = true; }
        }
    }

    [
        { pos: [0, wallHeight/2, -width/2],  ry: 0,          sx: length, mat: wallMat },
        { pos: [0, wallHeight/2,  width/2],  ry: Math.PI,    sx: length, mat: wallMat },
        { pos: [-length/2, wallHeight/2, 0], ry: Math.PI/2,  sx: width,  mat: endWallMat },
        { pos: [ length/2, wallHeight/2, 0], ry: -Math.PI/2, sx: width,  mat: endWallMat },
    ].forEach(cfg => {
        const m = new THREE.Mesh(sharedWallGeo, cfg.mat);
        m.scale.set(cfg.sx, 1, 1);
        m.position.set(...cfg.pos);
        m.rotation.y = cfg.ry;
        m.receiveShadow = !this.isLowEnd;
        m.castShadow    = !this.isLowEnd;
        this.scene.add(m);
    });

    if (!this.isLowEnd) {
        [-length / 4, length / 4].forEach(xp => {
            const l = new THREE.PointLight(0xfff8e8, venueFillIntensity.call(this, 2.5), length * 0.7);
            l.position.set(xp, wallHeight - 0.3, 0);
            l.castShadow = false;
            this.scene.add(l);
        });
    }

    this.camera.position.set(-length / 2 + 1.5, CONFIG.camera.height, 0);
    this.roomBounds = { minX: -length/2+0.5, maxX: length/2-0.5, minZ: -width/2+0.5, maxZ: width/2-0.5 };
    this._layoutMeta = { type: 'corridor', length, width };

    // ── FIX (Iteration 3, root-cause): structure ran BEFORE _layoutMeta
    // existed here, so the Industrial Loft's beams/columns/props were built
    // against hardcoded 20×6 defaults on every corridor. They now read the
    // real room (and placement-aware columns see the real artwork lanes).
    this.addVenueStructure(data);
}

// ── L-SHAPE ───────────────────────────────────────────────────────────────────
//
// v3 "The Double Volume" (Luxury Penthouse redesign, 2026-09-09): two
// venue-declared, GENERIC extensions — both absent ⇒ this builder emits the
// exact historic geometry (bit-identical for every sibling venue; §11.3
// rule 2: the config is the only on-switch):
//
//   1. visual_config.wing_heights { wing_a, wing_b } — per-wing ceiling
//      heights. The PLAN is unchanged (bounds, placement, arrival all read
//      the same footprint); only the vertical split differs: wing A walls +
//      its ceiling band rise to wing_a, the north strip (z ∈ [jZ, lenA/2])
//      and wing B rise to wing_b. The west wall splits at jZ into a gallery
//      segment (wing_a) and a volume segment (wing_b) — the exposed edge of
//      the gallery roofline IS the ceiling step the venue's descriptors
//      dress (fascia + cove, StructureBuilder 'junction' anchor). The
//      gallery ceiling goes DoubleSide so the tall volume never sees the
//      backface hole over the step.
//   2. visual_config.glazing_walls ['wing_b_end', 'wing_b_north'] — the
//      list form of the glazing declaration. 'wing_b_end' is the historic
//      single glazed wall (glazing_wall: true ⇒ exactly this list), now
//      spanning the wing's TALL height; 'wing_b_north' additionally opens
//      the wing B segment of the north run as a second full-height glass
//      face (this._glazingNorth — StructureBuilder anchors
//      'glazing_north'/'glazing_north_outside'). The fireplace pier
//      (wall_end, the wing A segment of the same run) stays solid by
//      design: one stone moment inside the glass corner.
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

    // ── v3 declared vertical split + glazing faces (defaults = historic) ──
    const vcBoot = this._venueVisualConfig || {};
    const wh = (vcBoot.wing_heights && typeof vcBoot.wing_heights === 'object') ? vcBoot.wing_heights : {};
    const hA = Number.isFinite(Number(wh.wing_a)) && Number(wh.wing_a) > 0 ? Number(wh.wing_a) : wallHeight;
    const hB = Number.isFinite(Number(wh.wing_b)) && Number(wh.wing_b) > 0 ? Number(wh.wing_b) : wallHeight;
    const dualHeight = (hA !== wallHeight) || (hB !== wallHeight);
    // Legacy boolean keeps its exact meaning: ['wing_b_end']. The list form
    // may open the second (north) face. Values outside the vocabulary are
    // ignored — unknown keys can never invent geometry.
    let glazingWalls = [];
    if (vcBoot.glazing_wall === true) glazingWalls.push('wing_b_end');
    if (Array.isArray(vcBoot.glazing_walls)) {
        for (const g of vcBoot.glazing_walls) {
            if ((g === 'wing_b_end' || g === 'wing_b_north') && !glazingWalls.includes(g)) glazingWalls.push(g);
        }
    }
    const glazing   = glazingWalls.includes('wing_b_end');
    const glazeNorth = glazingWalls.includes('wing_b_north');

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

    // Ceiling colour (venue-aware — config-declared since Iteration 6)
    const _ceilColor = ceilColorFromConfig(this._venueVisualConfig);
    const ceilMatA = this.isLowEnd
        ? new THREE.MeshLambertMaterial({ color: _ceilColor })
        : new THREE.MeshStandardMaterial({ color: _ceilColor, roughness: 0.95, metalness: 0 });
    // Dual heights: the gallery band's ceiling is seen FROM ABOVE (its back
    // face) across the step — culled backfaces would cut a hole over the
    // procession when viewed from the tall volume. Single-height venues
    // keep the historic FrontSide material (bit-identical).
    if (dualHeight) ceilMatA.side = THREE.DoubleSide;
    const ceilMatB = ceilMatA.clone ? ceilMatA.clone() : ceilMatA;

    const addPanel = (cx, cz, w, d, mat, isFloor, y) => {
        // PERF-B17 (3D audit F17): use the panel's OWN material for repeat —
        // the old code mutated the closure's shared floorMat.map for every
        // panel, so whichever panel was added LAST decided the tiling for
        // BOTH wings (wing B won and wing A displayed the wrong scale).
        if (mat.map && isFloor) {
            mat.map.repeat.set(w / 2, d / 2);
            mat.map.needsUpdate = true;
        }
        const mesh = new THREE.Mesh(new THREE.PlaneGeometry(w, d), mat);
        mesh.rotation.x = isFloor ? -Math.PI / 2 : Math.PI / 2;
        mesh.position.set(cx, isFloor ? 0 : (y ?? wallHeight), cz);
        mesh.receiveShadow = !this.isLowEnd;
        this.scene.add(mesh);
    };
    // Wing B gets its own floor material (with a cloned map — same GPU image,
    // independent tiling) so each wing tiles at its own dimensions.
    const floorMatB = floorMat.clone();
    if (floorMat.map) {
        floorMatB.map = floorMat.map.clone();
        floorMatB.map.needsUpdate = true;
    }
    addPanel(aCX, aCZ, wingW, lenA,  floorMat,  true);
    addPanel(bCX, bCZ, lenB,  wingW, floorMatB, true);
    if (dualHeight) {
        // Gallery band (z ∈ [−lenA/2, jZ]) under the LOW plane; the living
        // volume = wing A north strip + wing B under the TALL plane. The
        // seam z = jZ is exactly where the last artwork row ends — the
        // procession finishes as the room lifts.
        addPanel(aCX, (-lenA / 2 + jZ) / 2, wingW, jZ + lenA / 2, ceilMatA, false, hA);
        addPanel(aCX, jZ + wingW / 2,       wingW, wingW,          ceilMatB, false, hB);
        addPanel(bCX, bCZ,                  lenB,  wingW,          ceilMatB, false, hB);
    } else {
        addPanel(aCX, aCZ, wingW, lenA,  ceilMatA,  false);
        addPanel(bCX, bCZ, lenB,  wingW, ceilMatB,  false);
    }

    // Walls — one shared geometry per height class (1 box geo when uniform).
    const wallGeoA = new THREE.BoxGeometry(1, hA, wd);
    const wallGeoB = dualHeight ? new THREE.BoxGeometry(1, hB, wd) : wallGeoA;
    const addWall = (cx, cz, ry, len, geo) => {
        const m = new THREE.Mesh(geo, wallMat);
        m.scale.set(len, 1, 1);
        m.position.set(cx, geo.parameters.height / 2, cz);
        m.rotation.y = ry;
        m.receiveShadow = !this.isLowEnd;
        m.castShadow    = !this.isLowEnd;
        this.scene.add(m);
    };

    const H  = Math.PI / 2;
    const PI = Math.PI;
    const upperH    = jZ - (-lenA / 2);
    const upperMidZ = -lenA / 2 + upperH / 2;

    // Iteration 3: glazing support (the Penthouse mechanism). Descriptor
    // entries anchored 'glazing' / 'glazing_outside' resolve against this
    // opening; v3 adds the second face ('glazing_north') on the wing B
    // segment of the north run.

    // West wall (x=0): one run at the nominal height — or, under dual
    // heights, TWO segments: the gallery run (wing_a) + the volume's west
    // face (wing_b, where the art wall hangs).
    if (dualHeight) {
        addWall(0, (-lenA / 2 + jZ) / 2, H, jZ + lenA / 2, wallGeoA);
        addWall(0, jZ + wingW / 2,       H, wingW,          wallGeoB);
    } else {
        addWall(0,                aCZ,        H,  lenA,  wallGeoA);
    }
    addWall(aCX,             -lenA / 2,   0,  wingW, wallGeoA);   // south (gallery band)
    addWall(wingW,            upperMidZ,  H,  upperH, wallGeoA);   // gallery east (x=wingW)
    addWall(wingW + lenB / 2, jZ,         0,  lenB,  wallGeoB);   // wing B south (solid — the seam continues here)
    if (!glazing) addWall(wingW + lenB,     bCZ,        H,  wingW, wallGeoB); // east end
    // North run, wing B segment: opened as the second glass face under
    // 'wing_b_north'; the historic solid wall otherwise (bit-identical).
    if (!glazeNorth) addWall(wingW + lenB / 2, lenA / 2, PI, lenB, wallGeoB);
    addWall(aCX,              lenA / 2,   PI, wingW, wallGeoB);   // north run, wing A (the fireplace pier — always solid)

    if (glazing) {
        this._glazing = {
            cx: wingW + lenB - CONFIG.room.wallDepth / 2,
            cz: bCZ,
            inward: [-1, 0],                // [x, z] — points INTO wing B
            width: wingW,
            height: hB,
            wallId: 'wing_b_end',
        };
    }
    if (glazeNorth) {
        this._glazingNorth = {
            cx: wingW + lenB / 2,
            cz: lenA / 2 - CONFIG.room.wallDepth / 2,
            inward: [0, -1],                // [x, z] — points INTO wing B (south)
            width: lenB,
            height: hB,
            wallId: 'wing_b_north',
        };
    } else {
        this._glazingNorth = null;          // rebuild hygiene (Live Preview reloads)
    }

    if (!this.isLowEnd) {
        const mkLight = (cx, cz, y) => {
            const l = new THREE.PointLight(0xfff8e8, venueFillIntensity.call(this, 2.5), 14);
            l.position.set(cx, y, cz);
            l.castShadow = false;
            this.scene.add(l);
        };
        // Fill lights track their zone's ceiling (historic wallHeight−0.3
        // when no split is declared — bit-identical).
        mkLight(aCX, -lenA / 4, hA - 0.3);
        mkLight(aCX,  lenA / 4, hA - 0.3);
        mkLight(bCX,  bCZ,      hB - 0.3);
    }

    // ── SPAWN ORIENTATION (backported from the v2.2.0 "Residence Plan"
    // shared-defect fix — Luxury Penthouse forensic audit, applies to every
    // l-shape gallery): the camera was parked 1.5 m off the south wall with
    // the PointerLockControls default yaw — which faces −z, i.e. AT that
    // wall 1.35 m away. On every path that skips the Arrival choreography
    // (embeds, deep links, flag off, failed hero composition) the first
    // frame was a blank wall filling the screen. Orient the spawn DOWN the
    // gallery corridor (+z, the same one-point perspective the Arrival
    // composes toward — and on this venue, the sightline the v3 seam
    // sculpture is built to be seen along). Pure orientation fix: no
    // position change, no placement math change.
    this.camera.position.set(aCX, CONFIG.camera.height, -lenA / 2 + 1.5);
    this.camera.lookAt(aCX, CONFIG.camera.height, 0);

    // ── WALKABLE-DOMAIN FIX (backported from v2.2.0 — the wall-penetration
    // audit): the margin-0.5 rects let the visitor walk THROUGH two walls:
    //   • wing A east run (x = wingW, z ≤ jZ): maxX = wingW + 0.5 stands
    //     0.35 m beyond the wall's OUTER face — inside the wall box the
    //     backfaces cull and the room shows through;
    //   • wing B south wall (z = jZ): minZ = jZ is the wall CENTRELINE —
    //     the camera could stand 0.15 m inside the box.
    // Derive a per-face skin from the wall geometry exactly the way the
    // square builder does (skin = wallDepth/2 + 0.3): every rect edge sits
    // 0.3 m clear of the wall INNER face. The A→B junction stays open: the
    // two rects overlap on x ∈ [wingW − skin, wingW] for z ≥ jZ + skin, so
    // the walk flows through the doorway unchanged.
    const lSkin = wd / 2 + 0.3;
    this._lShapeBounds = {
        a: { minX: lSkin, maxX: wingW - lSkin, minZ: -lenA/2 + lSkin, maxZ: lenA/2 - lSkin },
        b: { minX: wingW - lSkin, maxX: wingW + lenB - lSkin, minZ: jZ + lSkin, maxZ: lenA/2 - lSkin }
    };
    this.roomBounds = {
        minX: 0, maxX: wingW + lenB,
        minZ: -lenA / 2, maxZ: lenA / 2
    };
    // v3: the declared heights ride the layout meta so StructureBuilder
    // anchors (and anything else) read the SAME numbers this builder built
    // from — no drift possible. Undeclared ⇒ both equal wallHeight.
    this._layoutMeta = { type: 'l-shape', wingW, lenA, lenB, jZ, zStart, zLimit, aCX, aCZ, bCX, bCZ, hA, hB };

    // Iteration 2: structure parity for l-shape layouts (same rationale as
    // the rotunda fix — layout choice must not silently drop structure).
    this.addVenueStructure(data);
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

    // Ceiling — venue-aware (open-air venues have no ceiling; declared via
    // visual_config.open_air since Iteration 6 — the OPEN_AIR_VENUES set is
    // gone)
    if (!(this._venueVisualConfig || {}).open_air) {
        const _ceilColor = ceilColorFromConfig(this._venueVisualConfig);
        const ceilMat = this.isLowEnd
            ? new THREE.MeshLambertMaterial({ color: _ceilColor, side: THREE.BackSide })
            : new THREE.MeshStandardMaterial({ color: _ceilColor, roughness: 0.95, metalness: 0, side: THREE.BackSide });
        const ceil = new THREE.Mesh(new THREE.CircleGeometry(radius, 64), ceilMat);
        ceil.rotation.x = -Math.PI / 2;
        ceil.position.y = wallHeight;
        this.scene.add(ceil);
    }

    if (!this.isLowEnd) {
        const cl = new THREE.PointLight(0xfff8e8, venueFillIntensity.call(this, 3.0), radius * 2.5);
        cl.position.set(0, wallHeight - 0.4, 0);
        cl.castShadow = false;
        this.scene.add(cl);
    }

    this._rotundaRadius = radius;
    this.roomBounds = { minX: -(radius - 1), maxX: radius - 1, minZ: -(radius - 1), maxZ: radius - 1 };
    this._layoutMeta = { type: 'rotunda', radius };

    // Iteration 2: structure now runs for rotunda layouts too (parity with
    // square/corridor/circular). Today no slug branch consumes it here, but
    // admin-created venues with a structure descriptor must not silently
    // lose their structure based on layout choice.
    this.addVenueStructure(data);
}

// ── CIRCULAR (NEW — sculpture garden + void venues) ───────────────────────────
// Like rotunda but with NO walls and NO ceiling — just a circular ground plane.
// Boundary is enforced by _circularBoundsRadius (set here — single source).
export function createRoomCircular(data) {
    const imageCount = data.imageCount;
    const spacing    = CONFIG.room.artworkSpacing;

    // ── Float-field radius (shared pure planner) ─────────────────────────
    // The radius used to grow linearly with count (n·spacing / 2π): a 60-
    // artwork void reached r ≈ 33, a 200-artwork one r ≈ 111 — beyond the
    // camera far plane. Venues that declare placement.depth_bands compose
    // the hang in DEPTH (PlacementMath), so the floor only needs to fit
    // ceil(n/bands) works per ring — radius grows ~√n instead of n. The
    // same pure function that sizes the field lays it out; they cannot
    // disagree. Venues without the declaration take the legacy formula
    // bit-exactly (sculpture garden + the other voids unchanged).
    const vcBoot = this._venueVisualConfig || {};
    const bandsWanted = Math.max(1, Math.floor(vcBoot.placement?.depth_bands || 1));
    const field = computeFloatFieldRadius(imageCount, spacing, { depthBands: bandsWanted });
    const radius = field.radius;

    // Ground
    const floorMat = this.getFloorMaterial(data.floor_material);
    const floorTile = this._venueMaterialConfig?.floor_tile_meters ?? 2;
    if (floorMat.map) {
        floorMat.map.wrapS = floorMat.map.wrapT = THREE.RepeatWrapping;
        floorMat.map.repeat.set(radius / floorTile, radius / floorTile);
        floorMat.map.needsUpdate = true;
    }
    const floor = new THREE.Mesh(new THREE.CircleGeometry(radius, 64), floorMat);
    floor.rotation.x = -Math.PI / 2;
    floor.receiveShadow = !this.isLowEnd;
    this.scene.add(floor);
    // Iteration 2: handle for tier-aware floor treatments — Mirror Lake's
    // planar reflector hides this mesh on high tier, the gloss fallback
    // retunes its material on mobile (VenueDecorator.addMirrorLakeStructure).
    this._circularFloor = floor;

    // No walls, no ceiling — venue's addVenueStructure() adds hedges / particles / etc.

    // Subtle ceiling light (downward) so the space isn't pitch-black
    if (!this.isLowEnd) {
        const center = new THREE.PointLight(0xffffff, venueFillIntensity.call(this, 2.5), radius * 2);
        center.position.set(0, 8, 0);
        center.castShadow = false;
        this.scene.add(center);
    }

    // Layout meta — ArtworkPlacer uses this to arrange artworks in a circle
    this._layoutMeta = { type: 'circular', radius };
    // Walkway edge: enforced bound = radius − 0.5, set ONCE here. (The void
    // structures used to re-set the same value, and Collisions subtracted a
    // FURTHER 0.5 at enforcement time — a double inset that made the real
    // bound radius − 1.0 while every comment claimed radius − 0.5. The
    // enforcement paths now consume this value as-is.)
    this._circularBoundsRadius = radius - 0.5;
    // Axis bounds mirror the circular clamp so buildGallery's camera-far math
    // sees the real reach: the fixed fallback (0/0/0/0 → reach 6 → far 25)
    // never cleared the ring on this layout. Low-end (camera.far = 20) used
    // to CLIP every artwork past ~18 m — 30-piece shows were invisible from
    // the centre. With real bounds, far = radius·2.5 + 10 on every tier.
    this.roomBounds = {
        minX: -(radius - 1), maxX: radius - 1,
        minZ: -(radius - 1), maxZ: radius - 1,
    };

    // ── FIX (Iteration 2, root-cause): addVenueStructure was NEVER called
    // for circular layouts. Every structure branch for the garden + all four
    // void venues (hedges, trees, sky, sun, dust, starfield, colonnade,
    // mist, moon) was DEAD CODE at runtime — those venues shipped as a bare
    // floor disc + easels. Square and corridor rooms call this inside their
    // builders (createRoom L125, createRoomCorridor); circular now does too,
    // AFTER _layoutMeta exists so structure code reads the real radius.
    this.addVenueStructure(data);

    // ── Declared floor-edge fade (§4.2 Infinite Void "the endless must read"):
    // the ground disc dissolves into the venue's own background colour
    // instead of ending at a visible geometric seam. Generic, config-declared
    // (visual_config.floor_edge_fade) — any venue may opt in.
    const vc = this._venueVisualConfig || {};
    if (vc.floor_edge_fade === true) {
        addFloorEdgeFade(this, radius, parseColor(vc.background_color) || new THREE.Color(0x000000));
    }
}

// ── Venue-aware ceiling (skipped for open-air venues) ─────────────────────────
export function addVenueCeiling(roomWidth, roomDepth, wallHeight) {
    const vc = this._venueVisualConfig || {};
    // ── Iteration 6: open-air is DECLARED (visual_config.open_air), not a
    // slug set. Seeders/migration carry it for the garden + void family.
    if (vc.open_air === true) return; // no ceiling for outdoor / void

    const ceilColor = ceilColorFromConfig(vc);

    const ceilGeo = new THREE.PlaneGeometry(roomWidth, roomDepth);
    const ceilMat = this.isLowEnd
        ? new THREE.MeshLambertMaterial({ color: ceilColor })
        : new THREE.MeshStandardMaterial({ color: ceilColor, roughness: 0.95, metalness: 0 });

    const ceiling = new THREE.Mesh(ceilGeo, ceilMat);
    ceiling.rotation.x = Math.PI / 2;
    ceiling.position.set(0, wallHeight, 0);
    ceiling.receiveShadow = false;
    this.scene.add(ceiling);

    // Steel beams across the ceiling — DECLARED since Iteration 6
    // (visual_config.ceiling_beams; the industrial-loft slug branch is gone,
    // any venue may declare the same detail — merged into ONE mesh).
    if (vc.ceiling_beams === true) {
        const beamMat = new THREE.MeshStandardMaterial({ color: 0x2a2a28, roughness: 0.8, metalness: 0.6 });
        const beamCount = Math.max(2, Math.floor(roomDepth / 4));
        const beamGeo = new THREE.BoxGeometry(roomWidth, 0.18, 0.22);
        const beamParts = [];
        for (let i = 0; i < beamCount; i++) {
            beamParts.push({
                geo: beamGeo,
                pos: [0, wallHeight - 0.1, -roomDepth / 2 + (i + 1) * (roomDepth / (beamCount + 1))],
            });
        }
        this.scene.add(new THREE.Mesh(mergeParts(beamParts), beamMat));
        beamGeo.dispose();
    }

    // Perimeter neon strip lights along ceiling edges — DECLARED since
    // Iteration 6 (visual_config.ceiling_neon). Iteration 3: when the venue
    // declares the Rooms structure pass, the full perimeter neon + floor
    // light grid render from its structure descriptors (§4.9); this two-strip
    // ceiling remains the PRE-PASS rollback body, now reachable on any
    // venue by declaring the key (and on cyber by removing structure_pass).
    if (vc.ceiling_neon === true &&
        (this._venueVisualConfig || {}).structure_pass !== 'rooms') {
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
