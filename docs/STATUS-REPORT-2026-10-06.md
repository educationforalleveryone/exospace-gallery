# Exospace Production Check: Status Report

**Date:** Tuesday 6 October 2026
**Run window:** about 00:30–00:40 UTC
**Where:** Coolify app container (`/app`), production, Laravel 12.69.2, PHP 8.2.27
**Scope covered:** Phases 1–5 (baseline, runtime health, scheduler, alert probes, backups), plus GitHub CI log review (updates 2 to 5)
**Not yet covered:** Phase 6 (browser flows), Phase 7 (payments), a passing automated test suite run

## Overall verdict: NOT READY. 4 failures, 1 unconfirmed (CI is progressing)

| Area | Status |
|---|---|
| App, DB, migrations, Redis, queue, scheduler | ✅ Healthy |
| Backups (encryption policy) | ❌ FAILING |
| robots.txt via smoke test | ❌ FAILING |
| GitHub CI: install step (F8) | ✅ Fixed and deployed. Jobs now get past `composer install` |
| GitHub CI: MySQL migration (F9) | ✅ Fixed and deployed. The job now reaches and fails at "Run tests" (F12) |
| GitHub CI: Dusk ChromeDriver (F10) | ✅ Fixed and deployed. Browser connects; SmokeTest 10/10 pass |
| GitHub CI: green today | ✅ SQLite suite (2,774 tests, 12,819 assertions), Pint lint, Frontend Build, Preflight, Dependency Audit |
| GitHub CI: Dusk InteractionSweepTest (F11) | ❌ 13 of 16 failing. Fixes prepared (update 5) |
| GitHub CI: MySQL phpunit run (F12) | ❌ 10 errors + 193 failures. 133 come from one cause (no frontend build); fix prepared. About 60 real MySQL differences remain |
| Slack and Sentry delivery | ❓ Unconfirmed |

## Results by command

| # | Command | Result | Notes |
|---|---|---|---|
| 1 | `php artisan about` | ✅ | production, debug OFF, mysql, redis cache/session/queue, mail=resend, storage LINKED, Sentry enabled, Send Default PII disabled |
| 2 | `php artisan migrate:status` | ✅ | All migrations Ran, none pending (last: 2026_10_05_000001) |
| 3 | `curl /up` | ✅ | HTTP 200 |
| 4 | `curl /health` | ✅ | status ok: database, cache, queue (0 failed), billing_webhooks (0 failed), storage, coolify |
| 5 | `curl -sI /` (headers) | ✅ with notes | HTTPS, HSTS, CSP, nosniff, referrer-policy OK. Session cookie Secure, HttpOnly, SameSite=lax. See finding F6 |
| 6 | `curl /metrics?token=<METRICS_TOKEN>` | ⚠️ not a valid test | Placeholder token was sent, so the 404 only proves a wrong token is rejected. Re-run with the real token |
| 7 | `exospace:preflight` | ✅ | 0 critical, 4 warnings (see F4) |
| 8 | `ps aux` (worker) | ✅ | `queue:work redis` running (started 00:05; restarts hourly by design) |
| 9 | `qa:health` (x2) | ✅ | 7/7 healthy |
| 10 | `qa:run smoke --target=production` | ❌ | 6/7 passed. `robots.txt` failed: "HTTP 404 sitemap-ref=1" |
| 11 | `exospace:verify-data-integrity` | ✅ | 15/15 checks, 0 violations |
| 12 | `queue:failed` | ✅ | No failed jobs |
| 13 | Redis queue size (tinker) | ✅ | 0 |
| 14 | `scheduler.log` mtime + tail | ✅ | Updated every minute, jobs showing DONE |
| 15 | `schedule:list` | ✅ | 38 entries, matches `routes/console.php` |
| 16 | `schedule:test --name=scheduler-heartbeat` | ✅ | DONE |
| 17 | Slack normal, Slack critical, Sentry probes | ❓ | Commands ran without error. Delivery not yet confirmed |
| 18 | `exospace:backup db` | ❌ | Backup ran, but verification failed: archive not encrypted |
| 19 | `exospace:backup:verify` | ❌ | local and r2 both `Encrypted: no` |
| 20 | `exospace:backup:restore --list --disk=r2` | ✅ | 29 archives listed, newest 2026-10-06-00-35-52 |
| 21 | `exospace:backup:restore --disk=r2 --only-db` (plan) | ❌ (correct refusal) | Refused to plan against an unverified artifact. This is the safety working |

## Findings

### F1. CRITICAL: backups are unencrypted although BACKUP_PASSWORD is set
- Newest backup (2026-10-06-00-35-52.zip) exists on local and R2, and the verifier rejects it as not encrypted.
- It contains a full DB dump (users, hashes, MFA data), so it is sitting readable in R2 and on local disk.
- Preflight raises no "BACKUP_PASSWORD is empty" advisory, so the password appears to reach config. The cause is therefore somewhere between config and the zip step.
- **Unknown:** whether the older nightly backups are also unencrypted. `backup:verify` only checks the newest per disk.
- **Next:** see the diagnostic steps below.

### F2. robots.txt smoke failure
- `qa:smoke` got HTTP 404 on `/robots.txt` even though the body contained a Sitemap line. That combination is odd.
- nginx is designed to pass `/robots.txt` through to Laravel (`RobotsController`), so the missing static file is expected. The preflight warning about `public/robots.txt` is likely a false alarm.
- **Next:** `curl -i https://exospace.gallery/robots.txt`, then from inside the container `curl -i http://127.0.0.1/robots.txt -H 'Host: exospace.gallery'`. Compare the two. If the first is 404 and the second 200, the cause is Cloudflare or Coolify's proxy.

### F3. Slack and Sentry delivery unconfirmed
- Need a human check of the normal Slack channel, the critical Slack channel and the Sentry project.
- Also check Slack for an alert from the failed backup run (F1).

### F4. Preflight warnings (low priority)
- `redis` extension not loaded: expected, the app uses predis. Ignore.
- `imagick` not loaded: expected, the app uses GD. Ignore.
- `upload_max_filesize=2M`, `post_max_size=8M` seen from the CLI. The CLI may use different ini values than php-fpm. This must be confirmed with a real 49 MB upload through the browser.
- `public/robots.txt` missing: see F2.

### F5. Config is CACHED in the running container
- `about` shows Config CACHED, but `nixpacks.toml` and `docker-start.sh` say config:cache must never run (it freezes .env values).
- Something is caching config, possibly outside this repo's pipeline. A stale cache means env changes in Coolify may not take effect.
- **Next:** `ls -la bootstrap/cache/config.php`, and compare its modification time with the last deploy.

### F6. Security header duplication (low)
- `x-frame-options` appears twice with conflicting values (DENY and SAMEORIGIN) and `x-content-type-options` appears twice. `frame-ancestors 'none'` in the CSP covers it in modern browsers, but the headers should be set in one place.
- CSP allows `'unsafe-eval'` in script-src. Check whether the 3D engine really requires it.
- HSTS has no `includeSubDomains`. This is optional.

### F7. Minor
- Sentry Release is NOT SET, so errors cannot be tied to a deploy.
- `scheduler-heartbeat` age shows slightly negative (about -0.1 min). Cosmetic.
- Weekly files backup dropped from about 63 MB (until 2026-09-13) to about 37 MB (from 2026-09-20). Confirm that was an intentional change.
- Security: all credentials were pasted into a chat. Rotation is **not confirmed**.

### F8. GitHub CI fails in every job at `composer install` (fix prepared)
- **Symptom:** `composer install` ends with `FATAL: TRUSTED_PROXIES is set to '(empty)' in production`, raised by `AppServiceProvider::assertTrustedProxiesConfigured()` during `artisan package:discover`.
- **Cause:** composer's post-install step boots Laravel, but the workflow only creates `.env` in a *later* step. With no `.env` and no `APP_ENV`, Laravel defaults to `production`, so your own production safety guard aborts the install. This is a workflow-order problem, not an app bug. The guard is working as designed and should stay.
- **Scope:** 5 jobs in `ci.yml` (PHP Tests SQLite, PHP Lint, Preflight, PHP Tests MySQL, Dusk) and 1 in `test-profiles.yml`.
- **Fix:** set `APP_ENV: testing` on each `composer install` step. See `ci-fix/ci-fix.patch`.
- **Side note:** the `[OpsEventIngestor] failed: ... Connection refused / Access denied` lines are logged non-fatally. Harmless noise.
- **Also fixed:** composer warned that `ReleaseIntelligenceTest` and `ControlCenterUiTest` declare namespace `Tests\Feature\ControlCenter` but sat in `tests/Feature/`. Both moved to `tests/Feature/ControlCenter/`. PHPUnit scans by directory so they would still have run, but composer was skipping them for the optimized autoloader.
- **Verification status:** VERIFIED by your next CI run (update 3). The MySQL job reached `migrate` and the Dusk job reached the tests, so the install step now passes. The remaining failures are the separate problems F9 and F10 below.

### F9. MySQL CI job: migration `create_retention_snapshots_table` fails (fix prepared)
- **Symptom:** `php artisan migrate --force` in the PHP Tests (MySQL) job stops at `2026_08_25_151000_create_retention_snapshots_table.php:15` with a QueryException. The pasted log did not include the SQL error text itself.
- **Diagnosis (strong, but inferred):** the unique index on `(cohort_week_start, week_index, captured_at)` gets the auto-generated name `retention_snapshots_cohort_week_start_week_index_captured_at_unique`, which is **67 characters**. MySQL limits identifiers to 64 (error 1059, "Identifier name is too long"). SQLite has no such limit, which is why the SQLite job never saw it. A scan of all migrations found no other auto-generated unique/index name over 64.
- **Fix:** give both indexes explicit short names (`retention_snap_cohort_week_captured_uq`, `retention_snap_week_captured_idx`).
- **Production impact:** none expected. Production's `migrate:status` shows this migration already Ran, so it will not run again. Only fresh databases (CI, new environments, restores) use the new names.
- **Open question:** how production ever created this table with a 67-character index name is not explained (maybe it was created by an earlier version of the migration). Not blocking, but worth knowing.
- **Confirm:** the next MySQL job should get past `migrate`. If it fails again, paste the first `SQLSTATE[...]` line.

### F10. Dusk CI job: ChromeDriver not reachable (fix prepared)
- **Symptom:** all 26 Dusk tests fail with `Failed to connect to localhost port 9515 ... Couldn't connect to server`.
- **Diagnosis (probable, not confirmed):** the old step ran `chromedriver &` then `sleep 2`, which gives no signal if the driver crashed or was not yet listening, and `localhost` can resolve to IPv6 while ChromeDriver listens on IPv4. The log did not show whether ChromeDriver started, so I cannot say which applies.
- **Fix:** start ChromeDriver on an explicit port with a log file and a 30-second readiness check on `http://127.0.0.1:9515/status` (fails with the log if it never answers); same readiness check for `artisan serve`; `DuskTestCase` now defaults to `127.0.0.1`; and a new "Dusk diagnostics (on failure)" step prints the ChromeDriver, server and Laravel logs.
- **Confirm:** if the driver still does not start, the failure will now print the reason (for example a Chrome/ChromeDriver version mismatch). Paste that output.
- **Expect:** once the browser connects, the real page tests run for the first time, so new failures are likely.

### F11. Dusk `InteractionSweepTest`: 13 of 16 fail (fixes prepared, update 5)
Full log read. These are mostly bugs in the test file, not in the app:
- **`waitForVisible` does not exist in Dusk** (5 tests: upgrade modal x2, feedback widget, dropdowns, mobile menu). Replaced with `waitFor`, which already waits for the element to be displayed (15 call sites).
- **`keys('body', ...)` never matches** ("Unable to locate element: body body"; command palette, turbo back/forward). Dusk prefixes every selector with `body`. Replaced with a `pressBody()` macro that sends keys to the real `<body>` element (11 call sites).
- **Create-gallery "handler not bound":** the injected script returned nothing (the function was called but its value was never returned), so the test saw `null`. Added `return`.
- **MFA tests (super admin, plan select) crashed with "Invalid characters in the base32 string":** the factory stores the secret with `encrypt()` (serialised payload) but the test decrypted it with `Crypt::decryptString()`, leaving a serialised string. Now uses `decrypt()`, as `MfaController` does.
- **Team member "Did not see Galleries":** in a team workspace the page heading is the team name, not "My Galleries". The test now expects the team name.
- **Still unexplained (2 tests):** "pro user pages are clean" and "missing image falls back" both time out after 15 s waiting for every `<img>` to finish loading. The log does not say which page or image stalled. `settlePage()` now reports the URL and the pending image sources when it times out. Expect these two to still fail next run, but with an actionable message.
- **Confirmed fine:** ChromeDriver and `artisan serve` start correctly now; SmokeTest passes 10/10.
- **Pint risk:** I could not run Pint here. If the PHP Lint job flags `InteractionSweepTest.php`, run `vendor/bin/pint tests/Browser/InteractionSweepTest.php` and commit.

### F12. MySQL job: 10 errors and 193 failures (partly fixed, update 5)
Full log read (2,774 tests, 12,200 assertions). The same suite passes completely on SQLite.
- **133 failures: one cause.** The MySQL job never builds the frontend, so `public/build/manifest.json` does not exist and every page render throws `ViteManifestNotFoundException` (HTTP 500). The SQLite job has the build step; the MySQL job did not. Fixed by adding Node setup, `npm ci` and `npm run build`.
- **4 failures: a genuine MySQL 8 bug in app code.** `InternalLinkingService::relatedGalleries()` runs `SELECT DISTINCT artist_id ... ORDER BY position_order` (the images relation carries that ordering). MySQL 8 rejects it with error 3065. Fixed with `->reorder()`. **Production note:** this only fires when the gallery's images are not already loaded. Check whether production is MySQL 8 (`SELECT VERSION(), @@sql_mode;`); MariaDB and relaxed `sql_mode` accept the query.
- **About 60 remaining failures are real MySQL-versus-SQLite differences** (counts approximate; a few more are Vite-related and will clear with the build step):
  - ~44 `Venue*Test`: MySQL JSON columns reorder object keys (shortest key first, then alphabetical), so order-sensitive `assertSame`/string comparisons fail. This is a test-assertion issue. Separately, several migrations compare raw JSON strings to decide whether to rewrite a row; that is worth a closer look.
  - 5 `AdminAuditChainTest`: chain verification fails on MySQL (cause not yet known; needs its own look because it guards audit-log integrity).
  - 4 tests create a user pointing at a team that does not exist; MySQL's foreign key rejects it (that state cannot exist in production on MySQL).
  - 2 tests that only make sense on non-MySQL databases, 1 test using SQLite-only `PRAGMA`, 1 query on `password_reset_tokens.id` (that table has no `id`).
  - About 5 others (OpsCredentialInventory, Analytics perf beacon, registration rollback, digest-recipient concurrency, artist covers query count, webhook ledger).
- **Recommendation:** push update 5, re-run, and send the new MySQL failure list. The real remaining set will be smaller and exact. Then decide per group whether to fix the app, fix the test, or skip on MySQL. Until then, consider the SQLite job as the merge gate and MySQL as informational.

### How to send CI logs
- Attach the log file directly in the chat. The GitHub run page does not expose logs without sign-in, even for this public repo.
- Whole run: Actions, open the run, the `...` menu at top right, "Download log archive".
- One job: open the job, gear icon at top right, "View raw logs", save as .txt.
- If the file is huge, filter locally (PowerShell): `Select-String -Path log.txt -Pattern '^\d+\) |FAILURES!|ERRORS!|Tests:|Exception' -Context 0,4 | Out-File short.txt`

## Diagnostic steps for F1 (run in the container)

```bash
# 1. Does the runtime see the password, and which encryption mode?
php artisan tinker --execute="var_dump(strlen((string)config('backup.backup.password')), config('backup.backup.encryption'));"

# 2. Does PHP's zip support AES?
php -r 'var_dump(defined("ZipArchive::EM_AES_256"));'
php --ri zip

# 3. Inspect the actual archive (encryption_method 0 = none, 259 = AES-256)
php -r '$z=new ZipArchive; $z->open("/app/storage/app/private/Exospace Backup/2026-10-06-00-35-52.zip"); print_r($z->statIndex(0));'

# 4. Are the older archives encrypted?
php artisan exospace:backup:verify --disk=r2 --file=2026-10-05-01-00-18.zip
php artisan exospace:backup:verify --disk=r2 --file=2026-10-04-01-30-15.zip
```

Expected timing: the scheduled `exospace:backup db` runs at 01:00 UTC and will likely fail the same way and raise a critical Slack alert until F1 is fixed.

## Open actions

| Priority | Action | Status |
|---|---|---|
| P0 | Apply F8 CI fix | Done, verified |
| P0 | Update-3 zip (F9 migration, F10 ChromeDriver) | Done, verified |
| P0 | Apply update-5 zip (Dusk test fixes, MySQL frontend build, MySQL-8 query fix), push, send the new MySQL failure list and Dusk output | Open, fix prepared |
| P1 | Check production DB version and sql_mode (`SELECT VERSION(), @@sql_mode;`) | Open |
| P0 | Rotate all credentials that were pasted into chat | Not confirmed |
| P0 | Diagnose and fix backup encryption (F1), re-run `backup db` and `backup:verify` until both disks show Encrypted: yes | Open |
| P0 | After fix, delete or replace the unencrypted 2026-10-06-00-35-52.zip on R2 and local, and decide what to do about older unencrypted archives | Open |
| P1 | Fix or explain robots.txt 404 (F2), then re-run `qa:run smoke --target=production` | Open |
| P1 | Confirm Slack normal, Slack critical and Sentry messages arrived (F3) | Open |
| P1 | Real 49 MB upload test in the browser (F4) | Open |
| P2 | Investigate cached config (F5) | Open |
| P2 | Clean up duplicate security headers (F6) | Open |
| P2 | Set Sentry release; re-test `/metrics` with the real token | Open |

## Not yet done

- Phase 6: browser flows (signup/email, login, MFA, gallery create/upload/publish, maintenance drill, mobile 3D)
- Phase 7: payments (2Checkout test order, webhook, invoice, refund)
- A passing run of the full automated suite (`phpunit`) in GitHub Actions or locally. Blocked until F11 and F12 are fixed (SQLite suite already passes)
- Restore drill on a separate staging app
- Morning digest (08:15 UTC) received

## Changelog

| Date (UTC) | Update |
|---|---|
| 2026-10-06 ~00:40 | Initial report. Phases 1–5 run. Two failures (F1, F2), one unconfirmed (F3) |
| 2026-10-06 (update 2) | Reviewed two failing GitHub CI logs. Added F8 (all jobs fail at `composer install` because `.env` is created after it). Prepared workflow patch plus test file move. Not yet pushed or verified |
| 2026-10-06 (update 3) | F8 fix deployed and verified (jobs pass `composer install`). New CI failures found: F9 (MySQL migration, 67-char index name) and F10 (Dusk, ChromeDriver unreachable). Fixes prepared, not yet pushed or verified |
| 2026-10-06 (update 4) | F9 and F10 fixes verified by CI: MySQL migrations pass, Dusk connects (SmokeTest 10/10). New failures: F11 (Dusk sweep, 13 of 16 failing) and F12 (MySQL phpunit). Root causes not yet known; waiting on log excerpts |
| 2026-10-06 (update 5) | Read the full log archive. SQLite suite 2,774 tests pass; Pint, Frontend Build, Preflight, Dependency Audit pass. Dusk: 6 root causes found in the test file (waitForVisible, keys('body'), missing return, decryptString vs decrypt, team heading) plus diagnostics for 2 unexplained timeouts. MySQL: 133 failures from missing frontend build, 4 from a real MySQL-8 query bug (fixed), about 60 genuine MySQL differences to triage. Fixes prepared, not yet pushed |
