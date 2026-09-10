#!/usr/bin/env node
// ─────────────────────────────────────────────────────────────────────────────
// salon-qa.mjs — the venue QA gate for The Salon (v3.0.0 "two rooms").
//
//   node scripts/venue-qa/salon-qa.mjs
//
// Runs WITHOUT a PHP stack (plain Node over the repo checkout). Layering:
// pins CONTRACTS (payload parity, divider placement geometry, determinism)
// here, while tests/Feature/VenueSalonIterationTest.php pins the DB side
// and scripts/harness/probe-salon-poses.mjs captures the visual evidence.
//
// Checks:
//   A. Seeder contract — the v3.0.0 row declares the authored identity
//      (two-room divider plan, curtain primitive + collision, wide double
//      door, per-room roses, warm readable rig, texture authority,
//      restraint, side-wall tangent-yaw discipline — the phantom-slab
//      guard).
//   B. DB↔harness sync — the PHP-less harness renders the same payload a
//      fresh install seeds (drift here means screenshots stop meaning
//      anything).
//   C. Divider placement invariants — driven through the REAL shared
//      modules (PlacementCuration.resolveDividerHang) across the capacity
//      range: both rooms hang works at every count 5→30, the hero keeps a
//      dead-centre odd slot, frames never reach the curtain fabric or the
//      doorcase, rows never touch, no two frames intersect, every work
//      finds a slot, the room stays domestic, and the whole plan is
//      deterministic.
//   D. Legacy parity — venues that declare none of the keys resolve exactly
//      as before (resolveSquareHang path untouched, resolveDividerHang
//      returns null without the key).
//   E. Structure↔placement coherence — the curtain's declared walk gap
//      equals the plan's opening; the keeps clear the real geometry.
//   F. JS hygiene — no venue slug in the runtime (DoD #7).
// ─────────────────────────────────────────────────────────────────────────────
import { readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const rel = (p) => path.join(root, p);

let failures = 0;
const ok = (name, cond, detail = '') => {
    if (cond) console.log(`  ✓ ${name}`);
    else { failures++; console.error(`  ✗ ${name}${detail ? ` — ${detail}` : ''}`); }
};
const section = (name) => console.log(`\n── ${name} ${'─'.repeat(Math.max(1, 62 - name.length))}`);

// ── payload extraction helpers ────────────────────────────────────────────
function seederSalonChunk() {
    const src = readFileSync(rel('database/seeders/VenueTemplateSeeder.php'), 'utf8');
    const i = src.indexOf("'slug'          => 'the-salon'");
    if (i === -1) throw new Error('the-salon row not found in seeder');
    const nextSlug = src.indexOf("'slug'", i + 10);
    return src.slice(i, nextSlug === -1 ? undefined : nextSlug);
}
function harnessSalonObject() {
    const src = readFileSync(rel('scripts/harness/harness.html'), 'utf8');
    const i = src.indexOf("'the-salon': {");
    if (i === -1) throw new Error('the-salon entry not found in harness');
    return src.slice(i, src.indexOf("'luxury-penthouse': {", i));
}
/** Pull every `key => [...]` / `key: [...]` literal (balanced) as raw text. */
function rawLiteral(text, phpStyle) {
    const keys = ['placement', 'structure', 'post_fx', 'material_config', 'visual_config', 'default_settings'];
    const out = {};
    for (const key of keys) {
        const marker = phpStyle ? `'${key}'` : `${key}:`;
        const ki = text.indexOf(marker);
        if (ki === -1) continue;
        const open = text.indexOf('[', ki);
        const braceOpen = text.indexOf('{', ki);
        const useBrace = braceOpen !== -1 && braceOpen < open;
        const oc = useBrace ? '{' : '[';
        const cc = useBrace ? '}' : ']';
        let depth = 0, k = open, inStr = false, q = '';
        while (k < text.length) {
            const c = text[k];
            if (inStr) { if (c === q && text[k - 1] !== '\\') inStr = false; }
            else if (c === '\'' || c === '"') { inStr = true; q = c; }
            else if (c === oc) depth++;
            else if (c === cc) { depth--; if (depth === 0) break; }
            k++;
        }
        out[key] = text.slice(open, k + 1);
    }
    return out;
}

// ── A. Seeder contract ─────────────────────────────────────────────────────
section('A. Seeder contract (the-salon v3 row)');
const chunk = seederSalonChunk();
const phpLit = rawLiteral(chunk, true);

ok('version 3.0.0 declared', /'version'\s*=>\s*'3\.0\.0'/.test(chunk));
ok('the two-room divider plan declared (room_divider)',
    /'room_divider'\s*=>\s*\['at'\s*=>\s*0\.5,\s*'opening'\s*=>\s*2\.4,\s*'keep'\s*=>\s*0\.55,\s*'door_keep'\s*=>\s*1\.15,\s*'spacing'\s*=>\s*2\.4\]/.test(chunk));
ok('keep_clear is superseded (absent)', !chunk.includes("'keep_clear'"));
ok('the room stays domestic (wall_length_cap 12.6)', /'wall_length_cap'\s*=>\s*12\.6/.test(chunk));
ok('the two-line salon hang declared (salon_rows 2)', /'salon_rows'\s*=>\s*2(?!\.)/.test(chunk));
ok('the focal hero faces the arrival (focal_wall front)', /'focal_wall'\s*=>\s*'front'/.test(chunk));
ok('intimate rhythm + orientation pairing kept',
    /'density'\s*=>\s*'intimate'/.test(chunk) && /'pair_orientation'\s*=>\s*true/.test(chunk));
ok('readable warm rig (exposure 1.0, ambient 0.5)',
    /'tone_mapping_exposure'\s*=>\s*1\.0/.test(chunk) && /'ambient_intensity'\s*=>\s*0\.5/.test(chunk));
ok('murk dead (fog starts at 22, ends 70)',
    /'fog_near'\s*=>\s*22/.test(chunk) && /'fog_far'\s*=>\s*70/.test(chunk));
ok('texture authority declared (texture_tint true)', /'texture_tint'\s*=>\s*true/.test(chunk));
ok('restraint declared (bloom off)',
    phpLit.post_fx.includes("'bloom' => false"));
ok('artwork legibility floor (base 0.3, pool 12)',
    /'artwork_light_base'\s*=>\s*0\.3/.test(chunk) && /'artwork_light_pool_cap'\s*=>\s*12/.test(chunk));
ok('per-row caps declared (large at eye, smaller above)',
    /'maxHeight'\s*=>\s*1\.45/.test(chunk) && /'maxHeight'\s*=>\s*0\.84/.test(chunk));
ok('fresh-install copy names the two-room venue',
    /two rooms/.test(chunk) && /salon-style/.test(chunk));
ok('rails stand ABOVE the hang (y 3.53 — the v1 clip is dead)',
    chunk.includes('[0, 3.53, 0.0]'));
ok('no mid-wall rail remains (the v1 0.9 m rail is gone)', !chunk.includes('[0, 0.9, 0.045]'));

// ── A2. The v3 identity elements ─────────────────────────────────────────
{
    ok('the curtain ships (parametric primitive, collision, both-side fabric)',
        /'id'\s*=>\s*'salon-curtain',\s*'primitive'\s*=>\s*'curtain'/.test(chunk)
        && /'opening'\s*=>\s*2\.4/.test(chunk)
        && /'collide'\s*=>\s*true,\s*'tier_floor'\s*=>\s*'low'\]/.test(chunk)
        && /'side'\s*=>\s*'double'/.test(chunk));
    ok('the curtain hardware ships (brass rod, rings, tie bands)',
        /'hardware'\s*=>\s*'bronze'/.test(chunk) && /'tie_y'\s*=>\s*1\.12/.test(chunk)
        && /'folds'\s*=>\s*6/.test(chunk));
    ok('the wide double-leaf threshold ships (2 × 0.92 m leaves, plates, both knobs)',
        /'id'\s*=>\s*'door-leaf-l'/.test(chunk) && /'id'\s*=>\s*'door-leaf-r'/.test(chunk)
        && /\[0\.92,\s*2\.62,\s*0\.045\]/.test(chunk)
        && /'id'\s*=>\s*'door-plate-l'/.test(chunk) && /'id'\s*=>\s*'door-plate-r'/.test(chunk)
        && /'id'\s*=>\s*'door-knob-l'/.test(chunk) && /'id'\s*=>\s*'door-knob-r'/.test(chunk));
    ok('the v2/v2.1 door forms are retired',
        !chunk.includes("'id' => 'door-leaf',") && !chunk.includes("'id' => 'door-knob',"));
    ok('both rooms carry a ceiling rose',
        /'id'\s*=>\s*'rose-disc-a'/.test(chunk) && /'id'\s*=>\s*'rose-disc-b'/.test(chunk));
    ok('the single rose is retired', !chunk.includes("'id' => 'rose-disc',"));
}

// ── A3. The phantom-slab guard ───────────────────────────────────────────
{
    const sideFitRe = /'id'\s*=>\s*'([\w-]+)',\s*'primitive'\s*=>\s*'[\wa-z-]+',\s*'at'\s*=>\s*\['from'\s*=>\s*'wall_(left|right)'/g;
    const sideIds = [...chunk.matchAll(sideFitRe)].map(m => m[1]);
    ok('side-wall anchored descriptors exist to guard', sideIds.length >= 10, `found ${sideIds.length}`);
    const noTurn = sideIds.filter(id => {
        const elRe = new RegExp(`'id'\\s*=>\\s*'${id}'[^\\n]*`);
        const line = chunk.match(elRe)?.[0] || '';
        return !/\bturn'\s*=>\s*'(in|out)'/.test(line);
    });
    ok('every side-wall descriptor declares the tangent yaw (turn)', noTurn.length === 0,
        `missing turn: ${noTurn.join(', ')}`);
}

// ── B. DB↔harness sync ───────────────────────────────────────────────────
section('B. DB↔harness sync (drift = screenshots stop meaning anything)');
const harness = harnessSalonObject();
const jsLit = rawLiteral(harness, false);

const stripKeys = (t, phpStyle) => phpStyle
    ? t.replace(/'([A-Za-z_][A-Za-z0-9_]*)'\s*=>/g, ' @ ')
    : t.replace(/([,{]\s*)([A-Za-z_][A-Za-z0-9_]*)(\s*:)/g, '$1 @ $3');
const tokenStream = (t) =>
    (t.match(/'[^']*'|"[^"]*"|-?\d+\.?\d*(?:e[+-]?\d+)?|\btrue\b|\bfalse\b|\bnull\b/gi) || [])
        .map(tok => {
            const n = parseFloat(tok);
            return /^[-\d]/.test(tok) && !Number.isNaN(n) ? String(n) : tok;
        });
const phpTokens = tokenStream(stripKeys(phpLit.structure, true));
const jsTokens = tokenStream(stripKeys(jsLit.structure.replace(/\/\/[^\n]*/g, ''), false));
ok('structure payload matches the harness token-for-token',
    JSON.stringify(phpTokens) === JSON.stringify(jsTokens),
    `drift: php ${phpTokens.length} tokens vs js ${jsTokens.length}`);

const idsFromPhp = [...phpLit.structure.matchAll(/'id'\s*=>\s*'([\w-]+)'/g)].map(m => m[1]).sort();
const idsFromJs = [...jsLit.structure.replace(/\/\/[^\n]*/g, '').matchAll(/\bid\s*:\s*'([\w-]+)'/g)].map(m => m[1]).sort();
ok('descriptor id sets identical', JSON.stringify(idsFromPhp) === JSON.stringify(idsFromJs));
ok('56 descriptors ship (trim + wide double door + curtain + furniture + per-room roses)',
    idsFromPhp.length === 56, `found ${idsFromPhp.length}`);

// ── C. Divider placement invariants (REAL module, full capacity sweep) ────
section('C. Divider placement invariants (shared pure math, counts 5→30)');
const { resolveSquareHang, resolveDividerHang, DENSITY_PRESETS } = await import(pathToFileURL(rel('resources/js/gallery/PlacementCuration.js')));
const Placer = await import(pathToFileURL(rel('resources/js/gallery/ArtworkPlacer.js')).href);

const placement = {
    density: 'intimate', pair_orientation: true, focal_wall: 'front',
    wall_length_cap: 12.6, salon_rows: 2, upper_row_y: 2.98,
    room_divider: { at: 0.5, opening: 2.4, keep: 0.55, door_keep: 1.15, spacing: 2.4 },
    row_caps: [{ maxWidth: 2.0, maxHeight: 1.45 }, { maxWidth: 1.7, maxHeight: 0.84 }],
};
const MINW = 8;
const eyeY = 1.6, upperY = 2.98;
const FRAME = 0.09;   // classic frame border (matches the placer's geometry)

const widthsFor = (n, seed) => {
    const out = [];
    for (let i = 0; i < n; i++) {
        const r = Math.sin(seed + i * 2.399963) * 0.5 + 0.5;
        const portrait = (i % 3 === 0);
        out.push(portrait ? 0.66 + r * 0.3 : 1.3 + r * 1.1);
    }
    return out;
};

let sweepOk = true;
const sweepLog = [];
const roomSummary = {};
for (let count = 5; count <= 30; count++) {
    const plan = resolveDividerHang(placement, count, DENSITY_PRESETS.intimate, MINW);
    if (!plan) { sweepOk = false; sweepLog.push(`count ${count}: divider plan is null`); continue; }
    const L = plan.wallLength;

    // (1) domestic room, nothing unplaced
    if (L < MINW - 1e-9 || L > 12.6 + 1e-9) { sweepOk = false; sweepLog.push(`count ${count}: room ${L}m outside [8, 12.6]`); }
    if (plan.unplaced !== 0) { sweepOk = false; sweepLog.push(`count ${count}: ${plan.unplaced} works unplaced`); }

    // (2) both rooms hang works; the hero line is odd
    const roomOf = (seg) => (seg.endsWith('-a') ? 'a' : 'b');
    const perRoom = { a: 0, b: 0 };
    let frontRow0 = null;
    for (const ln of plan.lines) {
        perRoom[roomOf(ln.seg)] += ln.count;
        if (ln.seg === 'front' && ln.row === 0) frontRow0 = ln.count;
    }
    if (perRoom.a < 1) { sweepOk = false; sweepLog.push(`count ${count}: room A (arrival) empty`); }
    if (perRoom.b < 1) { sweepOk = false; sweepLog.push(`count ${count}: room B (hero) empty`); }
    if (frontRow0 === null || frontRow0 % 2 !== 1) { sweepOk = false; sweepLog.push(`count ${count}: hero line ${frontRow0} not odd`); }
    roomSummary[count] = { L, a: perRoom.a, b: perRoom.b, hero: frontRow0 };

    // (3) geometry: slots mirror the placer's math exactly, then assert
    //     clearances against the REAL architecture (curtain sweep, doorcase)
    const widths = widthsFor(count, count);
    const atZ = plan.atZ;
    let idx = 0;
    for (const ln of plan.lines) {
        const seg = plan.segs[ln.seg];
        if (!seg || seg.len <= 0) { sweepOk = false; sweepLog.push(`count ${count}: line on empty segment ${ln.seg}`); continue; }
        const y = ln.row === 0 ? eyeY : upperY;
        const cap = ln.row === 0 ? placement.row_caps[0] : placement.row_caps[1];
        const spans = [];
        for (let p = 0; p < ln.count; p++) {
            const aspect = widths[idx % widths.length];
            let h = cap.maxHeight, wd = h * aspect;
            if (wd > cap.maxWidth) { wd = cap.maxWidth; h = wd / aspect; }
            // slot = wallRunOffset − seg.len/2 + seg.center (the placer's math)
            const off = Placer.wallRunOffset(ln.count, p, plan.spacing, seg.len) - seg.len / 2;
            const t = seg.center + off;
            const half = wd / 2 + FRAME;
            spans.push({ t, half, h });
            // segment containment (corner + curtain/door keeps)
            if (t - half < seg.a - 1e-9 || t + half > seg.b + 1e-9) {
                sweepOk = false;
                sweepLog.push(`count ${count}: frame leaves segment ${ln.seg} [${t.toFixed(2)}±${half.toFixed(2)} in ${seg.a.toFixed(2)},${seg.b.toFixed(2)}]`);
            }
            // curtain fabric sweep clear (side segments): the fabric sweeps
            // amp·env ≤ 0.16 from the curtain plane; frames stay ≥ keep−0.25
            if (seg.wall === 'left' || seg.wall === 'right') {
                const dPlane = Math.min(Math.abs((t - half) - atZ), Math.abs((t + half) - atZ));
                if (dPlane < 0.3) { sweepOk = false; sweepLog.push(`count ${count}: frame approaches the curtain (${dPlane.toFixed(2)} m)`); }
            }
            // doorcase clear (back segments): the assembly spans ±1.03 proud
            if (seg.wall === 'back') {
                const inner = Math.min(Math.abs(t - half), Math.abs(t + half));
                if (inner < 1.03 + 1e-9) { sweepOk = false; sweepLog.push(`count ${count}: frame reaches the doorcase (${inner.toFixed(2)} m)`); }
            }
            // vertical band discipline
            const top = y + h / 2 + 0.05, bot = y - h / 2 - 0.05;
            if (ln.row === 1 && (bot < eyeY + 1.45 / 2 + 0.05 - 0.001)) { sweepOk = false; sweepLog.push(`count ${count}: rows touch`); }
            if (ln.row === 1 && top > 3.485 + 1e-9) { sweepOk = false; sweepLog.push(`count ${count}: upper frame hits the rail`); }
            idx++;
        }
        spans.sort((a, b) => a.t - b.t);
        for (let i2 = 1; i2 < spans.length; i2++) {
            if (spans[i2 - 1].t + spans[i2 - 1].half > spans[i2].t - spans[i2].half + 1e-9) {
                sweepOk = false; sweepLog.push(`count ${count}: frames intersect on ${ln.seg}`);
            }
        }
    }
}
ok('capacity sweep 5→30: both rooms hang, hero centred, frames clear fabric + doorcase, no intersections, nothing unplaced',
    sweepOk, sweepLog.slice(0, 4).join(' | '));
console.log(`  · rooms: ${Object.entries(roomSummary).map(([c, r]) => `${c}→L${r.L.toFixed(1)} A${r.a}/B${r.b} hero${r.hero}`).join('  ')}`);

// determinism: same inputs → identical plan
const d1 = JSON.stringify(resolveDividerHang(placement, 24, DENSITY_PRESETS.intimate, MINW));
const d2 = JSON.stringify(resolveDividerHang(placement, 24, DENSITY_PRESETS.intimate, MINW));
ok('divider plan is deterministic', d1 === d2);

// ── D. Legacy parity ───────────────────────────────────────────────────────
section('D. Legacy parity (venues without the keys are untouched)');
const legacy = resolveSquareHang(undefined, 12, 4, 3.5, 8);
ok('no keys → single line', legacy.rows === 1);
ok('no keys → historic sizing (ceil(12/4)*3.5+3.5 = 14)', Placer.squareLinePlan(12, 3.5, 4, 8, legacy).wallLength === 14);
ok('no keys → no keep-clear', legacy.keep === null);
ok('no room_divider key → divider plan is null (the on-switch is the config)',
    resolveDividerHang(undefined, 24, 3.5, 8) === null
    && resolveDividerHang({ density: 'intimate' }, 24, 3.5, 8) === null);
const salonRowsLegacy = resolveSquareHang({ salon_rows: 2, wall_length_cap: 12.6 }, 24, 4, 2.8, 8);
ok('the v2 rows path still resolves (non-divider venues unaffected)', salonRowsLegacy.rows === 2 && salonRowsLegacy.perLine > 0);

// ── E. Structure↔placement coherence ──────────────────────────────────────
section('E. Structure↔placement coherence (one truth per number)');
{
    const curtainRe = /'id'\s*=>\s*'salon-curtain'[^\n]*/;
    const line = chunk.match(curtainRe)?.[0] || '';
    const openingMatch = /'opening'\s*=>\s*([\d.]+)/.exec(line);
    const ampMatch = /'amplitude'\s*=>\s*([\d.]+)/.exec(line);
    const bracketMatch = /'bracket'\s*=>\s*([\d.]+)/.exec(line);
    const opening = openingMatch ? parseFloat(openingMatch[1]) : 0;
    const amp = ampMatch ? parseFloat(ampMatch[1]) : 0;
    const bracket = bracketMatch ? parseFloat(bracketMatch[1]) : 0;
    ok('the curtain opening equals the plan opening (2.4)',
        opening === placement.room_divider.opening);
    ok('the segment keep (0.55) clears the fabric sweep (amp·env ≤ 0.16) + reveal',
        placement.room_divider.keep >= amp * 1.9 + 0.3,
        `keep ${placement.room_divider.keep} vs sweep ${amp * 1.9}`);
    ok('the door keep (1.15) clears the assembly (jambs ±1.03)',
        placement.room_divider.door_keep >= 1.03);
    ok('the fabric stays inside the room (bracket ≥ wall inset 0.125)',
        bracket >= 0.125);
}

// ── F. JS hygiene ─────────────────────────────────────────────────────────
section('F. JS hygiene (DoD #7 — the DB is the sole identity source)');
const { execSync } = await import('node:child_process');
let slugFree = true;
try {
    const out = execSync(
        `grep -ln "the-salon" ${rel('resources/js/gallery')}/*.js ${rel('resources/js/gallery')}/*/*.js 2>/dev/null | while read f; do sed -e 's-/\\*.*\\*/--g' -e 's-^\\\\s*//.*--' "$f" | grep -l "the-salon" >/dev/null 2>&1 && echo "$f"; done`,
        { shell: '/bin/bash' }
    ).toString().trim();
    slugFree = out === '';
} catch { /* grep exits 1 when nothing matches = good */ }
ok('no venue slug anywhere in the runtime', slugFree);

console.log(failures === 0
    ? '\nALL SALON QA CHECKS PASS\n'
    : `\n${failures} SALON QA CHECK(S) FAILED\n`);
process.exit(failures === 0 ? 0 : 1);
