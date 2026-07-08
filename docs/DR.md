# Disaster Recovery Runbook (A-6 FIX — Iteration-006)

## Overview

This document describes the backup and restore procedures for Exospace Gallery.
It should be tested quarterly in a staging environment.

## Backup Strategy

### Automated Backups (spatie/laravel-backup)

| Type | Schedule | Command | Retention |
|------|----------|---------|-----------|
| Database | Daily at 1am | `backup:run --only-db` | 7 daily + 4 weekly + 12 monthly |
| Files (uploads) | Weekly Sun 1:30am | `backup:run --only-files` | 4 weekly + 12 monthly |
| Cleanup | Daily at 2am | `backup:clean` | Removes old backups per retention policy |

**Backup destination:** Local disk (`storage/app/Laravel-backup/`).
**RPO (Recovery Point Objective):** 24 hours for DB, 7 days for files.

### Manual Backups

```bash
# Full backup (DB + files)
php artisan backup:run

# DB only
php artisan backup:run --only-db

# Files only
php artisan backup:run --only-files
```

### DigitalOcean Snapshots (infrastructure-level)

In addition to spatie/laravel-backup, enable:
- DO Managed Database automated backups (if using Managed MySQL)
- DO Volume snapshots (weekly)
- DO Droplet snapshots (monthly)

## Restore Procedure

### Database Restore

1. **Find the backup file:**
   ```bash
   ls -la storage/app/Laravel-backup/
   ```

2. **Extract the backup:**
   ```bash
   cd storage/app/Laravel-backup/
   unzip <backup-file>.zip -d /tmp/restore/
   ```

3. **Restore the database:**
   ```bash
   # For MySQL:
   mysql -u <user> -p <database> < /tmp/restore/db-dumps/mysql-exospace.sql

   # Or if the dump is compressed:
   gunzip < /tmp/restore/db-dumps/mysql-exospace.sql.gz | mysql -u <user> -p <database>
   ```

4. **Verify the restore:**
   ```bash
   php artisan tinker --execute="echo User::count() . ' users, ' . Gallery::count() . ' galleries';"
   ```

### File Restore

1. **Extract the backup:**
   ```bash
   unzip <backup-file>.zip -d /tmp/restore/
   ```

2. **Restore the files:**
   ```bash
   cp -r /tmp/restore/storage/app/public/* storage/app/public/
   chown -R www-data:www-data storage/app/public/
   ```

3. **Verify:**
   ```bash
   ls -la storage/app/public/galleries/
   ```

### Full Restore (New Server)

1. **Provision a new server** (same specs as the original).
2. **Deploy the application** via Coolify (git push → auto-deploy).
3. **Restore the database** (see above).
4. **Restore the files** (see above).
5. **Run migrations** (in case the backup is from an older schema version):
   ```bash
   php artisan migrate --force
   ```
6. **Clear caches:**
   ```bash
   php artisan config:clear
   php artisan route:clear
   php artisan view:clear
   php artisan cache:clear
   ```
7. **Rebuild the sitemap cache:**
   ```bash
   php artisan schedule:run
   ```
8. **Verify the site is functional** — check /health, /status, browse a gallery.

## RTO (Recovery Time Objective)

- **Database restore:** ~15 minutes (for a 1GB DB dump)
- **File restore:** ~30 minutes (for 10GB of uploads)
- **Full server restore:** ~1 hour (provision + deploy + restore + verify)

## Rollback Procedure (Bad Deploy)

1. In Coolify, click "Rollback" on the deployment (deploys the previous image).
2. If the previous image has a different DB schema, run:
   ```bash
   php artisan migrate:rollback --step=1
   ```
3. Clear caches:
   ```bash
   php artisan config:clear && php artisan route:clear && php artisan view:clear && php artisan cache:clear
   ```
4. Verify the site is functional.

## Contact Tree

- **Founder/CTO:** [fill in]
- **Hosting:** DigitalOcean support (https://cloud.digitalocean.com/support)
- **Deployments:** Coolify dashboard
- **Error monitoring:** Sentry dashboard
- **Email:** Resend dashboard

## Quarterly DR Drill

1. Provision a staging server.
2. Take a backup on production.
3. Restore the backup on staging.
4. Verify all data is present (user count, gallery count, recent transactions).
5. Verify the staging app is functional (login, create gallery, view gallery).
6. Document any issues encountered.
7. Update this runbook with lessons learned.
