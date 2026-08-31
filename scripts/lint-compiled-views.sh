#!/usr/bin/env bash
# scripts/lint-compiled-views.sh
#
# BUILD-TIME GUARD (added 2026-08-31) — run AFTER `php artisan view:cache`.
#
# WHY THIS EXISTS:
#   `php artisan view:cache` compiles every Blade template to PHP but does
#   NOT syntax-check the generated PHP. A template that compiles to invalid
#   PHP (unclosed @if, a non-self-closing <x-tag> inside a JS comment, an
#   @yield() inside a component attribute, directives glued to word chars…)
#   bakes silently into the image and only 500s at runtime — this is exactly
#   how production shipped a broken edit.blade.php and a broken
#   abandoned-cart-text email on 2026-08-31.
#
# WHAT THIS DOES:
#   Runs `php -l` on every compiled view in storage/framework/views and
#   fails the build (exit 1) on the first invalid one, with the parse error
#   printed for the offending template.
#
# Usage: bash scripts/lint-compiled-views.sh [viewsDir]
#        (viewsDir defaults to storage/framework/views, which view:cache
#         has just wiped and repopulated)
set -u

VIEWS_DIR="${1:-storage/framework/views}"
PHP_BIN="${PHP_BIN:-php}"

if ! command -v "$PHP_BIN" >/dev/null 2>&1; then
    echo "lint-compiled-views: php binary not found (set PHP_BIN to override)" >&2
    exit 1
fi

if [ ! -d "$VIEWS_DIR" ]; then
    echo "lint-compiled-views: directory not found: $VIEWS_DIR (did view:cache run?)" >&2
    exit 1
fi

fail=0
count=0
while IFS= read -r -d '' f; do
    count=$((count + 1))
    if ! "$PHP_BIN" -l "$f" >/dev/null 2>&1; then
        echo "lint-compiled-views: COMPILED VIEW HAS INVALID PHP: $f" >&2
        "$PHP_BIN" -l "$f" >&2 || true
        fail=1
    fi
done < <(find "$VIEWS_DIR" -name '*.php' -print0 | sort -z)

echo "lint-compiled-views: php -l checked ${count} compiled view(s)"

if [ "$fail" -ne 0 ]; then
    echo "lint-compiled-views: FAILED — a Blade template compiles to invalid PHP. Fix the template above and rebuild." >&2
    exit 1
fi
