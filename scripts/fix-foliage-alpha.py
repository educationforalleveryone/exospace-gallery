#!/usr/bin/env python3
# ─────────────────────────────────────────────────────────────────────────────
# fix-foliage-alpha.py — restore + bleed foliage alpha for Poly Haven glTFs
#
# Poly Haven's GLTF packages ship foliage diffuse as RGB jpgs (no alpha) even
# though the material is alphaMode BLEND — the leaf cards then render as
# opaque quads with their black background (the "black canopy" defect). The
# alpha PNGs exist on the CDN (the BLEND format ships them); this script:
#   1. downloads the missing *_alpha_1k.png per asset
#   2. composites diff RGB + alpha into RGBA
#   3. ALPHA-BLEEDS the RGB into transparent areas (dilation) — otherwise
#      mipmapping converges distant foliage to the atlas's average color
#      (near-black), the classic dark-canopy-at-distance defect
#   4. patches the source gltf: new RGBA image/texture → baseColorTexture
# ─────────────────────────────────────────────────────────────────────────────
import json, os, sys, urllib.request
from PIL import Image, ImageFilter

SRC = '/home/z/my-project/assets-src'
CDN = 'https://dl.polyhaven.org/file/ph-assets/Models/png/1k'

ASSETS = ['jacaranda_tree', 'island_tree_01', 'island_tree_02', 'searsia_lucida', 'grass_medium_01']

def fetch(url, dst):
    if os.path.exists(dst) and os.path.getsize(dst) > 0:
        return True
    try:
        urllib.request.urlretrieve(url, dst)
        return True
    except Exception as e:
        print(f'  ⚠ download failed {url}: {e}')
        return False

def alpha_bleed(rgba, passes=3):
    """Push leaf RGB outward into transparent pixels (RGB-only; A stays 0)."""
    for _ in range(passes):
        blurred = rgba.filter(ImageFilter.GaussianBlur(4))
        px, bx = rgba.load(), blurred.load()
        w, h = rgba.size
        for y in range(h):
            for x in range(w):
                if px[x, y][3] < 8:
                    r, g, b, _ = bx[x, y]
                    px[x, y] = (r, g, b, 0)
    return rgba

for asset in ASSETS:
    d = f'{SRC}/{asset}'
    gltf_path = None
    for f in os.listdir(d):
        if f.endswith('.gltf'):
            gltf_path = os.path.join(d, f)
    if not gltf_path:
        print(f'== {asset}: no gltf — skip'); continue
    gltf = json.load(open(gltf_path))
    # find BLEND materials whose baseColor image is an RGB jpg
    needs = []
    for mi, m in enumerate(gltf.get('materials', [])):
        if m.get('alphaMode') != 'BLEND':
            continue
        bct = m.get('pbrMetallicRoughness', {}).get('baseColorTexture')
        if bct is None:
            continue
        tex = gltf['textures'][bct['index']]
        src = tex.get('source')
        img = gltf['images'][src]
        uri = img.get('uri', '')
        if uri.endswith('.jpg'):
            needs.append((mi, bct['index'], src, uri))
    if not needs:
        print(f'== {asset}: BLEND materials already carry alpha — skip')
        continue
    print(f'== {asset}: fixing {len(needs)} BLEND material(s)')
    for mi, tex_idx, src_idx, uri in needs:
        stem = os.path.basename(uri).replace('_diff_1k.jpg', '')
        alpha_name = f'{stem}_alpha_1k.png'
        alpha_dst = f'{d}/textures/{alpha_name}'
        if not fetch(f'{CDN}/{asset}/{alpha_name}', alpha_dst):
            continue
        diff = Image.open(f'{d}/{uri}').convert('RGB')
        alpha = Image.open(alpha_dst).convert('L')
        if alpha.size != diff.size:
            alpha = alpha.resize(diff.size)
        rgba = diff.convert('RGBA')
        rgba.putalpha(alpha)
        # Canopy tonal normalization: Poly Haven foliage albedo targets a
        # filmic/overcast workflow; under the garden's daylight rig the same
        # values read as a heavy dark mass at distance. A +30% gain on OPAQUE
        # pixels brings the canopy to a fresh mid-green while keeping the
        # species' character (transparent pixels stay black-RGB for BLEND).
        r, g, b, a = rgba.split()
        r = r.point(lambda c: min(255, int(c * 1.3)))
        g = g.point(lambda c: min(255, int(c * 1.3)))
        b = b.point(lambda c: min(255, int(c * 1.3)))
        rgba = Image.merge('RGBA', (r, g, b, a))
        rgba = alpha_bleed(rgba)
        out_name = f'{stem}_diff_rgba_1k.png'
        out_rel = f'textures/{out_name}'
        rgba.save(f'{d}/{out_rel}')
        # patch gltf: add image + texture, point baseColorTexture at it
        img_idx = len(gltf['images'])
        gltf['images'].append({'uri': out_rel})
        tex_idx_new = len(gltf['textures'])
        gltf['textures'].append({'source': img_idx})
        gltf['materials'][mi]['pbrMetallicRoughness']['baseColorTexture'] = {'index': tex_idx_new}
        print(f'  ✓ {stem}: RGBA + bleed → {out_rel}')
    json.dump(gltf, open(gltf_path, 'w'))
print('done')
