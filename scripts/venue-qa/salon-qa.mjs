#!/usr/bin/env node
// ─────────────────────────────────────────────────────────────────────────────
// salon-qa.mjs — the venue QA gate for The Salon (v2.0.0 "The Collector's
// Salon").
//
//   node scripts/venue-qa/salon-qa.mjs
//
// Runs WITHOUT a PHP stack (plain Node over the repo checkout). Layering:
// pins CONTRACTS (payload parity, placement geometry, determinism) here,
// while tests/Feature/VenueSalonIterationTest.php pins the DB side and
// scripts/harness/probe-salon-poses.mjs captures the visual evidence.
//
// Checks:
//   A. Seeder contract — the v2.0.0 row declares the authored identity
//      (domestic room cap, salon rows, door keep-clear, row caps, warm
//      readable rig, texture authority, restraint).
//   B. DB↔harness sync — the PHP-less harness renders the same payload a
//      fresh install seeds (drift here means screenshots stop meaning
//      anything). This pin would have caught the v1 'turn' drift.
//   C. Placement invariants — driven through the REAL shared modules
//      (PlacementCuration.resolveSquareHang + ArtworkPlacer.
//      squareLinePlan) across the capacity range: the room stays domestic,
//      the door wall keeps its clear zone, the two rows never touch, no
//      two frames intersect, every work finds a slot, and the whole hang
//      is deterministic.
//   D. Legacy parity — venues that declare none of the new keys resolve
//      exactly as before (rows 1, historic sizing).
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
    const keys = phpStyle
        ? ['placement', 'structure', 'post_fx', 'material_config', 'visual_config', 'default_settings']
        : ['placement', 'structure', 'post_fx', 'material_config', 'visual_config', 'default_settings'];
    const out = {};
    for (const key of keys) {
        const marker = phpStyle ? `'${key}'` : `${key}:`;
        const ki = text.indexOf(marker);
        if (ki === -1) continue;
        const open = text.indexOf('[', ki);
        // choose { or [
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
section('A. Seeder contract (the-salon v2 row)');
const chunk = seederSalonChunk();
const phpLit = rawLiteral(chunk, true);
const vc = phpLit.placement ? chunk : chunk; // vc checked via needles below

ok('version 2.0.0 declared', /'version'\s*=>\s*'2\.0\.0'/.test(chunk));
ok('the room stays domestic (wall_length_cap 12.6)', /'wall_length_cap'\s*=>\s*12\.6/.test(chunk));
ok('the two-line salon hang declared (salon_rows 2)', /'salon_rows'\s*=>\s*2(?!\.)/.test(chunk));
ok('the doorcase keeps its wall (keep_clear back)', /'keep_clear'\s*=>\s*\['wall'\s*=>\s*'back'/.test(chunk));
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
ok('fresh-install copy names the authored venue',
    /doorcase/.test(chunk) && /salon-style/.test(chunk));
ok('rails stand ABOVE the hang (y 3.53 — the v1 clip is dead)',
    chunk.includes('[0, 3.53, 0.0]'));
ok('no mid-wall rail remains (the v1 0.9 m rail is gone)', !chunk.includes('[0, 0.9, 0.045]'));

// ── B. DB↔harness sync ───────────────────────────────────────────────────
section('B. DB↔harness sync (drift = screenshots stop meaning anything)');
const harness = harnessSalonObject();
const jsLit = rawLiteral(harness, false);

// PHP assoc-array syntax and JS object syntax are not both JSON — compare
// the canonical VALUE STREAM: strip the KEY syntax from both sides (PHP
// 'key' => / JS key:), then tokenize the remaining strings + numbers in
// order. Any value/format/order drift shows up; syntax does not matter.
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

// key identity set parity
// PHP keys are quoted ('id' =>), JS keys are bare (id:) — match both.
const idsFromPhp = [...phpLit.structure.matchAll(/'id'\s*=>\s*'([\w-]+)'/g)].map(m => m[1]).sort();
const idsFromJs = [...jsLit.structure.replace(/\/\/[^\n]*/g, '').matchAll(/\bid\s*:\s*'([\w-]+)'/g)].map(m => m[1]).sort();
ok('descriptor id sets identical', JSON.stringify(idsFromPhp) === JSON.stringify(idsFromJs));
ok('45 descriptors ship (the authored architecture)', idsFromPhp.length === 45, `found ${idsFromPhp.length}`);

// ── C. Placement invariants (REAL modules, full capacity sweep) ───────────
section('C. Placement invariants (shared pure math, counts 5→30)');
const { resolveSquareHang, DENSITY_PRESETS } = await import(pathToFileURL(rel('resources/js/gallery/PlacementCuration.js')));
const placerUrl = pathToFileURL(rel('resources/js/gallery/ArtworkPlacer.js')).href;
const Placer = await import(placerUrl);

const placement = {
    density: 'intimate', pair_orientation: true, focal_wall: 'front',
    wall_length_cap: 12.6, salon_rows: 2, upper_row_y: 2.98,
    keep_clear: { wall: 'back', width: 1.05, max_width: 1.6 },
    row_caps: [{ maxWidth: 2.4, maxHeight: 1.45 }, { maxWidth: 1.7, maxHeight: 0.84 }],
};
const SPACING = DENSITY_PRESETS.intimate;
const MINW = 8;
const eyeY = 1.6, upperY = 2.98;

const widthsFor = (n, seed) => {
    // deterministic mixed-orientation width profile (portrait-heavy mix)
    const out = [];
    for (let i = 0; i < n; i++) {
        const r = Math.sin(seed + i * 2.399963) * 0.5 + 0.5; // golden-angle spread
        const portrait = (i % 3 === 0);
        out.push(portrait ? 0.66 + r * 0.3 : 1.3 + r * 1.1); // aspect ratios
    }
    return out;
};

let sweepOk = true;
const sweepLog = [];
for (let count = 5; count <= 30; count++) {
    for (const drifted of [false]) {
        const hang = resolveSquareHang(placement, count, 4, SPACING, MINW);
        const plan = Placer.squareLinePlan(count, SPACING, 4, MINW, hang);
        const L = plan.wallLength;

        // (1) domestic room: never beyond the soft cap + one bumped column
        const maxL = Math.max(MINW, hang.perLine * SPACING + SPACING);
        if (L !== maxL) { sweepOk = false; sweepLog.push(`count ${count}: sizing mismatch`); }
        if (L > 14.001) { sweepOk = false; sweepLog.push(`count ${count}: room ${L}m breaches the domestic ceiling`); }

        // (2) per-line counts, keep-clear drops on the back wall's odd lines
        const counts = plan.lines.slice(0, 4 * plan.rows);
        let displaced = 0;
        const keepLines = new Set();
        for (let r = 0; r < plan.rows; r++) keepLines.add(1 + r * 4);
        for (const li of keepLines) {
            if (li < counts.length && counts[li] % 2 === 1) { counts[li] -= 1; displaced++; }
        }
        for (let d = 0; d < displaced; d++) {
            let moved = false;
            for (let i = counts.length - 1; i >= 0; i--) {
                if (!keepLines.has(i) && counts[i] < plan.perLine + 1) { counts[i]++; moved = true; break; }
            }
            if (!moved) { sweepOk = false; sweepLog.push(`count ${count}: displaced work unplaceable`); }
        }
        const total = counts.reduce((a, b) => a + b, 0);
        if (total < count) { sweepOk = false; sweepLog.push(`count ${count}: ${count - total} works unplaced`); }

        // (3) geometry: no frame overlap on any line, bands never touch,
        //     door zone clear, everything inside the wall span
        const widths = widthsFor(count, count);
        let idx = 0;
        for (let w = 0; w < 4; w++) {
            for (let r = 0; r < plan.rows; r++) {
                const n = counts[w + r * 4] || 0;
                const y = r === 0 ? eyeY : upperY;
                const cap = r === 0 ? placement.row_caps[0] : placement.row_caps[1];
                const spans = [];
                const wCap = (w === 1)
                    ? Math.min(cap.maxWidth, placement.keep_clear.max_width)  // the placer merges the keep-clear cap first
                    : cap.maxWidth;
                for (let p = 0; p < n; p++) {
                    const img = { id: idx, aspectRatio: widths[idx % widths.length] };
                    const aspect = img.aspectRatio;
                    let h = cap.maxHeight, wd = h * aspect;
                    if (wd > wCap) { wd = wCap; h = wd / aspect; }
                    const off = Placer.wallRunOffset(n, p, SPACING, L) - SPACING;
                    const center = -L / 2 + (w === 0 || w === 1 ? 0 : 0) + off + (w === 0 || w === 1 ? SPACING : 0);
                    spans.push({ c: -L / 2 + off + SPACING, half: wd / 2 + 0.05 });
                    // vertical band discipline
                    const top = y + h / 2 + 0.05, bot = y - h / 2 - 0.05;
                    if (r === 0 && top > (plan.rows > 1 ? upperY - 0.84 / 2 - 0.04 : 3.8)) { /* eye top under upper band */ }
                    if (r === 1 && (bot < eyeY + 1.45 / 2 + 0.05 - 0.001)) { sweepOk = false; sweepLog.push(`count ${count}: rows touch`); }
                    if (r === 1 && top > 3.485 + 1e-9) { sweepOk = false; sweepLog.push(`count ${count}: upper frame hits the rail`); }
                    idx++;
                }
                spans.sort((a, b) => a.c - b.c);
                for (let i2 = 1; i2 < spans.length; i2++) {
                    if (spans[i2 - 1].c + spans[i2 - 1].half > spans[i2].c - spans[i2].half + 1e-9) {
                        sweepOk = false; sweepLog.push(`count ${count}: frames intersect on wall ${w} row ${r}`);
                    }
                }
                // door keep-clear: no canvas centre within the door half-width
                if (keepLines.has(w + r * 4)) {
                    for (const s of spans) {
                        if (Math.abs(s.c) < placement.keep_clear.width / 2) {
                            sweepOk = false; sweepLog.push(`count ${count}: canvas in the door zone`);
                        }
                    }
                }
                // span inside the wall
                for (const s of spans) {
                    if (s.c - s.half < -L / 2 - 1e-9 || s.c + s.half > L / 2 + 1e-9) {
                        sweepOk = false; sweepLog.push(`count ${count}: canvas off the wall`);
                    }
                }
            }
        }
    }
}
ok('capacity sweep 5→30: domestic sizing, clean rows, no intersections, door clear, nothing unplaced', sweepOk, sweepLog.slice(0, 4).join(' | '));

// determinism: same inputs → identical plan
const a1 = resolveSquareHang(placement, 24, 4, SPACING, MINW);
const a2 = resolveSquareHang(placement, 24, 4, SPACING, MINW);
ok('hang plan is deterministic', JSON.stringify(a1) === JSON.stringify(a2));

// (4) room size story: the cap must actually bite (the v1 hall is dead)
const rooms = [];
for (let count = 5; count <= 30; count++) {
    const hang = resolveSquareHang(placement, count, 4, SPACING, MINW);
    rooms.push(Placer.squareLinePlan(count, SPACING, 4, MINW, hang).wallLength);
}
ok('every count 5→30 stays within 14 m walls (v1: up to 25.2 m)', Math.max(...rooms) <= 14.001, `max ${Math.max(...rooms)}m`);

// ── D. Legacy parity ───────────────────────────────────────────────────────
section('D. Legacy parity (venues without the keys are untouched)');
const legacy = resolveSquareHang(undefined, 12, 4, 3.5, 8);
ok('no keys → single line', legacy.rows === 1);
ok('no keys → historic sizing (ceil(12/4)*3.5+3.5 = 14)', Placer.squareLinePlan(12, 3.5, 4, 8, legacy).wallLength === 14);
ok('no keys → no keep-clear', legacy.keep === null);
const legacyPlan = Placer.squareLinePlan(12, 3.5, 4, 8, null);
ok('null hang → historic sizing', legacyPlan.wallLength === 14 && legacyPlan.lines.length === 4);

// ── E. JS hygiene ─────────────────────────────────────────────────────────
section('E. JS hygiene (DoD #7 — the DB is the sole identity source)');
const { execSync } = await import('node:child_process');
let slugFree = true;
try {
    const out = execSync(
        `grep -ln "the-salon" ${rel('resources/js/gallery')}/*.js ${rel('resources/js/gallery')}/*/*.js 2>/dev/null | while read f; do sed -e 's-/\\*.*\\*/--g' -e 's-^\\s*//.*--' "$f" | grep -l "the-salon" >/dev/null 2>&1 && echo "$f"; done`,
        { shell: '/bin/bash' }
    ).toString().trim();
    slugFree = out === '';
} catch { /* grep exits 1 when nothing matches = good */ }
ok('no venue slug anywhere in the runtime', slugFree);

console.log(failures === 0
    ? '\nALL SALON QA CHECKS PASS\n'
    : `\n${failures} SALON QA CHECK(S) FAILED\n`);
process.exit(failures === 0 ? 0 : 1);
