#!/usr/bin/env python3
"""
add_csp_nonces.py — Adds CSP nonces to all inline <script> tags in Blade templates.

Why this exists:
  The CSP middleware (app/Http/Middleware/SecurityHeaders.php) sets
  script-src 'self' 'nonce-<random>' 'strict-dynamic' 'unsafe-eval'.
  Inline <script> blocks MUST carry nonce="<nonce>" to execute.

  The Blade @nonce directive returns the per-request nonce string,
  so `<script nonce="@nonce">` is the canonical CSP-safe pattern.

What this script does:
  - Walks resources/views/ for *.blade.php
  - Finds every <script> tag
  - Skips non-JS script tags (type="application/ld+json", type="application/json",
    type="text/template", etc.) — CSP does not gate these
  - Skips <script> tags that already have a nonce= attribute
  - Skips <script src="..."> tags (external scripts; 'self' covers them)
  - For each remaining inline <script>, injects nonce="@nonce" right after the
    opening tag name

Idempotent: running it twice produces the same output.
"""
import re
import sys
from pathlib import Path

VIEWS_DIR = Path("/home/z/my-project/work/resources/views")

# Match <script ...> opening tags.
# We capture attributes to inspect them.
SCRIPT_OPEN_RE = re.compile(r"<script\b([^>]*)>", re.IGNORECASE)

# Types that are NOT executable JS — CSP doesn't gate them.
NON_JS_TYPES = {
    "application/ld+json",
    "application/json",
    "text/template",
    "text/plain",
    "text/x-handlebars-template",
    "text/html",
    "application/x-template",
}


def is_non_js(attrs_str: str) -> bool:
    """Return True if the script tag has a non-JS type attribute."""
    m = re.search(r'type\s*=\s*["\']([^"\']+)["\']', attrs_str, re.IGNORECASE)
    if not m:
        return False
    return m.group(1).strip().lower() in NON_JS_TYPES


def has_src(attrs_str: str) -> bool:
    return bool(re.search(r'\bsrc\s*=\s*["\']', attrs_str, re.IGNORECASE))


def has_nonce(attrs_str: str) -> bool:
    return bool(re.search(r'\bnonce\s*=\s*["\']', attrs_str, re.IGNORECASE))


def inject_nonce(attrs_str: str) -> str:
    """Inject nonce="@nonce" at the start of the attributes string."""
    # Preserve leading whitespace semantics; we add a leading space + nonce attr.
    return ' nonce="@nonce"' + attrs_str


def process_file(path: Path) -> int:
    """Process one blade file. Returns the number of nonces added."""
    text = path.read_text(encoding="utf-8")
    original = text
    added = 0

    def repl(m: re.Match) -> str:
        nonlocal added
        attrs = m.group(1)
        if has_nonce(attrs):
            return m.group(0)
        if has_src(attrs):
            return m.group(0)
        if is_non_js(attrs):
            return m.group(0)
        # Inline JS script without nonce — inject.
        added += 1
        return "<script" + inject_nonce(attrs) + ">"

    new_text = SCRIPT_OPEN_RE.sub(repl, text)
    if new_text != original:
        path.write_text(new_text, encoding="utf-8")
    return added


def main() -> int:
    if not VIEWS_DIR.is_dir():
        print(f"ERROR: {VIEWS_DIR} not found", file=sys.stderr)
        return 1

    total = 0
    files_changed = 0
    for blade in sorted(VIEWS_DIR.rglob("*.blade.php")):
        n = process_file(blade)
        if n:
            files_changed += 1
            total += n
            print(f"  {n:3d} nonces added  {blade.relative_to(VIEWS_DIR)}")

    print(f"\nDone. {total} nonces added across {files_changed} files.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
