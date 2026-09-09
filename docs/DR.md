# EXOSPACE DISASTER RECOVERY RUNBOOK (DR)

*Pinned by `InfrastructureTest` A-6: this file must exist and be reachable.
It describes the real stack — DigitalOcean + Coolify, Nixpacks (Node 22),
MySQL, Redis (predis), R2 off-site backups — and what to do when it breaks.*

---

## 1. The deployment, in one paragraph

Coolify builds the Laravel app from the repo with **Nixpacks** (`nixpacks.toml`,
Node 22 for the Vite build, PHP 8.3+ runtime) and runs it on **DigitalOcean**
as a single app + a MySQL database + a Redis instance (session, cache, queue;
`predis`). Persistent paths: `/app/storage/app/public` (user media),
`/app/storage/logs`, `/app/storage/app/private`. Daily monitored backups
(`RunMonitoredBackup`) are pushed to **local + Cloudflare R2**
(`BACKUP_DISKS=local,r2`). Error tracking is Sentry; ops alerts go to Slack.
`https://exospace.gallery` terminates TLS through Coolify's proxy.

## 2. Recovery objectives

| Class | Examples | Target |
|---|---|---|
| Secret store leak / key rotation | APP_KEY, provider keys | Rotate + redeploy ≤ 4 h |
| Data loss (DB) | bad migration, logic bug | RPO ≤ 24 h (last R2 backup), restore drill ≤ 1 h |
| App down | bad deploy, OOM, cert expiry | Rollback ≤ 15 min (Coolify previous deploy) |
| Venue identity incident | gallery/venue override pollution | Guarded migration + snapshot restore ≤ 30 min |

## 3. The three runbooks

### 3.1 Rollback a bad deploy
1. Coolify → App → **Redeploy previous successful build** (≤ 15 min).
2. If the deploy added migrations, check `php artisan migrate:status` on
   rollback; venue migrations are guarded (add-when-absent, admin values
   win) and their `down()` only removes what they added.
3. Hard-refresh one admin Live Preview panel — iframe bundle caches.

### 3.2 Restore the database (the real DR drill)
1. Coolify → stop the app (or enable maintenance: `php artisan down`).
2. Pull the newest verified R2 backup (`RunMonitoredBackup` writes a marker
   row in `backup_monitoring`; restore only from a row whose `cloud_ok=1`).
3. Restore: `gunzip < dump.sql.gz | mysql -h $DB_HOST -u $DB_USER -p $DB_DATABASE`.
4. `php artisan config:cache && php artisan route:cache && php artisan up`.
5. Smoke: `/health`, one public gallery, one venue walkthrough
   (`/venues/dark-museum/preview`), the ops overview. Compare
   `venue_templates.updated_at` values against the pre-incident value in the
   incident log — a stale venue row is the "identity replayed from an old
   snapshot" trap; the exporter cache re-keys on `updated_at`, so values
   land on the next request.

### 3.3 Secrets rotation
1. Rotate the leaked value in the provider dashboard first.
2. Update it in Coolify → Environment Variables → deploy (config is
   `config:cache`d at boot; never edit `.env` on the container).
3. Rotating `APP_KEY` invalidates sessions + encrypted payload columns —
   maintenance window only (the ops credentials ledger notes this per key).
4. Record the rotation in Ops → Credentials (audit + Slack + cadence clock).

### 3.4 Venue identity drift (a guarded pass silently skipped)
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
3. Verify: `php artisan test --filter=VenuePenthouseIterationTest` (includes
   the drifted-row replay), plus one live render of the venue preview.
4. If NO convergence migration exists for the drifted venue: write one
   following the 000008 pattern (semantic comparator + byte-verbatim chain
   bodies + loud logging + respected admin edits). Do NOT reseed production.


## 4. What is backed up where

| Asset | Cadence | Retention | Restore tool |
|---|---|---|---|
| MySQL dump (gzip) | daily (schedule worker) | per R2 lifecycle | §3.2 |
| `storage/app/public` (media) | daily, same job | per R2 lifecycle | rsync back into the volume |
| `storage/app/private` | daily, same job | per R2 lifecycle | rsync |
| Venue config history | **venue snapshots** (last 5 per venue, in-DB) | rolling | Venue Editor → restore |

## 5. Verification after any recovery

- `php artisan exospace:preflight` and `php artisan qa:doctor` green.
- `php artisan test --filter=VenueEnvironmentAuthorityTest` — the venue
  authority pins must pass against the restored data.
- One render check per family: white-cube / infinite-void / industrial-loft /
  dark-museum walkthroughs load and read as themselves.
- Slack digest resumes (a silent Slack is an outage signal too).
