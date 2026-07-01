#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────────────────
# download-cc0-assets.sh — fetches CC0 textures + HDRIs from ambientCG + PolyHaven
#
# All assets are CC0 (public domain) — no attribution required, no API keys,
# no rate limits worth worrying about. Total download: ~150 MB.
#
# Usage:
#   bash scripts/download-cc0-assets.sh
#
# What this script downloads:
#   - Wall PBR sets:   white plaster, concrete, brick, wood
#   - Floor PBR sets:  wood, marble, concrete, grass, terrazzo
#   - HDRIs:           studio, rural evening, night
#   - Misc:            canvas normal map (artwork surface texture)
#
# After download:
#   - Run `bash scripts/copy-decoders.sh` to set up DRACO/KTX2 decoders
#   - Run `php artisan preflight:assets` to verify
#   - Run `npm run optimize-glbs` if you have any GLBs to compress
# ─────────────────────────────────────────────────────────────────────────────
set -euo pipefail

TEXTURES_DIR="public/assets/textures"
ENV_DIR="$TEXTURES_DIR/env"
SHARED_DIR="$TEXTURES_DIR/shared"

mkdir -p "$ENV_DIR" "$SHARED_DIR"

# Helper: download + unzip an ambientCG PBR set into a target directory
# Usage: download_ambientcg <asset_id> <target_dir>
# ambientCG zip contains: <id>_<map>_<resolution>.jpg — we rename to color/normal/roughness/ao.
download_ambientcg() {
    local ID="$1"
    local TARGET="$2"
    mkdir -p "$TARGET"

    local URL="https://ambientcg.com/get?file=${ID}_1K-JPG.zip"
    local TMP="/tmp/ambientcg_${ID}.zip"

    echo "  ↓ $ID → $TARGET"
    if ! curl -L --fail --silent --show-error -o "$TMP" "$URL"; then
        echo "    ⚠ Failed to download $ID — skipping"
        return 0
    fi

    local UNZIP_DIR="/tmp/ambientcg_${ID}"
    rm -rf "$UNZIP_DIR"
    mkdir -p "$UNZIP_DIR"
    if ! unzip -q -o "$TMP" -d "$UNZIP_DIR"; then
        echo "    ⚠ Failed to unzip $ID — skipping"
        return 0
    fi

    # ambientCG's internal zip filenames have changed format over time and vary
    # by asset (e.g. "ID_Color_1K.jpg" vs "ID 1K Color.jpg" vs "ID 1K-JPG Color.jpg").
    # Rather than guess the exact separator/order, match by substring anywhere
    # in the filename — this is robust to whatever format a given asset uses.
    # Prefer NormalGL over NormalDX (GL = correct convention for Three.js/WebGL).
    local COLOR=$(find "$UNZIP_DIR" -type f -iname "*color*" ! -iname "*preview*" | head -1)
    local NORMAL=$(find "$UNZIP_DIR" -type f -iname "*normalgl*" | head -1)
    if [[ -z "$NORMAL" ]]; then
        NORMAL=$(find "$UNZIP_DIR" -type f -iname "*normal*" ! -iname "*preview*" | head -1)
    fi
    local ROUGH=$(find "$UNZIP_DIR" -type f -iname "*roughness*" | head -1)
    local AO=$(find "$UNZIP_DIR" -type f -iname "*ambientocclusion*" | head -1)

    [[ -n "$COLOR" ]]  && cp "$COLOR"  "$TARGET/color.jpg"     || echo "    ⚠ No color map in $ID"
    [[ -n "$NORMAL" ]] && cp "$NORMAL" "$TARGET/normal.jpg"    || echo "    ⚠ No normal map in $ID"
    [[ -n "$ROUGH" ]]  && cp "$ROUGH"  "$TARGET/roughness.jpg" || echo "    ⚠ No roughness map in $ID"
    [[ -n "$AO" ]]     && cp "$AO"     "$TARGET/ao.jpg"        || echo "    ℹ No AO map available for $ID (optional — not all assets ship one)"

    rm -rf "$UNZIP_DIR" "$TMP"
}

# ── Wall materials ────────────────────────────────────────────────────────────
echo "─── Wall materials ──────────────────────────────────────────────"
mkdir -p "$TEXTURES_DIR/walls"
download_ambientcg "Plaster001"  "$TEXTURES_DIR/walls/white"
download_ambientcg "Concrete033" "$TEXTURES_DIR/walls/concrete"
download_ambientcg "Bricks090"   "$TEXTURES_DIR/walls/brick"
download_ambientcg "Wood021"     "$TEXTURES_DIR/walls/wood"
download_ambientcg "Plaster003"  "$TEXTURES_DIR/walls/plaster"
download_ambientcg "Marble013"   "$TEXTURES_DIR/walls/marble"
download_ambientcg "Fabric030"   "$TEXTURES_DIR/walls/velvet"

# ── Floor materials ───────────────────────────────────────────────────────────
echo "─── Floor materials ─────────────────────────────────────────────"
mkdir -p "$TEXTURES_DIR/floors"
download_ambientcg "Wood025"     "$TEXTURES_DIR/floors/wood"
download_ambientcg "Marble013"   "$TEXTURES_DIR/floors/marble"
download_ambientcg "Concrete033" "$TEXTURES_DIR/floors/concrete"
download_ambientcg "Terrazzo008" "$TEXTURES_DIR/floors/terrazzo"
download_ambientcg "Grass003"    "$TEXTURES_DIR/floors/grass"
download_ambientcg "Ground055S"  "$TEXTURES_DIR/floors/sand"

# Water is procedural in Three.js — skip the texture, set a blue colour
mkdir -p "$TEXTURES_DIR/floors/water"
# Create a tiny solid-color placeholder so Materials.js's preload doesn't 404
echo -n "" > "$TEXTURES_DIR/floors/water/.gitkeep"

# ── Ceiling materials ─────────────────────────────────────────────────────────
echo "─── Ceiling materials ───────────────────────────────────────────"
mkdir -p "$TEXTURES_DIR/ceilings"
download_ambientcg "Plaster001"  "$TEXTURES_DIR/ceilings/flat"
download_ambientcg "Wood021"     "$TEXTURES_DIR/ceilings/beamed"

# ── HDRIs (from PolyHaven) ────────────────────────────────────────────────────
echo "─── HDRIs (PolyHaven) ──────────────────────────────────────────"
download_hdri() {
    local NAME="$1"
    local URL="https://dl.polyhaven.org/file/ph-assets/HDRIs/hdr/1k/$NAME"
    local OUT="$ENV_DIR/$NAME"
    echo "  ↓ $NAME → $OUT"
    curl -L --fail --silent --show-error -o "$OUT" "$URL" || echo "    ⚠ Failed — skipping"
}
download_hdri "studio_small_08_1k.hdr"
download_hdri "rural_landscape_1k.hdr"
download_hdri "qwantani_night_puresky_1k.hdr"
# Symlink the names the viewer expects
[[ -f "$ENV_DIR/studio_small_08_1k.hdr" ]] && ln -sf "studio_small_08_1k.hdr" "$ENV_DIR/studio.hdr"
[[ -f "$ENV_DIR/rural_landscape_1k.hdr" ]] && ln -sf "rural_landscape_1k.hdr" "$ENV_DIR/rural_evening.hdr"
[[ -f "$ENV_DIR/qwantani_night_puresky_1k.hdr" ]] && ln -sf "qwantani_night_puresky_1k.hdr" "$ENV_DIR/night.hdr"

# ── Canvas normal map (artwork surface texture) ────────────────────────────────
echo "─── Shared ──────────────────────────────────────────────────────"
# Use ambientCG's Fabric030 as a stand-in for canvas texture — looks great as
# a normal map on paintings.
if [[ ! -f "$SHARED_DIR/canvas_normal.jpg" ]]; then
    if [[ -f "$TEXTURES_DIR/walls/velvet/normal.jpg" ]]; then
        cp "$TEXTURES_DIR/walls/velvet/normal.jpg" "$SHARED_DIR/canvas_normal.jpg"
        echo "  ✓ canvas_normal.jpg (from Fabric030)"
    fi
fi

echo
echo "────────────────────────────────────────────────────────────────"
echo "Verifying downloaded texture sets..."
echo
INCOMPLETE=0
for dir in "$TEXTURES_DIR"/walls/* "$TEXTURES_DIR"/floors/* "$TEXTURES_DIR"/ceilings/*; do
    [[ -d "$dir" ]] || continue
    name=$(basename "$dir")
    [[ "$name" == "water" ]] && continue  # procedural, intentionally has no texture files
    missing=""
    for map in color normal roughness; do
        [[ -f "$dir/$map.jpg" ]] || missing="$missing $map"
    done
    if [[ -n "$missing" ]]; then
        echo "  ⚠ $name — missing:$missing"
        INCOMPLETE=1
    elif [[ ! -f "$dir/ao.jpg" ]]; then
        echo "  ✓ $name — complete (no AO map, optional)"
    else
        echo "  ✓ $name — complete"
    fi
done
echo
if [[ "$INCOMPLETE" -eq 1 ]]; then
    echo "Some sets are incomplete — see warnings above. The gallery will still"
    echo "run (Materials.js falls back to a flat colour for missing maps), but"
    echo "for a full fix, grab that asset manually from ambientcg.com and drop"
    echo "the 4 renamed files (color/normal/roughness/ao.jpg) into its folder."
else
    echo "✅ All texture sets complete."
fi
echo "────────────────────────────────────────────────────────────────"
echo
echo "Next steps:"
echo "  1. bash scripts/copy-decoders.sh    # DRACO + KTX2 wasm decoders"
echo "  2. php artisan preflight:assets     # verify everything's in place"
echo "  3. npm run build                    # rebuild the JS bundle"
echo "────────────────────────────────────────────────────────────────"