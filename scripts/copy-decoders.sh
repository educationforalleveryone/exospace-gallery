#!/usr/bin/env bash
set -euo pipefail

THREE_LIBS="node_modules/three/examples/jsm/libs"
OUT_DIR="public/decoders"

if [[ ! -d "$THREE_LIBS" ]]; then
    echo "❌ three not installed. Run: npm install"
    exit 1
fi

mkdir -p "$OUT_DIR/draco" "$OUT_DIR/basis"

echo "Copying DRACO decoders..."
if [[ -d "$THREE_LIBS/draco" ]]; then
    cp -r "$THREE_LIBS/draco/"*.js "$OUT_DIR/draco/" 2>/dev/null || true
    cp -r "$THREE_LIBS/draco/"*.wasm "$OUT_DIR/draco/" 2>/dev/null || true
    # The gltf-transform pipeline uses the gltf subfolder
    mkdir -p "$OUT_DIR/draco/gltf"
    cp -r "$THREE_LIBS/draco/gltf/"* "$OUT_DIR/draco/gltf/" 2>/dev/null || true
    echo "  ✓ DRACO decoders copied to $OUT_DIR/draco/"
else
    echo "  ⚠ DRACO source not found at $THREE_LIBS/draco"
fi

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
