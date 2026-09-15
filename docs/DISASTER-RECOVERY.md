# EXOSPACE DISASTER RECOVERY RUNBOOK (DR)

*Pinned by `InfrastructureTest`: this file must exist and be reachable.
It describes the real stack — DigitalOcean + Coolify, Nixpacks (Node 22),
MySQL, Redis (predis), R2 off-site backups — and what to do when it breaks.*

---

## 1. The deployment, in one paragraph

Coolify builds the Laravel app from the repo with **Nixpacks** (`nixpacks.toml`,
Node 22 for the Vite build, PHP 8.3+ runtime) and runs it on **DigitalOcean**
as a single app + a MySQL database + a Redis instance (session, cache, queue;
`predis`). Persistent paths: `/app/storage/app/public` (user media),
`/app/storage/app/private` (invoices and other private documents),
`/app/storage/logs`. Scheduled commands run through a Coolify scheduled task
(`php artisan schedule:run >> /app/storage/logs/scheduler.log 2>&1`,
`BYPASS_SCHEDULER=true` in the app container). Monitored backups
(`RunMonitoredBackup`) write **encrypted zip archives** to **local +
Cloudflare R2** (`BACKUP_DISKS=local,r2`, bucket from `R2_BUCKET`), and every
backup run verifies the stored artifacts before stamping its heartbeat.
Error tracking is Sentry; ops alerts go to Slack.
`https://exospace.gallery` terminates TLS through Coolify's proxy.

## 2. Recovery objectives

| Class | Examples | Target |
|---|---|---|
| Secret store leak / key rotation | APP_KEY, provider keys | Rotate + redeploy ≤ 4 h |
| Data loss (DB) | bad migration, logic bug | RPO ≤ 24 h (daily DB backup), restore drill ≤ 1 h |
| Data loss (media/invoices) | storage volume loss, bad deploy cleanup | RPO ≤ 7 d (weekly files backup) |
| App down | bad deploy, OOM, cert expiry | Rollback ≤ 15 min (Coolify previous deploy) |
| Venue identity incident | gallery/venue override pollution | Guarded migration + snapshot restore ≤ 30 min |

## 3. The runbooks

### 3.1 Rollback a bad deploy
1. Coolify → App → **Redeploy previous successful build** (≤ 15 min).
2. If the deploy added migrations, check `php artisan migrate:status` on
   rollback; venue migrations are guarded (add-when-absent, admin values
   win) and their `down()` only removes what they added.
3. Hard-refresh one admin Live Preview panel — iframe bundle caches.

### 3.2 Restore the database (the real DR drill)

**What exists to restore from:** daily `exospace:backup db` (01:00) produces a
spatie/laravel-backup archive — **an AES-256-encrypted zip** named
`YYYY-MM-DD-HH-MM-SS.zip` — stored on every disk in `BACKUP_DISKS`
(`local` → `/app/storage/app/private/Exospace Backup/`, `r2` →
`s3://<R2_BUCKET>/Exospace Backup/`). Inside the zip:
`db-dumps/mysql-<database>.sql` (plain SQL, produced by `mysqldump`).

**Step 1 — pick a recovery point.**
```bash
php artisan exospace:backup:restore --disk=r2 --list   # newest first, with sizes + timestamps
php artisan exospace:backup:verify  --disk=r2          # integrity-check the newest artifact per disk
```
An artifact is usable when `exospace:backup:verify` reports OK — that means
the zip opens, decrypts with `BACKUP_PASSWORD`, and its dump contains real
schema (`CREATE TABLE`). Backups that failed verification never stamp the
`exospace:backup:db` heartbeat, so Master Control → Backup health and the
Slack heartbeat alerts tell you the same thing from the other side.

**Step 2 — plan the restore (no changes yet).**
```bash
php artisan exospace:backup:restore --disk=r2 --only-db
```
This downloads the archive (remote disks are streamed to a temporary copy),
verifies it, and prints exactly what would happen: source file, target
connection/database, dump entry.

**Step 3 — execute.**
```bash
php artisan down                                   # maintenance window
php artisan exospace:backup:restore --disk=r2 --only-db --execute --confirm=RESTORE
```
The command extracts the dump, then pipes it into the `mysql` client using
the current `DB_*` connection settings (the password travels via the
`MYSQL_PWD` environment variable — it never appears in the process list or
in logs). Without `--confirm=RESTORE` (or typing `RESTORE` at the prompt)
it refuses to run. Every database restore is written to the admin audit log
(`backup.restored`) and to the ops log.

**Manual alternative** (e.g. mysql client unavailable in the app container):
```bash
php artisan tinker --execute="echo rescue(fn () => app(App\Services\BackupArtifactVerifier::class)->verify('r2', 'Exospace Backup/<file>.zip')->problemSummary() ?: 'OK');"
7z x -p"$BACKUP_PASSWORD" <file>.zip -o/tmp/restore db-dumps/   # p7zip handles AES-256
mysql -h $DB_HOST -u $DB_USERNAME -p $DB_DATABASE < /tmp/restore/db-dumps/mysql-<database>.sql
```
(`unzip -P` cannot open AES-256 archives — use `7z` or the artisan command.)

**Step 4 — reconnect + validate.**
```bash
php artisan config:clear && php artisan cache:clear
php artisan migrate:status
php artisan up
```

### 3.3 Restore media / private documents

The weekly `exospace:backup files` (Sunday 01:30) archives everything under
`storage/app/public` (galleries, artwork, thumbnails, venue assets, audio,
branding) and `storage/app/private` (invoices, other private documents),
excluding the backup destination itself and the disposable
`storage/app/private/control-center` QA artifacts. Entries are stored rooted
at the deploy directory (`app/storage/app/public/...` on the /app image).

```bash
php artisan exospace:backup:restore --disk=r2 --only-files            # plan
php artisan exospace:backup:restore --disk=r2 --only-files --execute --confirm=RESTORE
```
Extraction is additive: files in the archive overwrite their existing path,
files created *after* the backup are never deleted, and any entry attempting
path traversal (`../`) is refused, not extracted. Afterwards re-run
`php artisan storage:link` (per-container symlink) and spot-check one
gallery image over HTTPS.

Manual alternative: `7z x -p"$BACKUP_PASSWORD" <file>.zip -o/tmp/media`, then
`rsync -a /tmp/media/app/storage/app/public/ /app/storage/app/public/`
(trailing slashes matter; `-a` preserves permissions/ownership).

### 3.4 Full application recovery (lost/destroyed environment)

Minimum sequence to rebuild Exospace from nothing:

1. **Source** — deploy the repo from Coolify (same build: Nixpacks, Node 22,
   PHP 8.3+, `phpPackages.mariadb` provides `mysqldump`; the container start
   runs `storage:link`, `migrate --force`, preflight).
2. **Environment/secrets** — recreate the Coolify environment variables.
   **Secrets are NOT in the backups** (intentionally): `APP_KEY`, DB
   credentials, `R2_*`, `RESEND_API_KEY`, 2Checkout keys, Slack webhooks,
   `BACKUP_PASSWORD`, `COOLIFY_API_TOKEN`, `SENTRY_LARAVEL_DSN`, etc. must be
   retrieved from the Coolify config / provider dashboards / the ops
   credentials ledger. Restoring an old `APP_KEY` decrypts old sessions and
   encrypted DB columns — prefer reusing the existing value from the ledger.
3. **Database service** — provision MySQL, then §3.2.
4. **Persistent volume** — mount `/app/storage` (or restore media first:
   §3.3), then run `php artisan storage:link`.
5. **Redis** — no restore needed: cache/session/queue/locks are reconstructible
   state (sessions re-login, queues re-run, caches warm themselves).
6. **Verify** — §5.

### 3.5 Secrets rotation
1. Rotate the leaked value in the provider dashboard first.
2. Update it in Coolify → Environment Variables → deploy (config is
   `config:cache`d at boot; never edit `.env` on the container).
3. Rotating `APP_KEY` invalidates sessions + encrypted payload columns —
   maintenance window only (the ops credentials ledger notes this per key).
4. Record the rotation in Ops → Credentials (audit + Slack + cadence clock).

### 3.6 Venue identity drift (a guarded pass silently skipped)
*Class incident: Luxury Penthouse rendered the retired v1.0.0 "Rooms" body
(blue/warm preset-tower skyline) while `migrate:status` reported every venue
migration RAN and the row's `version` string said `3.0.0`.*

**Mechanism:** a SuperAdmin venue-template save re-serializes
`visual_config`/`lighting_fixtures` through a browser JSON round-trip —
integral floats lose their `.0` (`5.0` → `5`) and every object's keys are
re-sorted alphabetically. Guarded chain migrations compare the decoded row
with PHP `===` (type- AND order-sensitive), so `int(5) === float(5.0)` is
false and the swap silently skips — for every later migration in the chain.

**Detection:**
- `php artisan migrate:status` shows RAN but the venue renders an old body.
- Decisive on-site check: open the public preview page and diff the served
  `window.GALLERY_DATA.venueConfig.visual_config.structure` against the
  seeder body (descriptor COUNT first: 61 = v3.0.0 Penthouse; 17/40/47 =
  a skipped chain). Verify `wall_height`/`wing_heights` versus the
  descriptor list — a mismatch (v3 scalars + v1 structure) is this drift.
- Version string is NOT evidence — it updates under its own guard.

**Repair:**
1. Run the corrective convergence pass for the affected venue —
   `php artisan migrate --force` (for Luxury Penthouse this is
   `2026_09_09_000008_luxury_penthouse_convergence`, which matches the row
   SEMANTICALLY against the known chain bodies and converges only those;
   custom/admin structures are logged and left untouched). Deploy logs carry
   `[luxury-penthouse-convergence]` lines for every decision.
2. No manual cache clear: the exporter cache key includes the row contents
   hash + `updated_at`, so the converged row serves on the next request.
3. Verify: `php artisan test --filter=VenuePenthouseTest` (includes
   the drifted-row replay), plus one live render of the venue preview.
4. If NO convergence migration exists for the drifted venue: write one
   following the 000008 pattern (semantic comparator + byte-verbatim chain
   bodies + loud logging + respected admin edits). Do NOT reseed production.

### 3.7 The Media Wall pass (000009) — "screen/fixture looks wrong" checklist

The v3.1.0 pass adds viewer-drawn furniture (media console + bezel + Now
Showing screen) and two anchored fixtures. Symptoms and their first moves:

- **Media screen missing (console/bezel render, no glowing display):**
  the screen needs `visual_config.media_wall` AND `buildMediaWall` in the
  deployed JS bundle. Check the payload carries `media_wall` (venue-owned
  key — a gallery override cannot strip it); check the browser console for
  `[exospace] media_wall: bezel mesh … not found` (descriptor pruned by an
  admin save — the JS intentionally skips rather than conjure a floating
  screen; re-run `php artisan migrate --force`, 000009 re-adds absent ids).
- **Screen shows the generic wordmark, no artwork thumbnail:** the
  featured-artwork `Image` failed (404 on `urls.large`) — the typographic
  idle is the designed fallback, not a bug. Check the gallery's first image
  conversion exists.
- **Media wall / picture light stands in the wrong place after an admin
  edit:** the per-descriptor semantic guards SKIPPED an admin-customised
  descriptor by design — the deploy log says
  `[luxury-penthouse-media-wall] descriptor '…' is admin-customised … left
  untouched`. Either accept the admin state or revert the descriptor in the
  Venue Editor; the migration never fights a live edit.
- **Lamp/sofa/art-wall regression after rollback:** 000009.down() restores
  the exact v3.0.0 bodies; if a row was admin-edited between up() and
  down(), the guards leave those descriptors at the admin state (logged).
- Offline replay:
  `python3 scripts/validate_luxury_penthouse_media_wall_migration.py` (no PHP needed).

## 4. What is backed up where

| Asset | Cadence | Retention | Restore tool |
|---|---|---|---|
| MySQL dump (`db-dumps/mysql-<db>.sql` inside encrypted zip) | daily 01:00 | §4.1 | §3.2 |
| `storage/app/public` (media) + `storage/app/private` (invoices, private docs) | weekly Sun 01:30 | §4.1 | §3.3 |
| Venue config history | **venue snapshots** (last 5 per venue, in-DB) | rolling | Venue Editor → restore |

Destination: every disk in `BACKUP_DISKS` — `local`
(`/app/storage/app/private/Exospace Backup/`, survives container restarts,
does NOT survive storage-volume loss) and `r2`
(`s3://<R2_BUCKET>/Exospace Backup/`, the off-site disaster-recovery copy).
Archives are AES-256 encrypted (`BACKUP_PASSWORD`).

### 4.1 Retention (deterministic)

`exospace:backup clean` runs daily at 02:00 and applies the spatie
DefaultStrategy **per disk**:

- keep every backup for **7 days**
- keep daily backups for **16 days**
- keep weekly backups for **8 weeks**
- keep monthly backups for **4 months**
- keep yearly backups for **2 years**
- prune the oldest backups once total size per disk exceeds
  `BACKUP_MAX_STORAGE_MB` (**5000 MB** default) — the strategy always
  retains at least the newest usable recovery point

## 5. Verification after any recovery

- `php artisan exospace:backup:verify` green (backups still land and are
  usable) and `php artisan exospace:preflight` + `php artisan qa:doctor` green.
- `php artisan test --filter=VenueEnvironmentAuthorityTest` — the venue
  authority pins must pass against the restored data.
- One render check per family: white-cube / infinite-void / industrial-loft /
  dark-museum walkthroughs load and read as themselves.
- Spot-check one invoice download (`/billing/invoice/{id}`) after a media
  restore — invoices live on the private disk, not in the MySQL database.
- Slack digest resumes (a silent Slack is an outage signal too).

## 6. How backup failures surface

| Layer | Signal |
|---|---|
| `exospace:backup db\|files\|clean` | non-zero exit → immediate Slack alert (deduped per type), `backup.failed` audit row, ops log entry |
| Artifact verification (`BackupArtifactVerifier`, runs inside the monitored command) | spatie exited 0 but the stored zip is corrupt/unreadable/undecryptable/implausible → Slack alert "produced an unusable artifact", heartbeat NOT stamped |
| Heartbeat monitor (`OperationalAlertService::checkJobHeartbeats`, every 5 min) | `exospace:backup:db` stale > 36 h / `:files` > 192 h / `:clean` > 36 h → critical/missing-job alerts |
| Backup-disk watch (`checkBackupHealth`, every 5 min) | no zip on a configured disk, or newest zip older than 26 h → critical alert per disk |
| Scheduler watchdog (`scheduler.log` mtime, every 5 min) | scheduler loop dead → critical alert |
| spatie's own mail channel | `BackupHasFailedNotification` → `BACKUP_NOTIFICATION_EMAIL` |

Everything rides the existing Slack webhooks (`OPERATIONAL_ALERT_WEBHOOK`,
`OPERATIONAL_ALERT_CRITICAL_WEBHOOK`) + the ops log channel + Sentry — there
is no separate monitoring system to watch.

## 7. Scenario index

| Scenario | Answer |
|---|---|
| A — Database corruption | §3.2 (daily RPO, verified artifacts, plan-mode restore) |
| B — Application server loss | §3.4 (redeploy from Coolify + §3.2/§3.3 data restores) |
| C — Persistent storage loss | §3.3 for media/invoices (RPO ≤ 7 d), §3.2 for DB |
| D — Accidental destructive DB operation | §3.2 — newest pre-incident recovery point; daily backups at 01:00 bound the loss window |
| E — Backup destination failure | backup:run fails its run → Slack + audit; `exospace:backup:verify` distinguishes per disk; retention cap per disk in §4.1 |
| F — Scheduler failure | scheduler.log mtime watchdog + heartbeat staleness alerts (§6) |
