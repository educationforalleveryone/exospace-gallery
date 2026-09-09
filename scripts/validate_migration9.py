#!/usr/bin/env python3
"""validate_migration9.py — PHP-less validation of 000009 (the Media Wall pass).

The sandbox has no PHP, so this replays the migration's exact up()/down()
logic in Python against the REAL v3.0.0 chain body (extracted verbatim from
000008's V3_STRUCTURE const — the body production converged to). Validates:

  1. The EXPECTED guards semantically match the chain body (the migration
     would NOT silently skip on the live row).
  2. up() produces exactly the expected v3.1.0 body (64 descriptors, all
     deltas, additions appended).
  3. up() is idempotent (replay changes nothing).
  4. down() restores a body semantically equal to the v3.0.0 baseline.
  5. Admin edits are respected (an edited descriptor is skipped, others land).
  6. The DRIFTED row (int 5 vs 5.0 + re-sorted keys — the production disease)
     still matches the semantic guards.
"""
import json, re, copy, sys

MIG9 = "/home/z/my-project/exospace/database/migrations/2026_09_09_000009_luxury_penthouse_media_wall.php"
MIG8 = "/home/z/my-project/exospace/database/migrations/2026_09_09_000008_luxury_penthouse_convergence.php"

# ── PHP one-line descriptor parser ───────────────────────────────────────────
def split_top(s):
    """Split on top-level commas (bracket depth 0, outside quotes)."""
    items, depth, cur, in_str = [], 0, '', False
    for ch in s:
        if ch == "'":
            in_str = not in_str
        if not in_str:
            if ch == '[': depth += 1
            elif ch == ']': depth -= 1
        if ch == ',' and depth == 0 and not in_str:
            items.append(cur); cur = ''
        else:
            cur += ch
    if cur.strip(): items.append(cur)
    return items

def parse_php_value(s):
    s = s.strip()
    if s.startswith('['):
        if not s.endswith(']'):
            raise ValueError(f'unbalanced: {s!r}')
        items = [i for i in split_top(s[1:-1]) if i.strip()]
        is_map = any(re.match(r"^'[^']*'\s*=>", i.strip()) for i in items)
        if is_map:
            out = {}
            for i in items:
                m = re.match(r"^'([^']*)'\s*=>\s*(.*)$", i.strip(), re.S)
                if not m:
                    raise ValueError(f'bad map item: {i!r}')
                out[m.group(1)] = parse_php_value(m.group(2))
            return out
        return [parse_php_value(i) for i in items]
    if s.startswith("'") and s.endswith("'"):
        return s[1:-1].replace("\\'", "'")
    if re.match(r'^-?\d+$', s):
        return int(s)
    if re.match(r'^-?\d*\.\d+$', s):
        return float(s)
    if s in ('true', 'false'):
        return s == 'true'
    if s == 'null':
        return None
    raise ValueError(f'unparseable: {s!r}')

def extract_const(path, name):
    src = open(path).read()
    # single-line const first (one descriptor, ends with '];' on the same line)
    m = re.search(rf"private const {name} = (\[[^\n]*?\]);", src)
    if m:
        return [parse_php_value(m.group(1))]
    # multi-descriptor const (entries separated across lines)
    m = re.search(rf"private const {name} = \[(.*?)[ \t]*\n\s*\];", src, re.S)
    body = m.group(1)
    # strip PHP comment lines — they carry apostrophes that break quote tracking
    body = re.sub(r"(?m)^\s*//.*$", "", body)
    # split into top-level ['id' => ...] entries
    entries, depth, cur, in_str = [], 0, '', False
    for ch in body:
        if ch == "'": in_str = not in_str
        if not in_str:
            if ch == '[': depth += 1
            elif ch == ']': depth -= 1
        cur += ch
        if depth == 0 and ch == ']' and not in_str:
            entries.append(cur); cur = ''
    out = []
    for e in entries:
        e = e.strip().lstrip(',').strip()  # entries carry the PRECEDING separator
        if e.startswith('['):
            out.append(parse_php_value(e))
    # Map-shaped const ('id' => [...]) → return the descriptor VALUES as a list
    if out == [] and entries:
        first = entries[0].strip().lstrip(',').strip()
        if re.match(r"^'[^']*'\s*=>", first):
            as_list = []
            for e in entries:
                e = e.strip().lstrip(',').strip()
                mm = re.match(r"^'([^']*)'\s*=>\s*(\[\n?.*\])\s*,?$", e, re.S)
                if mm:
                    as_list.append(parse_php_value(mm.group(2)))
            return as_list
    return out

# ── Semantic comparator (the migration's sameValue, ported) ─────────────────
def same_value(a, b):
    if isinstance(a, list) and isinstance(b, list):
        return len(a) == len(b) and all(same_value(x, y) for x, y in zip(a, b))
    if isinstance(a, dict) and isinstance(b, dict):
        if set(a.keys()) != set(b.keys()):
            return False
        return all(same_value(a[k], b[k]) for k in a)
    if isinstance(a, (int, float)) and not isinstance(a, bool) and isinstance(b, (int, float)) and not isinstance(b, bool):
        return abs(float(a) - float(b)) < 1e-15
    return a == b

# ── The migration's tables (extracted from the PHP to avoid transcription) ──
EXPECTED = {d['id']: d for d in extract_const(MIG9, 'EXPECTED')}
MUTATED  = {d['id']: d for d in extract_const(MIG9, 'MUTATED')}
MEDIA_IDS = ['media-console', 'media-bezel']
MEDIA = extract_const(MIG9, 'MEDIA_DESCRIPTORS')
PICTURE_BAR = extract_const(MIG9, 'PICTURE_BAR')[0]
NEW_FIXTURES = extract_const(MIG9, 'NEW_FIXTURES')

def up(structure, fixtures, visual):
    """Faithful port of 000009::up(). Returns (new_structure, new_fixtures, visual, log)."""
    log = []
    structure = copy.deepcopy(structure); visual = copy.deepcopy(visual)
    by_id = {d['id']: i for i, d in enumerate(structure) if isinstance(d, dict) and 'id' in d}
    for id_, new_body in MUTATED.items():
        exp = EXPECTED.get(id_)
        if id_ not in by_id or exp is None:
            log.append(f"descriptor '{id_}' absent — skipped."); continue
        if not same_value(structure[by_id[id_]], exp):
            log.append(f"descriptor '{id_}' is admin-customised (or from another chain state) — left untouched."); continue
        structure[by_id[id_]] = new_body
        log.append(f"descriptor '{id_}' updated.")
    lounge_ok = 'sofa-base' in by_id
    for d in MEDIA:
        if d['id'] in by_id:
            log.append(f"'{d['id']}' already present."); continue
        if not lounge_ok:
            log.append(f"lounge anchor missing — '{d['id']}' not added."); continue
        structure.append(copy.deepcopy(d)); log.append(f"'{d['id']}' added.")
    if 'art-wall-panel' in by_id and PICTURE_BAR['id'] not in [d.get('id') for d in structure]:
        structure.append(copy.deepcopy(PICTURE_BAR)); log.append("picture bar added.")
    fx_ids = {f.get('id') for f in fixtures if isinstance(f, dict)}
    for f in NEW_FIXTURES:
        if f['id'] not in fx_ids:
            fixtures.append(copy.deepcopy(f)); log.append(f"fixture '{f['id']}' added.")
    if 'media_wall' not in visual:
        visual['media_wall'] = {'bezel': 'media-bezel', 'screen': {'w': 1.5, 'h': 0.84}, 'accent': '0xd8a35a'}
        log.append('media_wall declared.')
    return structure, fixtures, visual, log

def down(structure, fixtures, visual):
    structure = copy.deepcopy(structure); visual = copy.deepcopy(visual)
    remove = set(MEDIA_IDS) | {PICTURE_BAR['id']}
    kept = [d for d in structure if not (isinstance(d, dict) and d.get('id') in remove)]
    by_id = {d['id']: i for i, d in enumerate(kept) if isinstance(d, dict) and 'id' in d}
    for id_, new_body in MUTATED.items():
        exp = EXPECTED.get(id_)
        if id_ in by_id and exp is not None and same_value(kept[by_id[id_]], new_body):
            kept[by_id[id_]] = exp
    fixtures = [f for f in fixtures if not (isinstance(f, dict) and f.get('id') in {x['id'] for x in NEW_FIXTURES})]
    visual.pop('media_wall', None)
    return kept, fixtures, visual

# ── Validation ───────────────────────────────────────────────────────────────
fails = 0
def check(name, cond):
    global fails
    print(('  PASS  ' if cond else '  FAIL  ') + name)
    if not cond: fails += 1

v3_structure = extract_const(MIG8, 'V3_STRUCTURE')
v3_fixtures  = extract_const(MIG8, 'V3_FIXTURES')
print(f"chain body: {len(v3_structure)} descriptors, {len(v3_fixtures)} fixtures\n")

# 1. Guards match the chain body
print('[1] EXPECTED guards vs the real v3.0.0 chain body')
for id_, exp in EXPECTED.items():
    found = next((d for d in v3_structure if d.get('id') == id_), None)
    check(f"guard '{id_}' semantically matches the chain body", found is not None and same_value(found, exp))

# 2. up() produces the expected v3.1.0 body
print('[2] up() on the seeded row')
st2, fx2, vis2, log2 = up(v3_structure, list(v3_fixtures), {})
check('structure grows 61 → 64', len(st2) == 64)
check('fixtures grow 5 → 7', len(fx2) == 7)
d = {x['id']: x for x in st2}
check('lamp-pole moved + thickened', same_value(d['lamp-pole'], MUTATED['lamp-pole']))
check('lamp-shade strengthened', same_value(d['lamp-shade'], MUTATED['lamp-shade']))
check('bench-base walnut', same_value(d['bench-base'], MUTATED['bench-base']))
check('knot rests on plinth', same_value(d['sculpture-knot'], MUTATED['sculpture-knot']))
check('media console present', 'media-console' in d)
check('media bezel present', 'media-bezel' in d)
check('picture bar present', 'art-wall-light-bar' in d)
check('media_wall declared', vis2.get('media_wall', {}).get('bezel') == 'media-bezel')
check('art-wall-panel untouched', same_value(d['art-wall-panel'], EXPECTED['art-wall-panel']))

# 3. Idempotency
print('[3] replay idempotency')
st3, fx3, vis3, _ = up(st2, fx2, vis2)
check('second up(): structure unchanged', same_value(st2, st3))
check('second up(): fixtures unchanged', same_value(fx2, fx3))
check('second up(): media_wall unchanged', same_value(vis2, vis3))

# 4. down() restores the baseline
print('[4] down() round-trip')
st4, fx4, vis4 = down(st2, fx2, vis2)
check('down(): structure semantically equals the v3.0.0 baseline', same_value(st4, v3_structure))
check('down(): fixtures equal baseline', same_value(fx4, v3_fixtures))
check('down(): media_wall removed', 'media_wall' not in vis4)

# 5. Admin respect
print('[5] admin-edit respect')
stA = copy.deepcopy(v3_structure)
i = next(k for k, d in enumerate(stA) if d.get('id') == 'lamp-pole')
stA[i] = dict(stA[i]); stA[i] = copy.deepcopy(stA[i]); stA[i]['at']['offset'] = [3.0, 0.8, 1.5]
stA2, _, _, logA = up(stA, list(v3_fixtures), {})
dA = {x['id']: x for x in stA2}
check('admin lamp offset survives re-run', dA['lamp-pole']['at']['offset'] == [3.0, 0.8, 1.5])
check('other mutations still applied on re-run (bench walnut)', dA['bench-base']['material'] == 'walnut')
check('skipped decision logged loudly', any('admin-customised' in l for l in logA))

# 6. The drifted row still matches (int/float + key order disease)
print('[6] drifted-row (editor round-trip) guards')
def drift(x):
    if isinstance(x, dict):
        return {k: drift(v) for k, v in sorted(x.items())}
    if isinstance(x, list):
        return [drift(v) for v in x]
    if isinstance(x, float) and x == int(x):
        return int(x)
    return x
stD, fxD, visD, logD = up(drift(v3_structure), drift(list(v3_fixtures)), drift({}))
dD = {x['id']: x for x in stD}
check('drifted row: swaps still fire (media furniture added)', 'media-console' in dD)
check('drifted row: lamp lands on the v3.1.0 body', same_value(dD['lamp-pole'], MUTATED['lamp-pole']))

print()
if fails == 0:
    print('MIGRATION 9 PYTHON REPLAY: ALL PASS')
else:
    print(f'MIGRATION 9 PYTHON REPLAY: {fails} FAILURE(S)')
    sys.exit(1)
