#!/usr/bin/env node
// scripts/verify-vite-manifest.js
//
// BUILD-TIME GUARD (added 2026-08-31) — run this AFTER `npm run build` and
// BEFORE `php artisan view:cache` (see nixpacks.toml).
//
// WHY THIS EXISTS:
//   Production hit three 500s on 2026-08-31, one of them:
//     "Unable to locate file in Vite manifest: admin-vendor.js"
//     (View: /app/resources/views/admin/galleries/analytics.blade.php)
//   Root cause class: a Blade view references a Vite entry that the built
//   manifest does not contain. When that happens today, the deploy succeeds
//   and the error only appears at runtime as a 500 for end users.
//
// WHAT THIS SCRIPT DOES:
//   1. Scans resources/views/**/*.blade.php for @vite(...) references.
//      - Blade {{-- --}} comments are stripped first (so commented examples
//        are not checked).
//      - Escaped @@vite is ignored (Blade renders it as literal text).
//   2. Loads public/build/manifest.json and verifies:
//      a) every referenced entry exists in the manifest under the EXACT key
//         (manifest keys are paths relative to the project root, e.g.
//         "resources/js/admin-vendor.js" — NOT "admin-vendor.js");
//      b) every manifest entry's output file actually exists on disk.
//   3. Exit 1 with a clear, actionable message on any problem — failing the
//   deploy at build time instead of shipping a 500.
//
// Usage: node scripts/verify-vite-manifest.js [viewsDir] [manifestPath]
// Defaults: resources/views   public/build/manifest.json

import { readFileSync, existsSync, readdirSync, statSync } from 'node:fs';
import { join, relative, sep } from 'node:path';

const ROOT = process.cwd();
const VIEWS_DIR = join(ROOT, process.argv[2] ?? 'resources/views');
const MANIFEST_PATH = join(ROOT, process.argv[3] ?? 'public/build/manifest.json');

let failed = false;
const problems = [];

// ── 1. Collect every @vite(...) reference from all Blade templates ──────────
function listBladeFiles(dir) {
    const out = [];
    for (const name of readdirSync(dir)) {
        const full = join(dir, name);
        const st = statSync(full);
        if (st.isDirectory()) out.push(...listBladeFiles(full));
        else if (name.endsWith('.blade.php')) out.push(full);
    }
    return out;
}

// One level of paren nesting is enough for @vite(['a', 'b']) / @vite('a').
const VITE_RE = /(?<!@)@vite\s*\(((?:[^()]|\([^()]*\))*)\)/g;
const QUOTED_RE = /['"]([^'"]+)['"]/g;

const references = new Map(); // entry -> [files that reference it]

if (!existsSync(VIEWS_DIR)) {
    console.error(`[verify-vite-manifest] Views directory not found: ${relative(ROOT, VIEWS_DIR)}`);
    process.exit(1);
}

for (const file of listBladeFiles(VIEWS_DIR)) {
    const raw = readFileSync(file, 'utf8');
    // Strip Blade comments BEFORE scanning, mirroring BladeCompiler order.
    const src = raw.replace(/{{--[\s\S]*?--}}/g, '');
    for (const m of src.matchAll(VITE_RE)) {
        const callArgs = m[1];
        for (const q of callArgs.matchAll(QUOTED_RE)) {
            const entry = q[1].trim();
            if (!entry) continue;
            if (!references.has(entry)) references.set(entry, []);
            references.get(entry).push(relative(ROOT, file));
        }
    }
}

// ── 2. Load the manifest ────────────────────────────────────────────────────
if (!existsSync(MANIFEST_PATH)) {
    console.error('[verify-vite-manifest] FAILED — manifest not found at ' + relative(ROOT, MANIFEST_PATH));
    console.error('  Did `vite build` run? nixpacks.toml must run "npm run build" before this check.');
    process.exit(1);
}

let manifest;
try {
    manifest = JSON.parse(readFileSync(MANIFEST_PATH, 'utf8'));
} catch (e) {
    console.error('[verify-vite-manifest] FAILED — manifest is not valid JSON: ' + e.message);
    process.exit(1);
}

const keys = new Set(Object.keys(manifest));

// ── 3a. Every @vite reference must exist under its EXACT manifest key ───────
for (const [entry, files] of [...references].sort()) {
    if (keys.has(entry)) continue;

    failed = true;
    problems.push(
        `MISSING manifest entry: "${entry}"\n` +
        `    referenced by: ${[...new Set(files)].join(', ')}\n` +
        `    manifest keys are paths relative to the project root. If this entry\n` +
        `    exists under a different key (e.g. "${entry.startsWith('resources/') ? entry.replace(/^resources\//, '') : 'resources/' + entry}"),\n` +
        `    the VIEW is using the wrong form and must use the full path.\n` +
        `    If it does not exist at all, add it to the "input" array in vite.config.js.`
    );

    // Extra hint for the exact failure class we saw in production:
    if (!entry.startsWith('resources/') && keys.has('resources/' + entry)) {
        problems.push(
            `    DIAGNOSIS for "${entry}": the manifest has it as "resources/${entry}".\n` +
            `    A view is calling @vite('${entry}') with a bare name — change it to\n` +
            `    @vite(['resources/${entry}']).`
        );
    }
}

// ── 3b. Every manifest entry's output file must exist on disk ───────────────
for (const [key, chunk] of Object.entries(manifest)) {
    const out = join(ROOT, 'public', 'build', chunk.file ?? '');
    if (chunk.file && !existsSync(out)) {
        failed = true;
        problems.push(`MANIFEST file missing on disk: "${chunk.file}" (entry "${key}")`);
    }
    for (const css of chunk.css ?? []) {
        if (!existsSync(join(ROOT, 'public', 'build', css))) {
            failed = true;
            problems.push(`MANIFEST css missing on disk: "${css}" (entry "${key}")`);
        }
    }
}

// ── 4. Report ───────────────────────────────────────────────────────────────
console.log(`[verify-vite-manifest] checked ${references.size} @vite reference(s) across all Blade templates`);
console.log(`[verify-vite-manifest] checked ${keys.size} manifest entr(ies)`);

if (failed) {
    console.error('\n[verify-vite-manifest] FAILED — refusing to ship a build that would 500 at runtime:\n');
    for (const p of problems) console.error(p + '\n');
    process.exit(1);
}

console.log('[verify-vite-manifest] OK — every @vite reference resolves to a built asset.');
