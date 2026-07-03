#!/bin/bash
set -e

# ──────────────────────────────────────────────────────────────────────────
# Container start script for Exospace on Coolify / Nixpacks.
#
# (Hotfix) — Queue worker re-added to docker-start.sh because a separate
# Coolify application couldn't be created (GitHub app installation issue).
# This runs the worker as a background process inside the web container.
# Not ideal (no supervisor restart on crash) but functional for a small
# SaaS. To upgrade to a proper setup later, create a separate Coolify
# application with the command:
#   php artisan queue:work redis --tries=3 --max-jobs=1000 --max-time=3600
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

# 3. Clear all caches.
php /app/artisan config:clear
php /app/artisan cache:clear
php /app/artisan view:clear
php /app/artisan route:clear
php /app/artisan storage:link --force

# 4. Start the queue worker in the background
#    Uses Redis (QUEUE_CONNECTION=redis in .env). Runs with --tries=3
#    (retry failed jobs 3 times), --timeout=90 (kill jobs that run >90s),
#    --sleep=3 (sleep 3s when no jobs available). The & backgrounds it
#    so the web server can start. If the worker crashes, it stays down
#    until the next container restart (redeploy).
php /app/artisan queue:work redis --tries=3 --timeout=90 --sleep=3 &

# 5. Start PHP-FPM and Nginx
node /assets/scripts/prestart.mjs /assets/nginx.template.conf /nginx.conf && (php-fpm -y /assets/php-fpm.conf -d upload_max_filesize=50M -d post_max_size=50M -d memory_limit=512M & nginx -c /nginx.conf)
