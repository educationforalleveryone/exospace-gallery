#!/bin/bash
set -e

# ──────────────────────────────────────────────────────────────────────────
# Container start script for Exospace on Coolify / Nixpacks.
#
# ITERATION-001 CHANGES (audit CR-1 + CR-2 + K-10):
#   - Preflight check now FAILS the container on critical errors.
#     Previously: `php artisan exospace:preflight || echo "WARNING..."`
#     The `||` short-circuit meant the container ALWAYS exited 0, so the
#     590-line PreflightCheck command was theater. Bad deploys shipped
#     undetected. Now we hard-fail with exit 1.
#   - Scheduler loop now runs in the background (cron replacement).
#     Previously: NO scheduler process was started — zero scheduled
#     commands ever fired (dunning, abandoned-cart, lifecycle, rollups,
#     cleanup, partitioning, anonymization all dead).
#   - Queue worker --memory bumped from 256 to 512 to match PHP-FPM
#     and accommodate ImageProcessingService peak (50MP decode + scaleDown
#     + thumbnail = ~350-450MB).
#
# PRESERVED FROM PRIOR VERSION:
#   - PHP upload limits (50M)
#   - Nginx client_max_body_size patch
#   - storage:link on every container start
#   - migrate --force on every container start
#   - queue:work with --tries=3 --timeout=120 --max-jobs=1000 --max-time=3600
#   - php-fpm + nginx start
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

# 2b. PERF-A4b (3D audit F4, iteration-4): static-asset caching + gzip.
#
#     WHY THIS RUNS HERE (and not in nginx.template.conf): production
#     verification (2026-08-24) proved that nixpacks generates
#     /assets/nginx.template.conf from ITS OWN internal template — the
#     repo's nginx.template.conf is never used (evidence: static files
#     carry X-Frame-Options, which the repo template intentionally does
#     not set and Laravel middleware cannot set for static files; and no
#     Cache-Control header was emitted after the repo file was edited).
#     The reliable injection point is the same runtime-sed mechanism the
#     client_max_body_size patch above uses, which is proven live.
#
#     What this adds, inside the server block:
#       - gzip for text assets (JS/CSS/JSON/SVG)
#       - /build|assets|decoders|img/  -> 30d + "public, immutable"
#         (Vite output is content-hashed; decoders are version-locked)
#       - /storage/                    -> 7d + "public"
#         (artwork media; paths stable per artwork, revalidated weekly so
#          curator re-uploads still flow)
#     The two security headers are re-added inside the locations because
#     nginx's add_header inheritance drops parent-level headers when a
#     location defines its own (observed in production: X-Frame-Options +
#     X-Content-Type-Options are set at server level by the nixpacks
#     template — without re-adding them here they would vanish for static
#     assets).
#
#     Idempotent: the marker comment guards against double insertion.
NGINX_TPL="/assets/nginx.template.conf"
if [ -f "$NGINX_TPL" ] && ! grep -q "exospace-static-cache" "$NGINX_TPL"; then
    # Anchor on "server {" — the same anchor the client_max_body_size patch
    # uses, verified to exist in the nixpacks-generated template.
    sed -i 's@server {@server {\n\n        # exospace-static-cache: gzip + immutable static caching (PERF-A4b)\n        gzip on;\n        gzip_comp_level 5;\n        gzip_min_length 256;\n        gzip_proxied any;\n        gzip_vary on;\n        gzip_types text/css text/javascript application/javascript application/json application/manifest+json image/svg+xml;\n\n        location ~* ^/(build|assets|decoders|img)/ {\n            expires 30d;\n            add_header Cache-Control "public, immutable";\n            add_header X-Content-Type-Options nosniff;\n            add_header X-Frame-Options SAMEORIGIN;\n            access_log off;\n            log_not_found off;\n            try_files $uri =404;\n        }\n\n        location ~* ^/storage/ {\n            expires 7d;\n            add_header Cache-Control "public";\n            add_header X-Content-Type-Options nosniff;\n            add_header X-Frame-Options SAMEORIGIN;\n            access_log off;\n            log_not_found off;\n            try_files $uri =404;\n        }@' "$NGINX_TPL"
    echo "Injected static-asset caching + gzip into nginx template (PERF-A4b)."
fi

# 3. TD-2/TD-4: Caches are now built in nixpacks.toml build phase (deploy time).
#    Here we only run storage:link (needs to run at container start because
#    the symlink is per-container) and migrate (TD-3: was missing — migrations
#    had to be run manually after each deploy).
php /app/artisan storage:link --force

# 3b. ANTI-STALE VIEW GUARD (2026-08-31): recompile every Blade view from the
#     source files actually present in THIS image, on every container start.
#     `view:cache` clears the compiled-view directory first, then recompiles
#     from source — so any compiled view left over from a PREVIOUS deploy
#     (e.g. persisted by a storage volume mount that shadows
#     storage/framework/views, or baked by an older build) is discarded.
#     WHY: Laravel only recompiles a view when the SOURCE file's mtime is
#     newer than the COMPILED file's mtime. Re-deployed source files can
#     carry OLDER mtimes than persisted compiled views, in which case Laravel
#     happily serves the stale compiled view forever. That is exactly the
#     production incident of 2026-08-31: admin/galleries/edit.blade.php
#     500'd with "syntax error, unexpected end of file, expecting elseif or
#     else or endif" from a compiled view that no longer matched its (fixed)
#     source. Non-fatal: if this fails we warn and continue — Laravel then
#     recompiles lazily at render time.
if ! php /app/artisan view:cache; then
    echo "WARNING: php artisan view:cache failed at container start — compiled views may be stale. Investigate before serving traffic." >&2
fi

# NOTE: Do NOT run `php artisan config:cache` anywhere in this pipeline.
# Once config is cached, Laravel's LoadEnvironmentVariables bootstrapper
# skips loading .env entirely (by design). In this Coolify setup, secrets
# like DB_HOST only ever exist in the .env file Coolify writes at deploy
# time — they are not real container-level env vars. Caching config
# permanently bakes in empty values for them with no recovery until the
# cache is cleared. Route/view caching (in nixpacks.toml) is unaffected
# and stays in place; only config:cache is unsafe here.

# TD-3: Run migrations on deploy — previously the founder had to SSH in
# and run `php artisan migrate --force` manually after each deploy.
# --force skips the confirmation prompt in production.
#
# K-4 (deferred to Iteration-003 database batch): deploy lock to prevent
# concurrent migrations when Coolify scales to multiple containers.
# For now, single-container deploy is safe.
php /app/artisan migrate --force

# 5. CR-1 FIX: Run PreflightCheck — exit(1) if critical config is wrong.
#    Previously this line was `php /app/artisan exospace:preflight || echo "WARNING..."`
#    The `||` short-circuit meant the container ALWAYS exited 0, so the preflight
#    safety net was theater. Bad deploys shipped undetected. Now we hard-fail.
#    This catches issues like missing 2Checkout secrets, wrong APP_ENV, missing
#    business address (CAN-SPAM), TRUSTED_PROXIES=* in prod, etc. before the
#    container starts serving traffic.
#
#    The PreflightCheck command returns:
#      - exit 0: all checks passed OR only warnings (advisory)
#      - exit 1: one or more CRITICAL failures (must fix before serving traffic)
#
#    If a soft-fail mode is desired for non-critical warnings, the PreflightCheck
#    command already returns 0 for warnings-only — only CRITICAL failures exit
#    non-zero, and those should always block startup.
if [ "${BYPASS_PREFLIGHT:-false}" != "true" ]; then
    if ! php /app/artisan exospace:preflight; then
        echo "================================================================" >&2
        echo "FATAL: Preflight check failed — aborting container start." >&2
        echo "================================================================" >&2
        echo "The PreflightCheck command reported one or more CRITICAL failures." >&2
        echo "Review the output above to identify which env var or config is wrong." >&2
        echo "Common causes:" >&2
        echo "  - Missing TWOCHECKOUT_SECRET_WORD / TWOCHECKOUT_BUY_LINK_SECRET_WORD" >&2
        echo "  - APP_DEBUG=true in production" >&2
        echo "  - APP_ENV != 'production' in production" >&2
        echo "  - TRUSTED_PROXIES=* in production (host-header spoofing risk)" >&2
        echo "  - Missing EXOSPACE_BUSINESS_ADDRESS (CAN-SPAM §316.2)" >&2
        echo "  - Missing APP_KEY" >&2
        echo "" >&2
        echo "To override (NOT RECOMMENDED in production):" >&2
        echo "  Set BYPASS_PREFLIGHT=true in .env — but file an issue immediately." >&2
        echo "================================================================" >&2
        exit 1
    fi
else
    echo "WARNING: BYPASS_PREFLIGHT=true — preflight check skipped. NOT RECOMMENDED in production." >&2
fi

# 6. CR-2 FIX: Start the Laravel scheduler in the background (cron replacement).
#    Previously NO scheduler process was started — zero scheduled commands ever fired.
#    This silently broke: dunning emails, abandoned-cart recovery, lifecycle nudges,
#    analytics rollups, banned-session purges, transaction partitioning, PII anonymization,
#    and pending custom-domain re-verification.
#
#    The scheduler loop runs `schedule:run` every 60 seconds; Laravel itself
#    decides what to fire based on the schedule defined in routes/console.php.
#    Log output goes to /app/storage/logs/scheduler.log (persistent volume).
#
#    ITERATION-6 (AUDIT-P1-6.1) FIX: scheduler.log rotation. Previously the
#    log grew unboundedly — the bare `>>` append had no rotation, so a
#    long-running container could fill the disk. Now we rotate the log before
#    each schedule:run tick: keep the last 5 rotations (scheduler.log.1
#    through scheduler.log.5), each capped at 10MB. This mirrors the
#    supervisord.conf pattern (10MB max, 5 backups) that was documented but
#    never applied to the bare `&` loop.
#
#    ALTERNATIVE DEPLOYMENT: If you prefer a separate Coolify cron service
#    running `php artisan schedule:work` (long-running scheduler), delete
#    this block and document the Coolify service in DEPLOYMENT.md. Either
#    approach works; this in-container loop is simpler to deploy.
if [ "${BYPASS_SCHEDULER:-false}" != "true" ]; then
    (
        while true; do
            # AUDIT-P1-6.1: Rotate scheduler.log if it exceeds 10MB.
            # Keeps last 5 rotations. Prevents unbounded growth.
            SCHED_LOG="/app/storage/logs/scheduler.log"
            if [ -f "$SCHED_LOG" ]; then
                LOG_SIZE=$(stat -c%s "$SCHED_LOG" 2>/dev/null || echo 0)
                if [ "$LOG_SIZE" -gt 10485760 ]; then
                    # Rotate: .4 → .5, .3 → .4, ... .1 → .2, current → .1
                    for i in 4 3 2 1; do
                        [ -f "$SCHED_LOG.$i" ] && mv "$SCHED_LOG.$i" "$SCHED_LOG.$((i+1))"
                    done
                    mv "$SCHED_LOG" "$SCHED_LOG.1"
                fi
            fi

            php /app/artisan schedule:run --no-interaction >> /app/storage/logs/scheduler.log 2>&1
            sleep 60
        done
    ) &
    SCHEDULER_PID=$!
    echo "Scheduler started (PID $SCHEDULER_PID). Logs: /app/storage/logs/scheduler.log (rotated at 10MB, 5 backups)"
else
    echo "BYPASS_SCHEDULER=true — scheduler NOT started (assuming external Coolify cron service)."
    SCHEDULER_PID=""
fi

# 7. Start the queue worker in the background.
#    P1-11: --tries=3 --timeout=120 --max-jobs=1000 --max-time=3600
#    K-10 FIX: --memory bumped from 256 to 512 to match PHP-FPM and accommodate
#    ImageProcessingService peak (50MP decode + scaleDown + thumbnail = ~350-450MB).
#    The 50MP cap is enforced in ImageProcessingService::process(); under GD
#    the actual peak can be 2-3x the decode buffer due to Intervention keeping
#    source + destination alive during scaleDown.
#
#    ITERATION-6 (AUDIT-P1-6.2): queue-worker.log. The queue worker's stdout/stderr
#    is captured by Coolify's log driver (not written to a file), so no rotation
#    needed here. The OperationalAlertService::checkQueueWorkerHealth() method
#    (added in this iteration) monitors the worker indirectly via the failed_jobs
#    table + the queue-worker.log staleness check (if the file exists).
#
#    N-6 (deferred to future iteration): queue prioritization. Currently a
#    single queue. Future iteration will add --queue=high,default,low and
#    a dedicated high-priority worker.
php /app/artisan queue:work redis --tries=3 --timeout=120 --sleep=3 --memory=512 --max-jobs=1000 --max-time=3600 &
QUEUE_PID=$!
echo "Queue worker started (PID $QUEUE_PID, memory=512MB)."

# 8. Trap signals to clean up child processes on container shutdown.
#    This ensures the scheduler and queue worker don't become zombies
#    when Coolify stops the container.
if [ -n "$SCHEDULER_PID" ] || [ -n "$QUEUE_PID" ]; then
    trap "echo 'Shutting down container...'; kill $SCHEDULER_PID $QUEUE_PID 2>/dev/null; exit 0" SIGTERM SIGINT
fi

# 9. Start PHP-FPM and Nginx (foreground — keeps the container alive).
node /assets/scripts/prestart.mjs /assets/nginx.template.conf /nginx.conf && (php-fpm -y /assets/php-fpm.conf -d upload_max_filesize=50M -d post_max_size=50M -d memory_limit=512M & nginx -c /nginx.conf)
