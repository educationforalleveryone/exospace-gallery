#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────────────────
# copy-decoders.sh — copy DRACO + KTX2 (Basis) decoder wasm files from
# node_modules/three/examples/jsm/libs/ to public/decoders/.
#
# Three.js's DRACOLoader and KTX2Loader need these wasm files at runtime.
# The default path is /decoders/draco/ and /decoders/basis/ — set in
# AssetLoader.js.
#
# Run this once after `npm install` (or any time you upgrade three).
# ─────────────────────────────────────────────────────────────────────────────
set -euo pipefail

THREE_LIBS="node_modules/three/examples/jsm/libs"
OUT_DIR="public/decoders"

if [[ ! -d "$THREE_LIBS" ]]; then
    echo "❌ three not installed. Run: npm install"
    exit 1
fi

mkdir -p "$OUT_DIR/draco" "$OUT_DIR/basis"

# ── DRACO ────────────────────────────────────────────────────────────────────
echo "Copying DRACO decoders..."
if [[ -d "$THREE_LIBS/draco" ]]; then
    # We use the JS decoder (works everywhere, no native code)
    cp -r "$THREE_LIBS/draco/"*.js "$OUT_DIR/draco/" 2>/dev/null || true
    cp -r "$THREE_LIBS/draco/"*.wasm "$OUT_DIR/draco/" 2>/dev/null || true
    # The gltf-transform pipeline uses the gltf subfolder
    mkdir -p "$OUT_DIR/draco/gltf"
    cp -r "$THREE_LIBS/draco/gltf/"* "$OUT_DIR/draco/gltf/" 2>/dev/null || true
    echo "  ✓ DRACO decoders copied to $OUT_DIR/draco/"
else
    echo "  ⚠ DRACO source not found at $THREE_LIBS/draco"
fi

# ── KTX2 / Basis ──────────────────────────────────────────────────────────────
echo "Copying KTX2 (Basis) transcoders..."
if [[ -d "$THREE_LIBS/basis" ]]; then
    cp -r "$THREE_LIBS/basis/"* "$OUT_DIR/basis/" 2>/dev/null || true
    echo "  ✓ KTX2 transcoders copied to $OUT_DIR/basis/"
else
    echo "  ⚠ Basis source not found at $THREE_LIBS/basis"
fi

echo
echo "✅ Decoders in place."
echo
echo "Verify with:"
echo "  ls -la $OUT_DIR/draco/"
echo "  ls -la $OUT_DIR/basis/"
