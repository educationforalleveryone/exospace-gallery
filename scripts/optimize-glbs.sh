#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────────────────
# optimize-glbs.sh — DRACO + KTX2 compression for every GLB in public/assets/models/
#
# Why: uncompressed GLBs ship ~5MB each. After DRACO + KTX2, the same GLB
# is ~500KB — 10× smaller, loads 10× faster on mobile, no visual quality loss.
#
# Prereqs (one-time):
#   npm install  (this installs @gltf-transform/cli as a dev dep)
#
# Usage:
#   npm run optimize-glbs
#   bash scripts/optimize-glbs.sh
#   bash scripts/optimize-globs.sh --verbose
# ─────────────────────────────────────────────────────────────────────────────
set -euo pipefail

VERBOSE=""
if [[ "${1:-}" == "--verbose" ]]; then
    VERBOSE="--verbose"
fi

MODELS_DIR="public/assets/models"

if [[ ! -d "$MODELS_DIR" ]]; then
    echo "❌ Models directory not found: $MODELS_DIR"
    echo "   Create it and drop your raw GLBs inside."
    exit 1
fi

if ! command -v npx &> /dev/null; then
    echo "❌ npx not found. Install Node.js first."
    exit 1
fi

echo "🔄 Compressing all GLBs in $MODELS_DIR ..."
echo

# Find every .glb file (skip already-compressed ones ending in -opt.glb)
TOTAL=0
COMPRESSED=0

while IFS= read -r GLB; do
    TOTAL=$((TOTAL + 1))

    DIR=$(dirname "$GLB")
    BASE=$(basename "$GLB" .glb)
    OUT="$DIR/${BASE}-opt.glb"

    # Skip already-optimized files
    if [[ "$BASE" == *-opt ]]; then
        echo "  ⊘ Skip (already optimized): $GLB"
        continue
    fi

    # Skip if -opt.glb already exists and is newer
    if [[ -f "$OUT" && "$OUT" -nt "$GLB" ]]; then
        echo "  ⊘ Skip (output is newer): $GLB"
        continue
    fi

    BEFORE=$(stat -c%s "$GLB" 2>/dev/null || stat -f%z "$GLB")

    echo "  ⚙ Compressing: $GLB"
    npx @gltf-transform/cli optimize "$GLB" "$OUT" \
        --texture-compress ktx2 \
        --geometry-compress draco \
        --weld \
        --prune \
        --simplify \
        $VERBOSE

    AFTER=$(stat -c%s "$OUT" 2>/dev/null || stat -f%z "$OUT")
    RATIO=$(awk "BEGIN { printf \"%.1f\", ($BEFORE - $AFTER) / $BEFORE * 100 }")

    echo "    ✓ $BEFORE → $AFTER bytes (-${RATIO}%)"
    COMPRESSED=$((COMPRESSED + 1))
done < <(find "$MODELS_DIR" -type f -name '*.glb')

echo
echo "────────────────────────────────────────────────────────────────"
echo "✅ Done. Compressed $COMPRESSED of $TOTAL GLBs."
echo
echo "Next steps:"
echo "  1. Update your venue's decorations JSON to point at the *-opt.glb files"
echo "  2. Run: php artisan preflight:assets"
echo "  3. Test in the browser — Three.js will auto-detect DRACO + KTX2"
echo "────────────────────────────────────────────────────────────────"
