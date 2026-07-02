#!/bin/bash
set -e

# ──────────────────────────────────────────────────────────────────────────
# Container start script for Exospace on Coolify / Nixpacks.
#
# Iteration 03 (task C11) changes:
#   - Removed `php artisan migrate --force` and `db:seed` from this script.
#     Migrations now run as a Coolify PRE-DEPLOY command so a failed
#     migration aborts the deploy instead of producing a broken container.
#     See DEPLOYMENT.md section 5.
#   - Removed `php /app/artisan queue:work &` — the queue worker now runs
#     as a separate Coolify service so it gets proper signal handling,
#     log separation, and independent scaling. See DEPLOYMENT.md section 7.
#   - Cache clearing is kept — the post-deploy command (see DEPLOYMENT.md
#     section 6) re-warms the caches with config:cache / route:cache / etc.
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

# 3. Clear all caches. The post-deploy command (DEPLOYMENT.md section 6)
#    re-warms them with config:cache / route:cache / view:cache / event:cache.
#    storage:link ensures the public/storage symlink exists for serving
#    user-uploaded files.
php /app/artisan config:clear
php /app/artisan cache:clear
php /app/artisan view:clear
php /app/artisan route:clear
php /app/artisan storage:link --force

# 4. Start PHP-FPM and Nginx
#    The queue worker and scheduler run as SEPARATE Coolify services —
#    see DEPLOYMENT.md sections 7 and 8.
node /assets/scripts/prestart.mjs /assets/nginx.template.conf /nginx.conf && (php-fpm -y /assets/php-fpm.conf -d upload_max_filesize=50M -d post_max_size=50M -d memory_limit=512M & nginx -c /nginx.conf)
