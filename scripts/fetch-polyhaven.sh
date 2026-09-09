#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────────────────
# fetch-polyhaven.sh — download CC0 Poly Haven vegetation models + repack GLB
#
# Downloads the .gltf scene + all referenced textures for each asset into
# /home/z/my-project/assets-src/<name>/, then converts to a single compressed
# GLB (DRACO geometry + KTX2 textures) into the garden asset directory.
#
# All Poly Haven assets are CC0 (public domain) — no attribution required.
# ─────────────────────────────────────────────────────────────────────────────
set -uo pipefail

SRC=/home/z/my-project/assets-src
DEST=/home/z/my-project/exospace/public/assets/venues/sculpture-garden
mkdir -p "$SRC" "$DEST"

# map: polyhaven_id|target_glb_name|resolution
ASSETS=(
  "jacaranda_tree|tree_large_01.glb|1k"
  "island_tree_01|tree_medium_01.glb|1k"
  "island_tree_02|tree_medium_02.glb|1k"
  "searsia_lucida|shrub_01.glb|1k"
  "grass_medium_01|grass_clump_01.glb|1k"
  "boulder_01|boulder_01.glb|1k"
)

for entry in "${ASSETS[@]}"; do
  ID="${entry%%|*}"; rest="${entry#*|}"
  NAME="${rest%%|*}"; RES="${rest##*|}"
  DIR="$SRC/$ID"
  echo "═══ $ID → $NAME (res $RES)"

  if [ -f "$DEST/$NAME" ]; then
    echo "    already converted — skip"; continue
  fi

  mkdir -p "$DIR"
  # 1. manifest
  curl -s --max-time 30 "https://api.polyhaven.com/files/$ID" -o "$DIR/manifest.json"
  python3 - "$DIR/manifest.json" "$DIR" "$RES" <<'PY'
import json, sys, os
manifest, outdir, res = json.load(open(sys.argv[1])), sys.argv[2], sys.argv[3]
files = [("manifest.json", None)]
g = manifest.get("gltf", {})
if res not in g:
    res = next(iter(g))  # fall back to first available
scene = g[res]["gltf"]
scene_name = os.path.basename(scene["url"].split("?")[0])
files.append((scene["url"], scene_name))  # scene at root
for relpath, info in (scene.get("include") or {}).items():
    files.append((info["url"], relpath))
tasks = []
for url, rel in files:
    if rel is None:
        continue
    dst = os.path.join(outdir, rel)
    os.makedirs(os.path.dirname(dst) or outdir, exist_ok=True)
    tasks.append((url, dst))
open(os.path.join(outdir, "scene.gltf.path"), "w").write(
    os.path.join(outdir, scene_name))
open(os.path.join(outdir, "download.list"), "w").write(
    "\n".join(f"{u}\t{d}" for u, d in tasks) + "\n")
PY
  # 2. download all referenced files (3 attempts each)
  #    NOTE: curl reads stdin — without </dev/null it swallows the rest of
  #    download.list and the while loop dies after the first big file.
  while IFS=$'\t' read -r url dst || [ -n "$url" ]; do
    [ -z "$url" ] && continue
    if [ ! -s "$dst" ]; then
      for attempt in 1 2 3; do
        curl -sL --fail --max-time 400 --retry 2 -o "$dst" "$url" < /dev/null && break
        echo "    ⚠ attempt $attempt failed: $url"; rm -f "$dst"; sleep 2
      done
    fi
  done < "$DIR/download.list"
  SCENE=$(cat "$DIR/scene.gltf.path")
  if [ ! -s "$SCENE" ]; then echo "    ⚠ scene missing — skip"; continue; fi
  # 3. convert → single compressed GLB (webp textures: KTX2 needs the toktx
  #    binary which this environment lacks; EXT_texture_webp is universal)
  cd /home/z/my-project/exospace
  npx @gltf-transform/cli optimize "$SCENE" "$DEST/$NAME" \
      --texture-compress webp \
      --compress draco \
      --texture-size 1024 \
      --simplify true 2>&1 | tail -2
  ls -la "$DEST/$NAME" 2>/dev/null
done
echo "── done ──────────────────────────────"
ls -la "$DEST"
