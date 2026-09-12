#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/../.."
npx vite build --config scripts/harness/vite.harness.config.mjs
cp public/harness/scripts/harness/harness.html public/harness/harness.html
echo "[harness] built + copied to public/harness/harness.html"
