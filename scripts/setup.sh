#!/usr/bin/env bash
set -euo pipefail

QUICK=""
if [[ "${1:-}" == "--quick" ]]; then
    QUICK="1"
fi

echo "╔══════════════════════════════════════════════════════════════╗"
echo "║        Exospace Refactor — First-Run Setup                  ║"
echo "╚══════════════════════════════════════════════════════════════╝"
echo

echo "─── Step 1/7: npm install ──────────────────────────────────────"
if ! command -v npm &> /dev/null; then
    echo "❌ npm not found. Install Node.js 18+ first."
    exit 1
fi
npm install
echo "✅ npm install complete"
echo

echo "─── Step 2/7: Copy DRACO + KTX2 decoders ───────────────────────"
bash scripts/copy-decoders.sh
echo

echo "─── Step 3/7: Generate placeholder textures ────────────────────"
php scripts/generate-placeholder-assets.php
echo

# ── 4. Download CC0 assets (optional in --quick mode) ────────────────────────
if [[ -z "$QUICK" ]]; then
    echo "─── Step 4/7: Download CC0 textures + HDRIs (~150 MB) ──────────"
    bash scripts/download-cc0-assets.sh
else
    echo "─── Step 4/7: Skipping CC0 download (--quick mode) ────────────"
    echo "    (Placeholder textures from step 3 will be used)"
fi
echo

echo "─── Step 5/7: Run migrations + seed venue templates ────────────"
php artisan migrate --force
php artisan db:seed --class=VenueTemplateSeeder --force
echo "✅ Migrations + seed complete"
echo

echo "─── Step 6/7: Storage link + preflight:assets ──────────────────"
php artisan storage:link --force
php artisan preflight:assets || echo "    (Some warnings are OK — placeholder assets are minimal)"
echo

echo "─── Step 7/7: Build the JS bundle ──────────────────────────────"
npm run build
echo

echo "╔══════════════════════════════════════════════════════════════╗"
echo "║  ✅ Setup complete!                                          ║"
echo "╚══════════════════════════════════════════════════════════════╝"
echo
echo "Next steps:"
echo "  • Start the dev server:   npm run dev   (or: composer dev)"
echo "  • Visit a gallery:        http://localhost:8000/gallery/{slug}"
echo "  • Add venue thumbnails:   see documentation/ASSET_PIPELINE.md §5"
echo "  • Generate 3D props with your AI tool: see ASSET_PIPELINE.md §11"
echo
