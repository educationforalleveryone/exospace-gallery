#!/usr/bin/env python3
"""
Exospace Iteration 1 "The Rehearsal" — sample artwork generator.

Generates the 12 demonstration artworks referenced by
config/sample_exhibitions.php into
exospace_analysis/public/assets/sample/artworks/.

Design brief: abstract, muted, gallery-appropriate compositions that read
as "elegant miniature" (roadmap §5.5) — soft gradients, layered geometry,
subtle grain, gentle vignette. No text, no faces, no photographic claims.

Deterministic: every artwork uses a fixed seed, so re-running regenerates
byte-identical compositions (same PRNG philosophy as the Iteration 0 viewer
work).
"""

import math
import os
import random

from PIL import Image, ImageDraw, ImageFilter

OUT = "/home/z/my-project/exospace_analysis/public/assets/sample/artworks"
os.makedirs(OUT, exist_ok=True)


# ── helpers ──────────────────────────────────────────────────────────────────

def hx(h, a=255):
    """#rrggbb -> (r,g,b,a)"""
    h = h.lstrip('#')
    return (int(h[0:2], 16), int(h[2:4], 16), int(h[4:6], 16), a)


def hex_rgb(h):
    h = h.lstrip('#')
    return (int(h[0:2], 16), int(h[2:4], 16), int(h[4:6], 16))


def lerp(a, b, t):
    return tuple(int(a[i] + (b[i] - a[i]) * t) for i in range(3))


def vertical_gradient(size, top, bottom):
    w, h = size
    img = Image.new('RGB', size)
    px = img.load()
    for y in range(h):
        c = lerp(top, bottom, y / max(h - 1, 1))
        for x in range(w):
            px[x, y] = c
    return img


def soft_layer(base, layer, blur=24, alpha=1.0):
    """Composite an RGBA layer (blurred) onto base with global alpha."""
    l = layer.filter(ImageFilter.GaussianBlur(blur))
    if alpha < 1.0:
        a = l.getchannel('A').point(lambda v: int(v * alpha))
        l.putalpha(a)
    base.paste(l, (0, 0), l)
    return base


def grain(img, amount=9):
    """Subtle monochrome grain overlay."""
    w, h = img.size
    noise = Image.effect_noise((w // 2, h // 2), amount).resize((w, h))
    noise_rgb = Image.merge('RGB', (noise, noise, noise))
    return Image.blend(img, Image.blend(img, noise_rgb, 0.06), 0.5)


def vignette(img, strength=0.30, ease=1.9):
    """Radial darkening toward the edges."""
    w, h = img.size
    mask = Image.new('L', (w, h), 0)
    d = ImageDraw.Draw(mask)
    # concentric soft ellipses: bright center -> dark edge
    steps = 48
    for i in range(steps, 0, -1):
        t = i / steps
        val = int(255 * (strength * (t ** ease)))
        box = (
            int(w * 0.5 - w * 0.75 * t),
            int(h * 0.5 - h * 0.75 * t),
            int(w * 0.5 + w * 0.75 * t),
            int(h * 0.5 + h * 0.75 * t),
        )
        d.ellipse(box, fill=val)
    mask = mask.filter(ImageFilter.GaussianBlur(min(w, h) // 24))
    black = Image.new('RGB', (w, h), (8, 8, 12))
    return Image.composite(black, img, mask.point(lambda v: min(v, int(255 * strength))))


def finish(img, grain_amount=9, vig=0.30):
    img = grain(img, grain_amount)
    img = vignette(img, vig)
    return img


# ── artwork generators ───────────────────────────────────────────────────────

def bands(size, palette, seed, wave=0.0, softness=40):
    """Layered horizontal bands (harbour-light, tide-memorandum)."""
    rnd = random.Random(seed)
    w, h = size
    base = vertical_gradient(size, hex_rgb(palette[0]), hex_rgb(palette[-1]))
    lay = Image.new('RGBA', size, (0, 0, 0, 0))
    d = ImageDraw.Draw(lay)
    n = len(palette) + 3
    for i in range(n):
        y = int(h * (0.12 + 0.78 * i / n) + rnd.uniform(-h * 0.03, h * 0.03))
        band_h = int(h * rnd.uniform(0.10, 0.22))
        c = hx(palette[i % len(palette)], rnd.randint(90, 150))
        pts = []
        for x in range(0, w + 20, 20):
            yy = y + (math.sin(x / w * math.pi * 2 * (1 + i % 3)) * h * wave if wave else 0)
            pts.append((x, yy))
        poly = pts + [(w, y + band_h), (0, y + band_h)]
        d.polygon(poly, fill=c)
    return soft_layer(base, lay, blur=softness)


def lattice(size, palette, seed):
    """Warm rotated grid (dawn-lattice)."""
    rnd = random.Random(seed)
    w, h = size
    base = vertical_gradient(size, hex_rgb(palette[0]), hex_rgb(palette[1]))
    lay = Image.new('RGBA', size, (0, 0, 0, 0))
    d = ImageDraw.Draw(lay)
    angle = math.radians(rnd.uniform(8, 14))
    step = int(min(w, h) * 0.075)
    for i in range(-h, w + h, step):
        x1, y1 = i, -50
        x2 = i + int(math.tan(angle) * h)
        y2 = h + 50
        d.line([(x1, y1), (x2, y2)], fill=hx(palette[2], rnd.randint(50, 110)), width=rnd.choice([2, 3, 5]))
        y_off = i
        d.line([(-50, y_off), (w + 50, y_off + int(math.tan(angle) * w))],
               fill=hx(palette[3], rnd.randint(40, 90)), width=rnd.choice([2, 4]))
    base = soft_layer(base, lay, blur=6)
    # horizon glow
    glow = Image.new('RGBA', size, (0, 0, 0, 0))
    dg = ImageDraw.Draw(glow)
    dg.ellipse([w * 0.55, h * 0.28, w * 0.95, h * 0.62], fill=hx(palette[2], 120))
    return soft_layer(base, glow, blur=90)


def strata(size, palette, seed):
    """Wavy teal strata (tide-memorandum)."""
    return bands(size, palette, seed, wave=0.035, softness=26)


def horizon(size, palette, seed):
    """Two-field split + sun disc (north-field)."""
    rnd = random.Random(seed)
    w, h = size
    base = Image.new('RGB', size, hex_rgb(palette[0]))
    lay = Image.new('RGBA', size, (0, 0, 0, 0))
    d = ImageDraw.Draw(lay)
    hy = int(h * rnd.uniform(0.44, 0.52))
    d.rectangle([0, hy, w, h], fill=hx(palette[1], 255))
    # sky gradient overlay
    for y in range(hy):
        t = y / hy
        row_c = lerp(hex_rgb(palette[2]), hex_rgb(palette[0]), t)
        d.line([(0, y), (w, y)], fill=(*row_c, 110))
    # field shading
    for y in range(hy, h):
        t = (y - hy) / max(h - hy, 1)
        row_c = lerp(hex_rgb(palette[1]), hex_rgb(palette[3]), t)
        d.line([(0, y), (w, y)], fill=(*row_c, 90))
    base.paste(lay, (0, 0), lay)
    # low sun
    sun = Image.new('RGBA', size, (0, 0, 0, 0))
    ds = ImageDraw.Draw(sun)
    sx = int(w * rnd.uniform(0.3, 0.7))
    ds.ellipse([sx - h * 0.07, hy - h * 0.14, sx + h * 0.07, hy], fill=hx(palette[2], 200))
    base = soft_layer(base, sun, blur=30)
    return base


def columns(size, palette, seed):
    """Vertical color columns (vertical-chorus)."""
    rnd = random.Random(seed)
    w, h = size
    base = vertical_gradient(size, hex_rgb(palette[3]), hex_rgb(palette[0]))
    lay = Image.new('RGBA', size, (0, 0, 0, 0))
    d = ImageDraw.Draw(lay)
    x = int(w * 0.06)
    while x < w * 0.94:
        cw = int(w * rnd.uniform(0.05, 0.16))
        c = hx(rnd.choice(palette[:3]), rnd.randint(70, 140))
        top = int(h * rnd.uniform(-0.05, 0.25))
        bot = int(h * rnd.uniform(0.75, 1.05))
        d.rectangle([x, top, x + cw, bot], fill=c)
        x += cw + int(w * rnd.uniform(0.02, 0.08))
    return soft_layer(base, lay, blur=18)


def figure(size, palette, seed):
    """Elongated ascending form (ascending-figure)."""
    rnd = random.Random(seed)
    w, h = size
    base = vertical_gradient(size, hex_rgb(palette[3]), hex_rgb(palette[0]))
    lay = Image.new('RGBA', size, (0, 0, 0, 0))
    d = ImageDraw.Draw(lay)
    cx = w * rnd.uniform(0.42, 0.58)
    # stacked tapering ellipses rising
    segs = 9
    for i in range(segs):
        t = i / (segs - 1)
        rw = w * (0.20 - 0.10 * t) * rnd.uniform(0.9, 1.1)
        rh = h * 0.09
        cy = h * (0.92 - 0.72 * t)
        c = lerp(hex_rgb(palette[1]), hex_rgb(palette[2]), t)
        d.ellipse([cx - rw, cy - rh, cx + rw, cy + rh], fill=(*c, 110))
    # glow above
    g = Image.new('RGBA', size, (0, 0, 0, 0))
    dg = ImageDraw.Draw(g)
    dg.ellipse([cx - w * 0.2, h * 0.02, cx + w * 0.2, h * 0.3], fill=hx(palette[2], 90))
    base = soft_layer(base, lay, blur=16)
    return soft_layer(base, g, blur=60)


def shafts(size, palette, seed):
    """Vertical light shafts (cathedral-static)."""
    rnd = random.Random(seed)
    w, h = size
    base = vertical_gradient(size, hex_rgb(palette[3]), hex_rgb(palette[2]))
    lay = Image.new('RGBA', size, (0, 0, 0, 0))
    d = ImageDraw.Draw(lay)
    x = int(w * 0.04)
    while x < w:
        sw = int(w * rnd.uniform(0.02, 0.09))
        tilt = int(w * rnd.uniform(-0.10, 0.10))
        a = rnd.randint(60, 130)
        d.polygon([(x, h + 40), (x + sw, h + 40), (x + sw + tilt, -40), (x + tilt, -40)],
                  fill=hx(rnd.choice([palette[0], palette[1]]), a))
        x += sw + int(w * rnd.uniform(0.03, 0.12))
    return soft_layer(base, lay, blur=22)


def window_glow(size, palette, seed):
    """Luminous rectangle in deep blue (night-window)."""
    rnd = random.Random(seed)
    w, h = size
    base = vertical_gradient(size, hex_rgb(palette[2]), hex_rgb(palette[0]))
    glow = Image.new('RGBA', size, (0, 0, 0, 0))
    dg = ImageDraw.Draw(glow)
    rw, rh = w * 0.44, h * 0.52
    cx, cy = w * rnd.uniform(0.42, 0.58), h * rnd.uniform(0.42, 0.52)
    dg.rectangle([cx - rw / 2, cy - rh / 2, cx + rw / 2, cy + rh / 2], fill=hx(palette[3], 160))
    base = soft_layer(base, glow, blur=70)
    lay = Image.new('RGBA', size, (0, 0, 0, 0))
    d = ImageDraw.Draw(lay)
    d.rectangle([cx - rw / 2, cy - rh / 2, cx + rw / 2, cy + rh / 2], fill=hx(palette[1], 235))
    # mullion cross
    d.rectangle([cx - 2, cy - rh / 2, cx + 2, cy + rh / 2], fill=hx(palette[2], 200))
    d.rectangle([cx - rw / 2, cy - 2, cx + rw / 2, cy + 2], fill=hx(palette[2], 200))
    return soft_layer(base, lay, blur=2)


def field_mark(size, palette, seed):
    """Minimal off-white field + one mark (quiet-field)."""
    rnd = random.Random(seed)
    w, h = size
    base = vertical_gradient(size, hex_rgb(palette[0]), hex_rgb(palette[1]))
    lay = Image.new('RGBA', size, (0, 0, 0, 0))
    d = ImageDraw.Draw(lay)
    # one long calligraphic stroke
    pts = []
    x0, y0 = w * rnd.uniform(0.25, 0.4), h * rnd.uniform(0.55, 0.75)
    for i in range(24):
        t = i / 23
        pts.append((x0 + w * 0.35 * t, y0 - h * 0.16 * math.sin(t * math.pi) + rnd.uniform(-6, 6)))
    d.line(pts, fill=hx(palette[2], 230), width=int(min(w, h) * 0.012), joint='curve')
    return soft_layer(base, lay, blur=1.2)


def rings(size, palette, seed):
    """Neon rings on charcoal (signal-bloom)."""
    rnd = random.Random(seed)
    w, h = size
    base = vertical_gradient(size, hex_rgb(palette[0]), hex_rgb(palette[1]))
    lay = Image.new('RGBA', size, (0, 0, 0, 0))
    d = ImageDraw.Draw(lay)
    cx, cy = w * rnd.uniform(0.38, 0.62), h * rnd.uniform(0.38, 0.62)
    for i in range(7):
        r = min(w, h) * (0.08 + i * 0.075) * rnd.uniform(0.95, 1.05)
        c = hx(palette[2 + (i % 3)], rnd.randint(110, 190))
        d.ellipse([cx - r, cy - r, cx + r, cy + r], outline=c, width=rnd.choice([3, 5, 8]))
    base = soft_layer(base, lay, blur=5)
    halo = lay.filter(ImageFilter.GaussianBlur(26))
    base.paste(halo, (0, 0), halo)
    return base


def stones(size, palette, seed):
    """Balanced grey forms (stone-arrangement)."""
    rnd = random.Random(seed)
    w, h = size
    base = vertical_gradient(size, hex_rgb(palette[0]), hex_rgb(palette[1]))
    lay = Image.new('RGBA', size, (0, 0, 0, 0))
    d = ImageDraw.Draw(lay)
    for i in range(11):
        rw = w * rnd.uniform(0.06, 0.17)
        rh = rw * rnd.uniform(0.55, 0.8)
        cx = w * rnd.uniform(0.12, 0.88)
        cy = h * rnd.uniform(0.2, 0.85)
        shade = min(rnd.randint(0, 2), len(palette) - 3)
        c = hx(palette[2 + shade], rnd.randint(90, 160))
        d.ellipse([cx - rw, cy - rh, cx + rw, cy + rh], fill=c)
    return soft_layer(base, lay, blur=10)


def nebula(size, palette, seed):
    """Dust of violet and ember (slow-nebula)."""
    rnd = random.Random(seed)
    w, h = size
    base = vertical_gradient(size, hex_rgb(palette[3]), hex_rgb(palette[0]))
    lay = Image.new('RGBA', size, (0, 0, 0, 0))
    d = ImageDraw.Draw(lay)
    for _ in range(900):
        x = rnd.gauss(w * 0.5, w * 0.22)
        y = rnd.gauss(h * 0.45, h * 0.24)
        r = rnd.uniform(1.5, 7)
        c = hx(rnd.choice(palette[1:3]), rnd.randint(20, 90))
        d.ellipse([x - r, y - r, x + r, y + r], fill=c)
    base = soft_layer(base, lay, blur=6)
    # ember core
    core = Image.new('RGBA', size, (0, 0, 0, 0))
    dc = ImageDraw.Draw(core)
    dc.ellipse([w * 0.38, h * 0.34, w * 0.62, h * 0.58], fill=hx(palette[2], 70))
    return soft_layer(base, core, blur=80)


# ── the collection (keys match config/sample_exhibitions.php) ────────────────

ARTWORKS = {
    'harbour-light': (1920, 1280, bands,    ['#33414d', '#5a6b7a', '#8a97a5', '#c9cfd4']),
    'dawn-lattice':  (1920, 1280, lattice,  ['#4a3826', '#8a5a3a', '#d99a6c', '#f0d9c0']),
    'tide-memorandum': (1920, 1280, strata, ['#1f3d3d', '#3a6b6b', '#6b9a9a', '#a8c8c8']),
    'north-field':   (1920, 1280, horizon,  ['#3d4426', '#6b7a4a', '#a89a5a', '#d4cfa8']),
    'vertical-chorus': (1280, 1920, columns, ['#3a2a4d', '#6b4a8a', '#9a7ab8', '#c8b8d8']),
    'ascending-figure': (1280, 1920, figure, ['#4a3226', '#7a5a4a', '#b89880', '#e0d0c0']),
    'cathedral-static': (1280, 1920, shafts, ['#1a1a2d', '#4a4a68', '#8a8aa8', '#d8d8e8']),
    'night-window':  (1280, 1920, window_glow, ['#0a1226', '#1a2a4d', '#3a5a8a', '#a8c8f0']),
    'quiet-field':   (1600, 1600, field_mark, ['#d4d0c8', '#e8e5de', '#3a3a3a']),
    'signal-bloom':  (1600, 1600, rings,    ['#111114', '#2a2a2a', '#00e5ff', '#ff3a8a', '#b8ff3a']),
    'stone-arrangement': (1600, 1600, stones, ['#4a4a4a', '#6a6a6a', '#8a8a8a', '#b0b0b0']),
    'slow-nebula':   (1600, 1600, nebula,   ['#1a1226', '#3a2a4d', '#8a5a6a', '#d98a5a']),
}


def main():
    total = 0
    for key, (w, h, fn, palette) in ARTWORKS.items():
        seed = sum(ord(c) * (i + 7) for i, c in enumerate(key))  # stable per key
        img = fn((w, h), palette, seed)
        img = finish(img, grain_amount=9, vig=0.26)
        path = os.path.join(OUT, f"{key}.jpg")
        img.save(path, 'JPEG', quality=84, optimize=True, progressive=True)
        kb = os.path.getsize(path) / 1024
        print(f"  {key}.jpg  {w}x{h}  {kb:7.0f} KB")
        total += kb
    print(f"\n12 artworks, {total/1024:.1f} MB total -> {OUT}")


if __name__ == '__main__':
    main()
