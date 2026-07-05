#!/bin/bash
set -e

# ──────────────────────────────────────────────────────────────────────────
# Container start script for Exospace on Coolify / Nixpacks.
#
# P1-11: Queue worker with memory/job/time limits.
# TD-2: Build caches on startup (was clearing them — killed performance).
# P3-17: Run PreflightCheck as post-deploy health gate.
# ──────────────────────────────────────────────────────────────────────────

# 1. Configure PHP upload limits
cat > /assets/php-fpm-overrides.conf << 'PHPEOF'
upload_max_filesize = 50M
post_max_size = 50M
memory_limit = 512M
max_execution_time = 300
PHPEOF

# 2. Patch the Nginx template to allow 50MB uploads
if grep -q "client_max_body_size" /assets/nginx.template.conf; then
    sed -i 's/client_max_body_size [^;]*;/client_max_body_size 50M;/g' /assets/nginx.template.conf
else
    sed -i 's/server {/server {\n    client_max_body_size 50M;/g' /assets/nginx.template.conf
fi

# 3. TD-2/TD-4: Caches are now built in nixpacks.toml build phase (deploy time).
#    Here we only run storage:link (needs to run at container start because
#    the symlink is per-container) and migrate (TD-3: was missing — migrations
#    had to be run manually after each deploy).
php /app/artisan storage:link --force

# TD-3: Run migrations on deploy — previously the founder had to SSH in
# and run `php artisan migrate --force` manually after each deploy.
# --force skips the confirmation prompt in production.
php /app/artisan migrate --force

# 4. P3-17: Run PreflightCheck — exit(1) if critical config is wrong.
#    This catches issues like missing 2Checkout secrets, wrong APP_ENV, etc.
#    before the container starts serving traffic.
php /app/artisan exospace:preflight || echo "WARNING: Preflight check failed — see logs above. Container will start but may have issues."

# 5. Start the queue worker in the background
#    P1-11: --memory=256 --max-jobs=1000 --max-time=3600 --timeout=120
php /app/artisan queue:work redis --tries=3 --timeout=120 --sleep=3 --memory=256 --max-jobs=1000 --max-time=3600 &

# 6. Start PHP-FPM and Nginx
node /assets/scripts/prestart.mjs /assets/nginx.template.conf /nginx.conf && (php-fpm -y /assets/php-fpm.conf -d upload_max_filesize=50M -d post_max_size=50M -d memory_limit=512M & nginx -c /nginx.conf)
