// ─────────────────────────────────────────────────────────────────────────────
// VenueDecorator — applies venue-specific overrides
//
// Two paths:
//   1. Data-driven: if window.GALLERY_DATA.venueConfig.visual_config is set
//      (which it always is, because VenueConfigExporter populates it), apply
//      the JSON config from the database.
//   2. Legacy fallback: hardcoded switch — only runs if the JSON is missing.
//
// SCULPTURE GARDEN REDESIGN:
//   Old version: tall walls + dark green background + 2 missing GLBs.
//   New version: circular grass plane + sky dome + procedural hedges + trees +
//   stone path + artworks on easels. No walls, no missing GLBs.
//
// DARK MUSEUM FIX:
//   Divider walls are now registered as collision obstacles via registerObstacle
//   (see Collisions.js), so the player can't walk through them.
//
// NEW VOID VENUES:
//   Crystal Cathedral, Nebula Drift, Mirror Lake — all use the circular ground
//   plane + sky/particle decorations.
// ─────────────────────────────────────────────────────────────────────────────

import * as THREE from 'three';
import { CONFIG, OPEN_AIR_VENUES, parseColor } from './config.js';
import { loadGlb } from './AssetLoader.js';

// ── Top-level dispatcher ────────────────────────────────────────────────────
export function applyVenueOverrides(slug) {
    const cfg = window.GALLERY_DATA.venueConfig;
    if (cfg && cfg.visual_config && Object.keys(cfg.visual_config).length) {
        this.applyVenueConfig(cfg);
        return;
    }
    // Legacy fallback (kept for backward compat with galleries that have no
    // venueConfig — e.g. older seeded venues without visual_config)
    legacyVenueSwitch.call(this, slug);
}

// ── Data-driven config application ───────────────────────────────────────────
export function applyVenueConfig(cfg) {
    const v = cfg.visual_config || {};
    const m = cfg.material_config || {};

    if (v.wall_height)                CONFIG.room.wallHeight = v.wall_height;
    if (v.wall_depth)                 CONFIG.room.wallDepth  = v.wall_depth;
    if (v.background_color)           this.scene.background  = parseColor(v.background_color);
    if (v.fog_color) {
        this.scene.fog = new THREE.Fog(
            parseColor(v.fog_color),
            v.fog_near ?? 10,
            v.fog_far  ?? 30
        );
    } else if (v.fog_color === null) {
        this.scene.fog = null;
    }
    if (v.ambient_color)              this._venueAmbientColor     = parseColor(v.ambient_color);
    if (v.ambient_intensity != null)  this._venueAmbientIntensity = v.ambient_intensity;
    if (v.spot_intensity != null)     this._venueSpotIntensity    = v.spot_intensity;
    if (v.fill_intensity != null)     this._venueFillIntensity    = v.fill_intensity;
    if (v.tone_mapping_exposure != null) this.renderer.toneMappingExposure = v.tone_mapping_exposure;
    if (v.frame_override)             this._venueFrameOverride    = v.frame_override;
    if (v.ceiling_type)               this._venueCeilingType      = v.ceiling_type;

    this._venueMaterialConfig = m;
    this._venueSlug = cfg.slug || 'white-cube';

    if (Array.isArray(cfg.decorations) && cfg.decorations.length) {
        this.loadDecorations(cfg.decorations);
    }
    if (Array.isArray(cfg.lighting_fixtures) && cfg.lighting_fixtures.length) {
        this.addCustomLights(cfg.lighting_fixtures);
    }
    if (cfg.hdri_url) this._customHdriUrl = cfg.hdri_url;
}

// ── Legacy hardcoded switch (fallback only) ─────────────────────────────────
function legacyVenueSwitch(slug) {
    switch (slug) {
        case 'white-cube':
        default:
            CONFIG.room.wallHeight = 4;
            this.scene.background  = new THREE.Color(0x0f0f0f);
            this.scene.fog         = new THREE.Fog(0x0f0f0f, 10, 30);
            this._venueSlug        = 'white-cube';
            break;
        case 'industrial-loft':
            CONFIG.room.wallHeight = 7;
            CONFIG.room.wallDepth  = 0.5;
            this.scene.background  = new THREE.Color(0x111008);
            this.scene.fog         = new THREE.Fog(0x111008, 8, 35);
            this._venueSlug        = 'industrial-loft';
            break;
        case 'dark-museum':
            CONFIG.room.wallHeight = 5;
            this.scene.background  = new THREE.Color(0x020202);
            this.scene.fog         = new THREE.Fog(0x020202, 5, 18);
            this._venueSlug        = 'dark-museum';
            break;
        case 'zen-gallery':
            CONFIG.room.wallHeight = 3.2;
            this.scene.background  = new THREE.Color(0x1a1710);
            this.scene.fog         = new THREE.Fog(0x1a1710, 12, 40);
            this._venueSlug        = 'zen-gallery';
            break;
        case 'luxury-penthouse':
            CONFIG.room.wallHeight = 4.5;
            this.scene.background  = new THREE.Color(0x08090d);
            this.scene.fog         = new THREE.Fog(0x08090d, 8, 25);
            this._venueSlug        = 'luxury-penthouse';
            break;
        case 'cyber-gallery':
            CONFIG.room.wallHeight = 6;
            this.scene.background  = new THREE.Color(0x020412);
            this.scene.fog         = new THREE.Fog(0x020412, 6, 22);
            this._venueSlug        = 'cyber-gallery';
            break;
        case 'sculpture-garden':
            CONFIG.room.wallHeight = 8;
            this.scene.background  = new THREE.Color(0x0d1a0d);
            this.scene.fog         = new THREE.Fog(0x0d1a0d, 10, 45);
            this._venueSlug        = 'sculpture-garden';
            break;
        case 'infinite-void':
            CONFIG.room.wallHeight = 20;
            this.scene.background  = new THREE.Color(0x000000);
            this.scene.fog         = null;
            this._venueSlug        = 'infinite-void';
            break;
    }
}

// ── Load 3D decoration props (GLB) asynchronously ─────────────────────────────
export async function loadDecorations(decorations) {
    for (const dec of decorations) {
        try {
            const url = dec.model_url || dec.model_path;
            if (!url) continue;
            const obj = await loadGlb.call(this, url);
            if (dec.position) obj.position.set(dec.position[0], dec.position[1], dec.position[2]);
            if (dec.rotation) obj.rotation.set(dec.rotation[0], dec.rotation[1], dec.rotation[2]);
            if (typeof dec.scale === 'number')      obj.scale.setScalar(dec.scale);
            else if (Array.isArray(dec.scale))       obj.scale.set(dec.scale[0], dec.scale[1], dec.scale[2]);

            obj.traverse(child => {
                if (child.isMesh) {
                    child.castShadow    = !this.isLowEnd;
                    child.receiveShadow = !this.isLowEnd;
                    // Register physical props as collision obstacles
                    if (dec.solid !== false) this.registerObstacle(child);
                }
            });
            this.scene.add(obj);
        } catch (err) {
            console.warn('Decoration load failed:', dec.model_path || dec.model_url, err);
        }
    }
}

// ── Add custom lighting fixtures (from lighting_fixtures JSON) ────────────────
export function addCustomLights(fixtures) {
    for (const f of fixtures) {
        let light;
        const color = f.color ? parseColor(f.color) : 0xffffff;
        const intensity = f.intensity ?? 1;
        switch (f.type) {
            case 'point':       light = new THREE.PointLight(color, intensity, f.distance ?? 0, f.decay ?? 2); break;
            case 'spot':        light = new THREE.SpotLight(color, intensity, f.distance ?? 0); break;
            case 'directional': light = new THREE.DirectionalLight(color, intensity); break;
            case 'strip':       light = new THREE.PointLight(color, intensity, f.distance ?? 10, f.decay ?? 1.5); break;
            default: continue;
        }
        if (f.position) light.position.set(f.position[0], f.position[1], f.position[2]);
        if (f.cast_shadow !== false && light.castShadow !== undefined) light.castShadow = true;
        this.scene.add(light);
    }
}

// ── Venue structure (in-room details: beams, dividers, hedges, particles) ────
// This is the venue-specific decoration that's NOT a GLB — it's procedural
// geometry we build in code. Each venue can have bespoke code here.
export function addVenueStructure(data) {
    const slug = this._venueSlug || 'white-cube';
    const wh   = CONFIG.room.wallHeight;

    if (slug === 'industrial-loft') {
        addIndustrialLoftStructure.call(this, data);
    } else if (slug === 'dark-museum') {
        addDarkMuseumStructure.call(this, data); // includes collision registration
    } else if (slug === 'sculpture-garden') {
        addSculptureGardenStructure.call(this, data); // grass, hedges, trees, sky
    } else if (slug === 'infinite-void' || slug === 'crystal-cathedral' ||
               slug === 'nebula-drift' || slug === 'mirror-lake') {
        addVoidVenueStructure.call(this, data);
    }
    // white-cube + others: no structure — clean is the point
}

// ── INDUSTRIAL LOFT — steel beams + columns + floor grates ───────────────────
function addIndustrialLoftStructure(data) {
    const beamMat = this.isLowEnd
        ? new THREE.MeshLambertMaterial({ color: 0x2a2a2a })
        : new THREE.MeshStandardMaterial({ color: 0x1e1e1e, roughness: 0.6, metalness: 0.9 });

    const meta = this._layoutMeta || {};
    const length = meta.length || 20;
    const width  = meta.width  || 6;

    const beamCount = Math.max(3, Math.floor(length / 5));
    const beamStep  = length / (beamCount + 1);
    const beamGeo   = new THREE.BoxGeometry(width + 0.4, 0.25, 0.3);

    for (let i = 1; i <= beamCount; i++) {
        const beam = new THREE.Mesh(beamGeo, beamMat);
        beam.position.set(-length / 2 + i * beamStep, CONFIG.room.wallHeight - 0.12, 0);
        this.scene.add(beam);
    }

    // Vertical column supports at every other beam
    const colGeo = new THREE.BoxGeometry(0.18, CONFIG.room.wallHeight, 0.18);
    for (let i = 1; i <= beamCount; i += 2) {
        const xPos = -length / 2 + i * beamStep;
        [-width / 2 + 0.09, width / 2 - 0.09].forEach(zPos => {
            const col = new THREE.Mesh(colGeo, beamMat);
            col.position.set(xPos, CONFIG.room.wallHeight / 2, zPos);
            this.scene.add(col);
        });
    }

    // Floor grate strips
    const grateMat = this.isLowEnd
        ? new THREE.MeshLambertMaterial({ color: 0x111111 })
        : new THREE.MeshStandardMaterial({ color: 0x0d0d0d, roughness: 1.0, metalness: 0.3 });
    const grateGeo = new THREE.BoxGeometry(width + 0.3, 0.02, 0.15);
    for (let i = 1; i <= beamCount; i++) {
        const grate = new THREE.Mesh(grateGeo, grateMat);
        grate.position.set(-length / 2 + i * beamStep, 0.01, 0);
        this.scene.add(grate);
    }
}

// ── DARK MUSEUM — dividers + skirting board (with collision) ────────────────
// FIX: divider walls are now registered as collision obstacles so the player
// can't walk through them.
function addDarkMuseumStructure(data) {
    const meta = this._layoutMeta || {};
    const wl   = Math.max(8, meta.wallLength || 14);
    const wh   = CONFIG.room.wallHeight;

    const divMat = this.isLowEnd
        ? new THREE.MeshLambertMaterial({ color: 0x080808 })
        : new THREE.MeshStandardMaterial({ color: 0x050505, roughness: 0.95, metalness: 0.0 });

    const dividerDepth  = 0.3;
    const dividerLength = wl * 0.28; // reach 28% into the room
    const dividerH      = wh;
    const zOffset       = wl * 0.18; // asymmetric bays

    [
        { x: -wl / 2 + dividerLength / 2, z:  zOffset },
        { x:  wl / 2 - dividerLength / 2, z: -zOffset },
    ].forEach(cfg => {
        const geo  = new THREE.BoxGeometry(dividerLength, dividerH, dividerDepth);
        const mesh = new THREE.Mesh(geo, divMat);
        mesh.position.set(cfg.x, dividerH / 2, cfg.z);
        mesh.castShadow    = false;
        mesh.receiveShadow = !this.isLowEnd;
        this.scene.add(mesh);

        // 🔑 FIX: register the divider as a collision obstacle
        this.registerObstacle(mesh, 0.4);
    });

    // Skirting / baseboard along all four walls
    const skirtMat = this.isLowEnd
        ? new THREE.MeshLambertMaterial({ color: 0x0a0a0a })
        : new THREE.MeshStandardMaterial({ color: 0x080808, roughness: 0.8, metalness: 0.4 });
    const skirtH   = 0.12;
    const skirtGeo = new THREE.BoxGeometry(wl, skirtH, 0.06);
    [
        { x: 0,      z: -wl / 2, ry: 0          },
        { x: 0,      z:  wl / 2, ry: Math.PI    },
        { x: -wl/2,  z: 0,       ry: Math.PI/2  },
        { x:  wl/2,  z: 0,       ry: -Math.PI/2 },
    ].forEach(cfg => {
        const mesh = new THREE.Mesh(skirtGeo, skirtMat);
        mesh.position.set(cfg.x, skirtH / 2 + 0.01, cfg.z);
        mesh.rotation.y = cfg.ry;
        this.scene.add(mesh);
    });
}

// ── SCULPTURE GARDEN — full outdoor redesign ────────────────────────────────
// No walls, no ceiling. Circular grass plane + hedge boundary + sky dome +
// procedural trees + stone path + central pedestal. Artworks are placed on
// easels (built procedurally — see ArtworkPlacer.js for the easel placement).
function addSculptureGardenStructure(data) {
    const meta = this._layoutMeta || {};
    const radius = meta.radius || 15;

    // ── 1. Sky dome — gradient blue (top dark, horizon light) ──────────────
    const skyGeo = new THREE.SphereGeometry(radius * 3, 32, 16);
    const skyMat = new THREE.ShaderMaterial({
        side: THREE.BackSide,
        uniforms: {
            topColor:    { value: new THREE.Color(0x2a5a9a) },
            bottomColor: { value: new THREE.Color(0xc8d8e8) },
            offset:      { value: 0.4 },
            exponent:    { value: 0.6 },
        },
        vertexShader: `
            varying vec3 vWorldPosition;
            void main() {
                vec4 worldPosition = modelMatrix * vec4(position, 1.0);
                vWorldPosition = worldPosition.xyz;
                gl_Position = projectionMatrix * modelViewMatrix * vec4(position, 1.0);
            }
        `,
        fragmentShader: `
            uniform vec3 topColor;
            uniform vec3 bottomColor;
            uniform float offset;
            uniform float exponent;
            varying vec3 vWorldPosition;
            void main() {
                float h = normalize(vWorldPosition + offset).y;
                gl_FragColor = vec4(mix(bottomColor, topColor, max(pow(max(h, 0.0), exponent), 0.0)), 1.0);
            }
        `,
    });
    const sky = new THREE.Mesh(skyGeo, skyMat);
    this.scene.add(sky);

    // ── 2. Sun — directional light from above + visual sphere ──────────────
    const sunLight = new THREE.DirectionalLight(0xfff4e0, 0.8);
    sunLight.position.set(radius * 0.6, radius * 1.5, -radius * 0.4);
    this.scene.add(sunLight);

    const sunGeo = new THREE.SphereGeometry(2, 16, 16);
    const sunMat = new THREE.MeshBasicMaterial({ color: 0xfff4e0 });
    const sun = new THREE.Mesh(sunGeo, sunMat);
    sun.position.copy(sunLight.position);
    this.scene.add(sun);

    // ── 3. Hedge boundary (low wall of greenery around the circle) ─────────
    // Low enough (1.2m) that the player can see over, dense enough to feel
    // like a garden boundary. Registered as collision obstacles.
    const hedgeMat = this.isLowEnd
        ? new THREE.MeshLambertMaterial({ color: 0x2a4a1a })
        : new THREE.MeshStandardMaterial({ color: 0x2a4a1a, roughness: 1.0, metalness: 0.0 });

    const hedgeHeight = 1.2;
    const hedgeThickness = 0.5;
    const hedgeSegments = 24;
    const hedgeGeo = new THREE.BoxGeometry(2 * Math.PI * radius / hedgeSegments + 0.1, hedgeHeight, hedgeThickness);
    for (let i = 0; i < hedgeSegments; i++) {
        const angle = (i / hedgeSegments) * Math.PI * 2;
        const hedge = new THREE.Mesh(hedgeGeo, hedgeMat);
        hedge.position.set(Math.sin(angle) * radius, hedgeHeight / 2, Math.cos(angle) * radius);
        hedge.rotation.y = -angle + Math.PI / 2;
        this.scene.add(hedge);
        this.registerObstacle(hedge, 0.1);
    }

    // ── 4. Procedural trees (cylinder trunk + 2 cone foliage) ──────────────
    const treePositions = [
        { x: -radius * 0.7, z:  radius * 0.5, scale: 1.0 },
        { x:  radius * 0.6, z:  radius * 0.7, scale: 1.2 },
        { x: -radius * 0.5, z: -radius * 0.6, scale: 0.9 },
        { x:  radius * 0.8, z: -radius * 0.3, scale: 1.1 },
    ];
    const trunkMat = this.isLowEnd
        ? new THREE.MeshLambertMaterial({ color: 0x3a2418 })
        : new THREE.MeshStandardMaterial({ color: 0x3a2418, roughness: 0.9, metalness: 0.0 });
    const leafMat = this.isLowEnd
        ? new THREE.MeshLambertMaterial({ color: 0x2a4a1a })
        : new THREE.MeshStandardMaterial({ color: 0x2a4a1a, roughness: 1.0, metalness: 0.0 });
    const trunkGeo = new THREE.CylinderGeometry(0.2, 0.3, 2.5, 8);
    const leafGeo  = new THREE.ConeGeometry(1.5, 3.0, 8);

    treePositions.forEach(pos => {
        const tree = new THREE.Group();
        const trunk = new THREE.Mesh(trunkGeo, trunkMat);
        trunk.position.y = 1.25;
        tree.add(trunk);
        const leaves1 = new THREE.Mesh(leafGeo, leafMat);
        leaves1.position.y = 3.5;
        tree.add(leaves1);
        const leaves2 = new THREE.Mesh(leafGeo, leafMat);
        leaves2.position.y = 4.5;
        leaves2.scale.setScalar(0.7);
        tree.add(leaves2);
        tree.position.set(pos.x, 0, pos.z);
        tree.scale.setScalar(pos.scale);
        this.scene.add(tree);
        // Trunk collides (so player can't walk through tree)
        this.registerObstacle(trunk, 0.1);
    });

    // ── 5. Stone path — circular segments of lighter-coloured ground ──────
    const pathMat = this.isLowEnd
        ? new THREE.MeshLambertMaterial({ color: 0x9a9080 })
        : new THREE.MeshStandardMaterial({ color: 0x9a9080, roughness: 0.95, metalness: 0.0 });
    const pathGeo = new THREE.RingGeometry(0, 1.5, 16);
    const pathSteps = 6;
    for (let i = 0; i < pathSteps; i++) {
        const t = i / (pathSteps - 1);
        const r = radius * 0.3 + t * radius * 0.4;
        const angle = t * Math.PI * 1.5 - Math.PI / 2;
        const stone = new THREE.Mesh(pathGeo, pathMat);
        stone.rotation.x = -Math.PI / 2;
        stone.position.set(Math.cos(angle) * r, 0.02, Math.sin(angle) * r);
        stone.scale.setScalar(0.8 + Math.random() * 0.4);
        stone.rotation.z = Math.random() * Math.PI;
        this.scene.add(stone);
    }

    // ── 6. Central pedestal — stone column for a hero sculpture ────────────
    const pedestalMat = this.isLowEnd
        ? new THREE.MeshLambertMaterial({ color: 0x808080 })
        : new THREE.MeshStandardMaterial({ color: 0xa0a0a0, roughness: 0.7, metalness: 0.1 });
    const pedestalGeo = new THREE.CylinderGeometry(0.6, 0.7, 1.2, 16);
    const pedestal = new THREE.Mesh(pedestalGeo, pedestalMat);
    pedestal.position.set(0, 0.6, 0);
    this.scene.add(pedestal);
    this.registerObstacle(pedestal, 0.2);

    // ── 7. Set circular bounds (player can't walk past the hedge) ─────────
    this._circularBoundsRadius = radius - 0.5;
}

// ── VOID VENUES — Infinite Void + 3 new variants ────────────────────────────
// All four share the "no walls, no ceiling, abstract atmosphere" feel.
// Individual character comes from the bespoke decorations below.
function addVoidVenueStructure(data) {
    const slug = this._venueSlug;
    const meta = this._layoutMeta || {};
    const radius = meta.radius || 15;

    // Common: circular bounds + subtle ambient
    this._circularBoundsRadius = radius - 0.5;

    if (slug === 'infinite-void') {
        // Original infinite void — pure black + soft ambient blue
        addInfiniteVoidParticles.call(this, radius);
    } else if (slug === 'crystal-cathedral') {
        addCrystalCathedralStructure.call(this, radius);
    } else if (slug === 'nebula-drift') {
        addNebulaDriftStructure.call(this, radius);
    } else if (slug === 'mirror-lake') {
        addMirrorLakeStructure.call(this, radius);
    }
}

// INFINITE VOID — slow-floating dust particles, very subtle
function addInfiniteVoidParticles(radius) {
    const particleCount = 200;
    const positions = new Float32Array(particleCount * 3);
    for (let i = 0; i < particleCount; i++) {
        positions[i * 3]     = (Math.random() - 0.5) * radius * 2;
        positions[i * 3 + 1] = Math.random() * 5;
        positions[i * 3 + 2] = (Math.random() - 0.5) * radius * 2;
    }
    const geo = new THREE.BufferGeometry();
    geo.setAttribute('position', new THREE.BufferAttribute(positions, 3));
    const mat = new THREE.PointsMaterial({
        color: 0xaaccff,
        size: 0.05,
        transparent: true,
        opacity: 0.6,
        sizeAttenuation: true,
    });
    const points = new THREE.Points(geo, mat);
    points.userData.isParticle = true;
    points.userData.baseY = positions;
    this.scene.add(points);
    this._particleSystems = this._particleSystems || [];
    this._particleSystems.push({ obj: points, type: 'drift' });
}

// CRYSTAL CATHEDRAL — floating glass shards catching refracted light
function addCrystalCathedralStructure(radius) {
    const shardMat = new THREE.MeshPhysicalMaterial({
        color: 0xffffff,
        roughness: 0.05,
        metalness: 0.0,
        transmission: 0.95,
        thickness: 0.5,
        ior: 1.5,
        transparent: true,
        opacity: 0.6,
        side: THREE.DoubleSide,
    });

    // 12 floating glass shards at varying heights
    const shardGeo = new THREE.OctahedronGeometry(1.0, 0);
    for (let i = 0; i < 12; i++) {
        const shard = new THREE.Mesh(shardGeo, shardMat);
        const angle = (i / 12) * Math.PI * 2;
        const r = radius * 0.5 + Math.random() * radius * 0.3;
        shard.position.set(Math.cos(angle) * r, 2 + Math.random() * 4, Math.sin(angle) * r);
        shard.rotation.set(Math.random() * Math.PI, Math.random() * Math.PI, Math.random() * Math.PI);
        shard.scale.setScalar(0.8 + Math.random() * 1.5);
        this.scene.add(shard);

        // Coloured point light inside each shard — refracted glow
        const colors = [0xffaaaa, 0xaaffaa, 0xaaaaff, 0xffffaa, 0xffaaff, 0xaaffff];
        const c = colors[i % colors.length];
        const light = new THREE.PointLight(c, 0.5, 6);
        light.position.copy(shard.position);
        this.scene.add(light);

        // Register for slow rotation animation in animate()
        this._particleSystems = this._particleSystems || [];
        this._particleSystems.push({ obj: shard, type: 'rotate-slow' });
    }

    // Floor: crystal — high metalness, mirror-like
    // (Floor is set in RoomBuilder via floorMaterial="marble" + high metalness
    // override in the venue's material_config.)
}

// NEBULA DRIFT — particle cloud + starfield + slow camera drift feel
function addNebulaDriftStructure(radius) {
    // 1. Starfield — distant points in all directions
    const starCount = 800;
    const starPositions = new Float32Array(starCount * 3);
    const starColors    = new Float32Array(starCount * 3);
    for (let i = 0; i < starCount; i++) {
        // Spherical distribution far away
        const theta = Math.random() * Math.PI * 2;
        const phi   = Math.acos(Math.random() * 2 - 1);
        const r     = radius * 4 + Math.random() * radius * 2;
        starPositions[i * 3]     = r * Math.sin(phi) * Math.cos(theta);
        starPositions[i * 3 + 1] = r * Math.cos(phi);
        starPositions[i * 3 + 2] = r * Math.sin(phi) * Math.sin(theta);

        // Purple/blue/pink star colours
        const hue = 0.7 + Math.random() * 0.15; // 0.7-0.85
        const sat = 0.4 + Math.random() * 0.4;
        const lit = 0.5 + Math.random() * 0.4;
        const c = new THREE.Color().setHSL(hue, sat, lit);
        starColors[i * 3]     = c.r;
        starColors[i * 3 + 1] = c.g;
        starColors[i * 3 + 2] = c.b;
    }
    const starGeo = new THREE.BufferGeometry();
    starGeo.setAttribute('position', new THREE.BufferAttribute(starPositions, 3));
    starGeo.setAttribute('color',    new THREE.BufferAttribute(starColors, 3));
    const starMat = new THREE.PointsMaterial({
        size: 0.4,
        vertexColors: true,
        transparent: true,
        opacity: 0.9,
        sizeAttenuation: true,
    });
    this.scene.add(new THREE.Points(starGeo, starMat));

    // 2. Drifting nebula cloud — closer, coloured particles
    const nebCount = 400;
    const nebPositions = new Float32Array(nebCount * 3);
    for (let i = 0; i < nebCount; i++) {
        nebPositions[i * 3]     = (Math.random() - 0.5) * radius * 2;
        nebPositions[i * 3 + 1] = Math.random() * 6 - 1;
        nebPositions[i * 3 + 2] = (Math.random() - 0.5) * radius * 2;
    }
    const nebGeo = new THREE.BufferGeometry();
    nebGeo.setAttribute('position', new THREE.BufferAttribute(nebPositions, 3));
    const nebMat = new THREE.PointsMaterial({
        color: 0x8844ff,
        size: 0.15,
        transparent: true,
        opacity: 0.5,
        sizeAttenuation: true,
        blending: THREE.AdditiveBlending,
    });
    const nebPoints = new THREE.Points(nebGeo, nebMat);
    this.scene.add(nebPoints);

    this._particleSystems = this._particleSystems || [];
    this._particleSystems.push({ obj: nebPoints, type: 'drift' });

    // 3. Soft purple backlight
    const backLight = new THREE.PointLight(0x8844ff, 0.5, radius * 2);
    backLight.position.set(0, 5, 0);
    this.scene.add(backLight);
}

// MIRROR LAKE — perfectly reflective floor + floating artworks + soft fog
function addMirrorLakeStructure(radius) {
    // The mirror effect comes from floor_material="marble" + very low roughness
    // in the venue's material_config. Here we add: floating particles + soft
    // moonlight + faint mist.

    // Moonlight
    const moon = new THREE.DirectionalLight(0xb0c8ff, 0.6);
    moon.position.set(radius * 0.8, radius * 1.5, -radius * 0.5);
    this.scene.add(moon);

    // Moon visual
    const moonMesh = new THREE.Mesh(
        new THREE.SphereGeometry(1.5, 16, 16),
        new THREE.MeshBasicMaterial({ color: 0xe0e8ff })
    );
    moonMesh.position.copy(moon.position);
    this.scene.add(moonMesh);

    // Drifting mist particles
    const mistCount = 150;
    const positions = new Float32Array(mistCount * 3);
    for (let i = 0; i < mistCount; i++) {
        positions[i * 3]     = (Math.random() - 0.5) * radius * 2;
        positions[i * 3 + 1] = 0.1 + Math.random() * 2;
        positions[i * 3 + 2] = (Math.random() - 0.5) * radius * 2;
    }
    const geo = new THREE.BufferGeometry();
    geo.setAttribute('position', new THREE.BufferAttribute(positions, 3));
    const mat = new THREE.PointsMaterial({
        color: 0xc0d0ff,
        size: 0.4,
        transparent: true,
        opacity: 0.3,
        sizeAttenuation: true,
        blending: THREE.AdditiveBlending,
    });
    const mist = new THREE.Points(geo, mat);
    this.scene.add(mist);
    this._particleSystems = this._particleSystems || [];
    this._particleSystems.push({ obj: mist, type: 'drift' });
}
