#!/usr/bin/env bash
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
