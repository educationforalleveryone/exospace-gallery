#!/usr/bin/env bash
# build.sh — build the PHP-less visual harness and lay it out where the
# static server + shoot.mjs expect it (public/harness/harness.html at the
# ROOT of /harness/, not the vite-emitted scripts/harness/ mirror path).
#
#   bash scripts/harness/build.sh
#
# WHY THE COPY: the harness vite config's rollup input is
# scripts/harness/harness.html, so vite preserves that relative path under
# outDir → public/harness/scripts/harness/harness.html. The QA server serves
# /harness/harness.html from the root, so the fresh build must be copied up
# (asset/ bundle references are root-absolute /harness/assets/*, unchanged).
set -euo pipefail
cd "$(dirname "$0")/../.."
npx vite build --config scripts/harness/vite.harness.config.mjs
cp public/harness/scripts/harness/harness.html public/harness/harness.html
echo "[harness] built + copied to public/harness/harness.html"
