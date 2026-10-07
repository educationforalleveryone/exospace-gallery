# Exospace Production Check: Status Report

**Date:** Tuesday 6 October 2026
**Run window:** about 00:30–00:40 UTC (phases 1–5), CI log reviews through update 11
**Where:** Coolify app container (`/app`), production, Laravel 12.69.2, PHP 8.2.27
**Scope covered:** Phases 1–5 (baseline, runtime health, scheduler, alert probes, backups), plus GitHub CI log review (updates 2 to 11)
**Not yet covered:** Phase 6 (browser flows), Phase 7 (payments); a fully green unit-suite CI run has now happened (update-9 run)

## Overall verdict: NOT READY, but close. The update-10 run is the best of the engagement: BOTH unit suites fully green for the second consecutive run (SQLite + MySQL, 2,774 tests each, only legitimate skips) and Dusk improved to 24 passed / 2 failed with the web server alive for the entire run (zero supervisor restarts). Both remaining failures share one new finding — an intermittent server-side 500 on POST /mfa/verify (Carbon TypeError, F31), exposed by update 10's F29 error capture; update 11 ships the diagnostics that will pin its exact caller from the next run's logs

| Area | Status |
|---|---|
| App, DB, migrations, Redis, queue, scheduler | ✅ Healthy |
| Backups (encryption policy) | ❌ FAILING |
| robots.txt via smoke test | ❌ FAILING |
| GitHub CI: install, MySQL migrations, Dusk ChromeDriver (F8–F10) | ✅ Fixed and verified by later runs |
| GitHub CI: Pint, Frontend Build, Preflight, Dependency Audit | ✅ Green |
| GitHub CI: SQLite suite | ✅ **Fully green, confirmed twice in a row (after updates 9 and 10)** (2,774 tests, 0 failures, 1 legitimate skip; was 1 after update 7, 187 after update 6, 0 at baseline) |
| GitHub CI: MySQL suite (F12–F24) | ✅ **Fully green after updates 9 and 10 — first in the engagement, now confirmed twice** (2,774 tests, 0 failures, 2 legitimate skips; was 1 error after update 8, 3 after update 7, 221 after update 6, 61 at baseline). The F24 rollback fix held |
| GitHub CI: Dusk (F11/F16/F25–F31) | ⚠️ **2 failed / 24 passed after update 10 — best Dusk run of the engagement**; the web server survived the whole run (serve-supervisor.log: zero restarts — F25/F30 resilience confirmed). Both failures share one server-side root cause: an intermittent 500 on POST /mfa/verify (Carbon TypeError, **F31**) — newly exposed by the F29 retry's error capture. Update 11 ships the diagnostics that will land the full stack trace in the next run's log |
| **NEW: audit chain verification on MySQL (F13)** | 🔧 Fixed in update 6, verified by update-7 CI run |
| **NEW: venue JSON migrations on MySQL (F14)** | 🔧 Fixed; drift-refusal semantics restored for SQLite in update 8 (F21) |
| **NEW: audit rows for string-key targets (F15)** | 🔧 Fixed in update 6, confirmed by update-7 CI run |
| **NEW: rollback path integrity on MySQL (F22)** | 🔧 Full-chain down() audit done; four latent defects fixed in update 8 |
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

### F11. Dusk `InteractionSweepTest`: 13 of 16 fail (fixes prepared, update 5 — superseded by update 6, see F16)
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

### F13. CRITICAL, found in update 6: audit chain verification never worked on fresh MySQL databases
The update-5 MySQL run failed all five `AdminAuditChainTest` tests with "3 is identical to 0" etc. Reading `AdminAuditLog` explains it:
- The row hash covers the **raw stored `payload` JSON string**. The column is a real MySQL `json` column, and MySQL re-serialises whatever it stores (keys reordered shortest-first, spacing normalised). So the string a row was hashed with at write time is never the string MySQL returns at verify time: **every payload row verifies as tampered** on a fresh MySQL database (SQLite stores the string verbatim, which is why the SQLite job never saw it).
- Production passed `exospace:verify-data-integrity` 15/15 only because the production `payload` column evidently stores strings verbatim (schema drift from an earlier migration version) — the same luck that explains F9. Any **restore to a freshly migrated MySQL database would make the whole audit chain report broken**.
- **Fix (update 6):** the hash now covers a canonical (key-sorted) JSON form of the payload, which is stable under MySQL's re-serialisation; `verifyChain()` additionally accepts the legacy raw-string hash so already-stored rows keep verifying. New rows are always written canonically.
- **After deploy:** run `php artisan exospace:verify-data-integrity` and confirm 0 violations, then re-run it on a fresh MySQL database (CI now covers this automatically).

### F18. CRITICAL, introduced by update 6, found in update 7: `AdminAuditLog::record()` died on every audit write — **FIXED AND VERIFIED by the update-7 CI run**
Update 6's F15 change computed `$targetKey` outside the `DB::transaction` closure but forgot to add it to the closure's `use (...)` clause. PHP raised `Undefined variable $targetKey` inside every single audit write, the transaction rolled back, and the calling action failed:
- **MySQL job:** 29 errors + 192 failures (was 6 errors + 55 failures before update 6). ~215 of the 221 problems trace to this one line — directly (ErrorException in the trace) or as cascades (audit row missing → "Failed asserting that null is not null", request 500s, "The response is not a streamed response" on the billing exports, mailables queued 0 times, MFA flows dying mid-write).
- **SQLite job:** 28 errors + 187 failures (was fully green). The bug is engine-independent, which is why a previously green job went red.
- **What is genuinely fixed from update 6 (confirmed by this run):** 42 previously-failing tests now pass — the F14 venue-guard fixes, the F17 test defects and the F15 `_target_key` behaviour all work as designed.
- **Dusk:** the update-7 run confirms the cascade — 23 failures collapsed to 7 (19 passing) once audit writes stopped 500ing; the F18 fix released 16 browser tests.
- **Production impact:** resolved — the deployed update 7 ends it. (Before that deploy, every audit-logged action 500ed.)
- **Fix (update 7):** add `$targetKey` to the closure `use` clause — one line in `app/Models/AdminAuditLog.php`.

### F19. MEDIUM, found in update 7: `ops_incidents` down() cannot roll back on MySQL (error 1553)
With the F17 PRAGMA fix, `MigrateFreshTest::test_rollback_and_re_migrate_works` now gets further on MySQL and exposed the next pre-existing defect: the migration's `down()` ran `dropIndex('ops_incident_id')` **before** dropping the foreign key that needs that index, so MySQL refuses with `General error: 1553 Cannot drop index 'ops_events_ops_incident_id_index': needed in a foreign key constraint`. SQLite has no standalone FK objects, which is why it never surfaced there. `MigrateFreshTest` had already failed on MySQL for a different reason (the F17 PRAGMA bug), so this is NOT a new regression — it is the next layer of the same onion.
- **Production impact:** none for `migrate` (up() is unaffected); it blocks `migrate:rollback` past this point and any fresh-migrate + rollback cycle (restore drills, CI).
- **Fix (update 7):** on mysql/mariadb the down() now drops FK → index → column in one statement; SQLite keeps the previous order (its dropColumn emulation relies on the original table shape). Follows the same driver-guard pattern already used by `2026_07_10_000010` and `2026_09_15_000001`.

### F20. HIGH, found in update 7: residual F14 cases — exact-match guards that survived the update-6 helper
Re-reading the still-failing venue tests against the migrations found four remaining guard defects, all pre-existing (each failed in BOTH the update-5 and update-6 runs):
1. **Integral floats break exact-match guards everywhere.** PHP's `json_encode()` writes `1.0` as `"1"` (no `JSON_PRESERVE_ZERO_FRACTION`) — the project's own test helper `jsonNormalized()` documents this — so a guard literal holding `float 1.0/3.0/0.0` can never equal the `int 1/3/0` that comes back from the column: `int(1) === float(1.0)` is false in PHP. This silently disabled every salon v2.1 heal on MySQL ("7 door descriptors became 11" — the per-element and door-group guards all refused; the idempotence/reversibility tests only "passed" because the migration was a no-op). `json_canonical()` now normalises integral floats to ints so `json_arrays_equal()` compares values, not representations. Chain-hash safety check: stored payload strings already contain `"1"`-style ints (Laravel's cast encodes them that way), so recomputed audit-chain hashes are unchanged.
2. **Penthouse `residence` and `evening_light` carried their own private `jsonCanonical()` without key sorting** — the structure written by the previous pass comes back from MySQL with reordered keys, so the v2.1 structure swap never fired ("the cheap glass still arrives"), which then cascaded into the v3 pass (its input state was wrong, so "step-fascia" never arrived) and the full-chain test. Both now ksort (matching `double_volume`'s implementation); `convergence` and `media_wall` already had key-order-insensitive comparators.
3. **Cathedral `down()` compared nested arrays with `===`** (`post_fx`, `placement`) — MySQL reorders those keys, so the removal guards never fired and a rolled-back row was never equal to its pristine state. Now uses `json_arrays_equal()`.
4. **`VenueLakeTest` still used order-sensitive `assertSame` on two decoded JSON blocks** (lines 65–66, the lake block and post_fx) — converted to the project's `assertSameJson()` like their siblings at lines 156/157/276.

### F21. MEDIUM, found in update 8: update 7's key-order fix made the penthouse guards fire on drifted (admin-resaved) rows
The F20 key-sort fix for `residence`/`evening_light` was too broad: it normalised key order on **both** engines, but only MySQL needs that (it re-serialises JSON on storage). SQLite stores the bytes verbatim, so on SQLite the strict order-sensitive compare was the *mechanism that refuses editor drift* — an admin re-save reorders the payload, and the migration guards must treat that row as admin-customised and leave it alone (the `convergence` migration owns those rows). With update 7's ksort in place the drifted row matched the migration constants again, every structure/fixture swap fired through the drift, and `VenuePenthouseTest::test_the_drifted_production_row_converges_to_the_double_volume` failed on BOTH engines (structure came out 61 descriptors instead of the pinned 17, fixtures 5 instead of 2).
- **Production impact:** on MySQL production the behaviour is unchanged (the MySQL path is byte-for-byte the update-7 logic — and on MySQL a re-saved row is *byte-identical* to a never-edited row once stored, so the drift is not even representable there). Only the SQLite/dev path changes, back to the original author intent.
- **Fix (update 8):** `residence`/`evening_light` `jsonCanonical()` takes a `$normaliseKeyOrder` flag; `jsonEquals()` enables it only on mysql/mariadb. The drift test now documents both behaviours: refuse-and-converge on SQLite (17/2), heal-through-the-drift on MySQL (61/5).

### F22. MEDIUM, found in update 8: three more latent rollback defects past `ops_incidents` on MySQL
`MigrateFreshTest` rolls back the whole batch, so every `down()` must succeed on MySQL. The update-7 run got past `ops_incidents` (F19 fixed) and failed at the next layer. A full audit of the remaining rollback path found and fixed four defects:
1. **`2026_07_04_000003` team_user error 1553 (the run's failure):** down() dropped `team_user_user_id_index` while the pivot's FK to `users` still needed it. Now drops FK → index in the same statement, mirroring the file's own `users.current_team_id` block.
2. **`2026_07_02_160001` (venue_templates consolidated) error 3730:** down() did `dropIfExists('venue_templates')` while `galleries.venue_template_id` still referenced it. Now suspends FK checks for the drop (the graph is rebuilt by the re-migrate) — `Schema::withoutForeignKeyConstraints()`, the project's established idiom.
3. **`2026_07_02_160000` (users consolidated) error 3730:** the dependent list drops `galleries`/`users` while `gallery_analytics`, `gallery_schedule_events`, `analytics_daily`, `admin_audit_logs`, `artists`, etc. still reference them — no ordering of the list can satisfy every constraint direction. MySQL now drops the graph with FK checks suspended; SQLite keeps the plain sequential drop (it rebuilds tables and has no standalone FK objects).
4. **`2026_07_01_070923` (varchar conversion) table-missing error:** down() ran `ALTER TABLE galleries` without a guard, but the consolidated users migration drops `galleries` earlier in the rollback. Now guarded on table + columns.
- Everything else in the rollback path (01_19 → 10_05) was audited: either already guarded, already FK-safe, verified by this CI run, or a child-side drop that MySQL allows.

### F23. LOW, found in update 8: salon turn test asserted byte-identical JSON on MySQL
`VenueSalonTest::test_the_turn_migration_heals_a_v2_production_row` asserted the untouched `material_config` block with `assertSame` — insertion-order sensitive. On MySQL the stored block comes back with reordered keys (all values identical), so the assertion failed purely on representation. Converted to `assertSameJson()` like its siblings.

### F24. MEDIUM, found in update 9: the rollback tail hit the galleries-consolidated `down()` (MySQL error 3730)
With F22's four defects fixed, `MigrateFreshTest`'s rollback now travels further back and dies at `2026_07_02_150000_create_galleries_table_consolidated.php:78`: it dropped `gallery_images` **before** `analytics_events`, which still holds the `gallery_events_image_id_foreign` FK (the table was created as `gallery_events` by `2026_04_21_201844`, renamed by `2026_06_30_014503` — so the FK outlives the rename and its parent drops too early). A full-chain static simulation of all 128 migrations' `down()` methods (FK graph + live-set replay, newest → oldest) confirms **this is the last 3730/1553 inversion in the rollback path**: `galleries` itself is already dismantled under suspended FK checks by the F22-fixed `160000` drop, and every older alter-migration `down()` is `hasTable`-guarded.
- **Fix (update 9):** same pattern as F22's consolidated graph — driver-guarded `Schema::withoutForeignKeyConstraints()` on MySQL for the whole drop list, with child-first order (`analytics_events` → `event_rsvps` → `gallery_schedule_events` → `gallery_images` → `galleries`); SQLite keeps the plain sequential drop it already passes with.

### F25. HIGH (infrastructure), found in update 9: the Dusk web server died mid-run for the second time — same signature
The update-8 Dusk run's raw numbers (23 failed / 3 passed) are **not** an app regression. The diagnostics step proved it: `(artisan serve NOT running — the server died during the run)`, no `laravel.log`, no shutdown message, `serve.log` ending mid-stream right after `/master-control` — identical to the update-6-era incident. 21 of the 23 failures are pure `net::ERR_CONNECTION_REFUSED` fallout; the only genuine failures are the 2 InteractionSweepTest timing tests that also head the 7-failure list of the update-7-era run (where the server survived — `php artisan serve` was alive in that run's diagnostics). Cross-run evidence: update-6 era 23/3 with server dead, update-7 era 7/19 with server alive, update-8 era 23/3 with server dead. The Dusk number is currently tracking **server survival, not code quality**.
- **Fix (update 9):** the serve process now runs under a self-healing supervisor (`serve-supervisor.sh`, started by the CI job): if the `artisan serve` master dies, it is restarted within ~1 s and every death is logged with its exit code; the diagnostics step additionally prints the supervisor's restart log and greps `dmesg` for OOM/kill evidence so the actual death cause (suspected runner OOM or a `php -S` master crash) is captured next time. A mid-run death now costs at most the one test that races the 1 s restart window, instead of every test after it.

### F26. MEDIUM (test harness), found in update 10: the settle walker deadlocked on lazy images it never intersected — and kept scrolling after the wait resolved
Two failure classes in the update-9 run trace to the scroll-walker added for F16:
1. `/admin/galleries/:id/edit` still deadlocked the 20-second image wait on both the pro-user and missing-image sweeps: that page's artwork grid sits where the window-walk never brings it near the viewport (collapsed panels / inner scroll containers), so its `loading="lazy"` images are never selected and can never become `complete`. The timeout report proves it — the pending entries show `loading:"lazy"`, and their paths come from the report's `src`-attribute fallback, not `currentSrc` (an un-requested lazy image has no selected source).
2. The fire-and-forget walk steps kept running after the image wait resolved: a pending 60 ms step could scroll the page back down after `settlePage()` returned, so the next interaction targeted coordinates above the viewport (`element click intercepted ... at point (356, -38)` in the mobile-menu sweep).
- **Fix (update 10):** the walk sets a `__sweepWalkDone` flag that `settlePage()` awaits before anything else; the image wait then accepts lazy images the walk never intersected (`i.complete || (i.loading === "lazy" && !i.currentSrc)` — the same rule the broken-image sweep already applies); and the return-to-top happens only after the walk is provably finished.

### F27. HIGH (test harness), found in update 10: the first-visit welcome modal intercepted every dashboard click for fresh users
The dashboard shows a fixed `inset-0` welcome modal (`z-[60]`, body `overflow-y-hidden` scroll lock) to users under 48 hours old until `localStorage.exospace_welcomed === '1'`. Every sweep user is a fresh factory user, so the feedback, mobile-menu, Turbo and notification sweeps clicked into an invisible wall — the update-9 log literally shows the modal's "Create First Gallery" CTA receiving the click meant for the feedback submit button.
- **Fix (update 10):** `loginAs()` sets `exospace_welcomed = '1'` on the login page (same origin) before signing in, so no sweep runs behind the modal.

### F28. LOW (test bug), found in update 10: `assertAttribute` called with reversed arguments
Dusk's signature is `assertAttribute($selector, $attribute, $expected)`; both call sites passed `('aria-expanded', '#selector', $value)`, so Dusk tried to resolve the literal selector `body aria-expanded` (`no such element` in the notification sweep).
- **Fix (update 10):** arguments swapped in the notification and mobile-menu sweeps. (The mobile-menu site never executed — its test died at the welcome-modal click first — but it carried the same latent bug.)

### F29. MEDIUM (test harness), found in update 10: MFA verification had no retry when the TOTP code straddled a 30-second window
`verifyMfaInBrowser()` computed one OTP and waited 10 s for the `/master-control` redirect. If the code expired between typing and the POST landing, the challenge silently re-renders and the wait times out — the plan-selection sweep's failure in this run.
- **Fix (update 10):** the helper retries with a freshly computed code (up to 3 attempts) and treats "no longer on /mfa/verify" as success.

### F30. LOW (test harness), found in update 10: the login flow had no tolerance for the ~1 s server-restart window
The mid-run `artisan serve` death (exit code 139 = SIGSEGV, 18:39:45Z — the supervisor restarted it in ~1 s, exactly as F25 designed) cost two tests whose navigation landed inside the restart window: the super-admin sweep's `assertSee('Master Control')` ran against a Chrome error page (settlePage passes instantly on an empty page — the marker text itself is fine, verified in the view source), and the control-center sweep's `loginAs` typed into an error page (`#email` missing). This is the bounded collateral F25 predicted: 2 casualties instead of a 21-test cascade, and zero `ERR_CONNECTION_REFUSED` console records — Chrome error pages never fire them.
- **Fix (update 10):** `loginAs()` waits for `#email` (up to 10 s) before typing, riding out restarts. The remaining exposure (an in-loop `assertSee` racing a restart) is accepted residual flake; no app code is implicated.

### F31. HIGH (app, intermittent), found in update 10: POST `/mfa/verify` intermittently returns 500 — a Carbon `TypeError` escapes the challenge flow
Both remaining Dusk failures ("super admin pages are clean", "plan select restores after cancelled confirm") died inside `verifyMfaInBrowser()`: the update-10 retry (F29) ran its three fresh-window attempts and captured the response body — an Ignition **Internal Server Error** page: `TypeError` at `vendor/nesbot/carbon/src/Carbon/Traits/Units.php:556` — `Carbon\CarbonImmutable::rawAddUnit(): Argument #1 ($date) must be of type Carbon\\CarbonImmutable, Carbon\\CarbonImmutable given, called in …/vendor/…`. Forensics from this run:
- **Server-side and intermittent:** serve.log shows one verify in the very same run completing cleanly (POST → redirect → `/master-control`), while other attempts 500 — the identical code path both succeeds and fails within one run.
- **Not reproducible offline:** the exact locked dependency tree (Laravel 12.69.2, Carbon 3.14.0, google2fa v9) was installed in an isolated sandbox and the CI flow was driven over real HTTP (`php artisan serve`, cached config, file sessions, sqlite+WAL, real CSRF login, real TOTP codes) on **both PHP 8.4.23 and 8.2.32** — 302 every time, no 500. The feature suites (both engines) exercise the same POST and are green.
- **Where it cannot be:** google2fa v9 contains no Carbon calls at all; the controller's own `catch (\Throwable)` returns a friendly redirect — so the TypeError escapes **outside the controller action** (middleware or terminate phase), which is also why it is env-sensitive.
- **Why the caller is still unknown:** the captured body was truncated at 300 characters — exactly before the `vendor/…` caller path — and the full stack trace sat in `storage/logs/laravel-YYYY-MM-DD.log` (LOG_STACK=daily) while the diagnostics step tailed `laravel.log` and printed "(no laravel.log)".
- **Fix (update 11):** diagnostics, not a blind patch — (1) the Dusk diagnostics step now dumps the latest `storage/logs/laravel-*.log` daily files, so the full trace of ANY 5xx lands in the CI log; (2) the same step prints the serve binary's PHP version and opcache state (the intermittency signature is consistent with per-worker state, and the trace will settle it); (3) `MfaController::enable/verify` catch blocks now log the exception class + full stack instead of the message alone. Once the next run's trace names the Carbon caller, the actual fix is a one-liner follow-up (update 12).

### F14. HIGH, found in update 6: venue JSON migrations silently skipped on MySQL (update-6 fix confirmed working; residuals fixed in update 7 — see F20)

Every venue pass that guards a rewrite with an exact array/string match (`===` on decoded JSON, `array_keys($a) === array_keys($b)`, or a byte-stable `jsonEquals`) can never fire on MySQL, because the JSON column reorders keys before the migration reads it back. Concretely: the salon v2.1 door/side heal (7 doors never became 11), the penthouse v2.1 structure/fixture swap ("the cheap glass still arrives"), the nebula fixture swap, and every venue `down()` that removes what `up()` added (cyber `artwork_reactive`/`post_fx`, dark-museum `post_fx`/`placement`, zen `bays`, industrial-loft, sculpture-garden `garden`/assets, mirror-lake `lake`/`post_fx`, white-cube, rooms, infinite-void).
- **Production impact:** production already ran these migrations (they are marked Ran), so the guarded rewrites **did not apply there**: production's salon still has the v2 doorcase, the penthouse the v2.0 glass, and rollbacks on production will leave the migration-added keys behind. If those venues look wrong, the fix is a follow-up healing command or re-running the payloads manually — say the word and it gets built.
- **Fix (update 6):** a shared `json_arrays_equal()` helper (content equality, order-insensitive, list order preserved) in `app/helpers.php`, used by every affected migration guard. Value drift is still refused; only key order stopped mattering, because key order is not something MySQL lets us control.

### F15. HIGH, found in update 6: audit rows for string-key targets were never written on MySQL
`OpsCredentialInventoryService::markRotated()` audits against the `OpsCredential` model, whose primary key is a string (`'db-password'`, `'coolify-token'`). `admin_audit_logs.target_id` is a BIGINT morph column: MySQL strict mode rejects the insert (`Incorrect integer value`), the service's catch-all swallowed it, and **credential rotations left no audit trail on MySQL** (the three failing `OpsCredentialInventoryTest` tests; SQLite tolerates the type slip, which is why it passed there).
- **Fix (update 6):** `AdminAuditLog::record()` now puts a non-numeric key into the payload as `_target_key` and stores `target_id` as NULL; a new migration (`2026_10_06_000001_make_admin_audit_logs_target_id_nullable.php`) makes the column nullable. Generic fix — any future string-keyed audit target is covered.

### F16. Dusk run lost its web server mid-run (update 6 diagnosis)
The update-5 Dusk log shows a clean two-stage collapse: first `InteractionSweepTest > pro user pages` timed out in `settlePage` waiting for two `loading="lazy"` artworks below the fold on `/admin/galleries/1/edit` (lazy images never fetch until scrolled near, so "all images complete" can never become true). From the next test on, every request failed with `net::ERR_CONNECTION_REFUSED` — the single `php artisan serve` process died right after serving `/master-control`, with no fatal logged, and the remaining 22 tests (including all of SmokeTest) failed against a dead port.
- **Fixes (update 6):**
  - `settlePage()` now walks the page once (scroll steps + return to top) to trigger lazy loads, then waits for image completion, with the actionable timeout report kept.
  - The CI server starts with `PHP_CLI_SERVER_WORKERS=8`, so one crashed worker no longer takes the whole run down, and the sqlite connection gained WAL + a 10 s busy timeout (`DB_BUSY_TIMEOUT`/`DB_JOURNAL_MODE` are now env-tunable in `config/database.php`).
  - The Dusk diagnostics step now prints whether `artisan serve` is still alive plus a longer `serve.log` tail, so a repeat of the worker death comes with evidence instead of a port error.

### F17. MySQL job residuals fixed as test defects (update 6)
The remaining non-venue MySQL failures, each traced and fixed:
- `PasswordUpdateTest`: `password_reset_tokens` has no `id` column (`insertGetId` works only on SQLite's rowid). Insert/assert by token + email now.
- `MigrateFreshTest`: `PRAGMA foreign_keys` is SQLite-only SQL; now driver-guarded.
- `TeamMembershipLifecycleTest` / `ConcurrentWriteSafetyTest`: the composite membership FK (MySQL-only by design) refuses the deliberately corrupt "stale team pointer" state. The genuine case (owner membership) now attaches the owner row; the deliberately corrupt states are built under `Schema::withoutForeignKeyConstraints()`.
- `RegistrationTest`: the SQL interceptor matched only SQLite's `insert into "team_user"`; MySQL quotes with backticks, so the simulated failure never fired and registration succeeded (302 instead of 5xx). Now quote-style agnostic.
- `DigestRecipientManagementTest`: the simulated concurrent duplicate inserted `added_by => 1`, which violates the real FK on MySQL. Now uses the acting admin's id.
- `HttpRequestEfficiencyTest` + `WebhookLedgerAndReplayTest`: query-log matchers looked for `from "galleries"`-style SQLite quoting; now accept either quoting style.
- `OpsDiagnosticRunnersTest` / `RestoreFromBackupTest`: both verify **non-MySQL** capability reporting; on the MySQL job the paths cannot occur, so they skip with a note.
- `AnalyticsEventTrackingTest` (perf beacon) + all venue tests: order-insensitive `assertSameJson()` (added to `Tests\TestCase`) replaces order-sensitive `assertSame` on decoded JSON.

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
| P0 | Apply update-5 zip (Dusk test fixes, MySQL frontend build, MySQL-8 query fix) | Done — its CI run produced the logs reviewed in update 6 |
| P0 | Apply update-6 zip (F13 audit chain, F14 venue guards, F15 target_id, F16 Dusk resilience, F17 test defects) | Done — but its CI run exposed F18/F19/F20, see update 7 |
| P0 | Apply update-7 zip (F18 audit closure, F19 rollback 1553, F20 residual guards) | Done, deployed — its CI run confirmed F18 fixed (221→3 MySQL, 215→1 SQLite, Dusk 23→7) and exposed F21/F22/F23, see update 8 |
| P0 | **Apply update-8 zip (F21 drift-refusal semantics, F22 rollback-tail defects, F23 salon assertion)** | Done, deployed — its CI run confirmed F21/F22/F23 fixed (SQLite 0 failures, MySQL down to the F24 rollback error; Dusk 7→23 raw only because the server died again, see F25) |
| P0 | **Apply update-9 zip (F24 last rollback 3730, F25 Dusk serve supervisor)** | Done, deployed — its CI run confirmed F24 fixed (MySQL fully green, first time) and the F25 supervisor worked (one death, ~1 s recovery, 2 casualties instead of a cascade); exposed the Dusk harness defects F26–F30 |
| P0 | ~~After the update-9 deploy: re-run CI~~ Done — see update-10 verdict: SQLite green (1 skip), MySQL green (2 legitimate skips), Dusk 9/17 with all failures root-caused | Done |
| P0 | **Apply update-10 zip (F26 settle walker, F27 welcome modal, F28 assertAttribute, F29 MFA retry, F30 login resilience — all in tests/Browser/InteractionSweepTest.php)** | Done, deployed — its CI run confirmed every fix (Dusk 9→2 failures, 24/26 passing, zero server restarts) and exposed F31 | 
| P0 | **Apply update-11 zip (F31 diagnostics: daily laravel-*.log capture + PHP/opcache print in Dusk diagnostics, full-stack logging in MfaController catches)** | Open — the next run's log should contain the exact Carbon caller of the MFA 500; the one-line fix follows from that trace |
| P1 | Decide on a healing pass for the venue rewrites that silently skipped on production MySQL (F14): salon v2.1 doors, penthouse v2.1 glass, nebula fixtures | Open |
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
- A fully green CI run including Dusk (expected next run: both unit suites green again; Dusk at 0 failed once F31's Carbon caller is fixed from the captured trace)
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
| 2026-10-06 (update 6) | Read the update-5 CI logs. SQLite 2,774 green; MySQL now 6 errors + 55 failures; Dusk 23 failed / 3 passed. Three production-grade causes found and fixed: **F13** audit-chain hashes broke on MySQL JSON normalisation (canonical hash + legacy fallback), **F14** venue migration guards never matched MySQL's reordered JSON (shared `json_arrays_equal()`), **F15** string-PK audit targets rejected by `target_id` BIGINT (`_target_key` payload + nullable migration). Dusk: `settlePage()` lazy-image deadlock fixed, server resilience via `PHP_CLI_SERVER_WORKERS`, richer diagnostics (F16). All remaining MySQL failures fixed as test defects (F17). Update-6 zip ships only changed files. Not yet pushed or verified |
| 2026-10-06 (update 7) | Read the update-6 CI logs and compared against the update-5 baseline. **The suite got worse in raw numbers — one self-inflicted bug:** MySQL 29 errors + 192 failures, SQLite 28 errors + 187 failures (was green), Dusk byte-identical. Root cause: **F18**, a missing `$targetKey` in the `AdminAuditLog::record()` closure `use` clause (update 6's F15 change) — every audit write died and cascaded into ~215 of the 221 MySQL problems. **42 tests are genuinely fixed** (F14/F15/F17 work confirmed). New findings fixed: **F19** `ops_incidents` down() error 1553 (FK dropped after its index; blocks rollback on MySQL), **F20** residual venue guards — integral-float representation (`1.0` stored as `1`) disabled the salon heal, penthouse `residence`/`evening_light` private canonicals lacked key sorting (cascaded into the v3 pass and full chain), Cathedral `down()` used `===` on nested arrays, Lake test used `assertSame` on decoded JSON. Update-7 zip ships only the 7 changed code files + this report. **Deploy update 7 immediately — deployed update 6 breaks audit-logged actions in production.** |
| 2026-10-06 (update 8) | Read the update-7 CI logs. **Dramatically better:** MySQL 3 problems (was 221; baseline 61), SQLite 1 failure (was 215; baseline 0), Dusk 7 failed / 19 passed (was 23/3 — the F18 fix released 16 browser tests). The F18 fix is confirmed working. Remaining problems, all root-caused and fixed: **F21** update 7's key-sort for `residence`/`evening_light` also normalised SQLite, dissolving the guard that refuses editor re-save drift — now key-order normalisation is MySQL-only, restoring the author's refuse-drift semantics where they are representable, with the drift test documenting both engine behaviours; **F22** full rollback-path audit found four more latent MySQL defects — `2026_07_04_000003` team_user FK/1553 (this run's failure), `2026_07_02_160001` venue_templates drop vs galleries FK/3730, `2026_07_02_160000` consolidated graph drop vs many incoming FKs/3730, `2026_07_01_070923` unguarded ALTER on an already-dropped table — all fixed (FK-before-index pattern; `Schema::withoutForeignKeyConstraints()` for the consolidated graph dismantling; guards); **F23** salon turn test used order-sensitive `assertSame` on a stored JSON block — now `assertSameJson()`. Update-8 zip ships only the 9 changed files (8 code + this report). Expected next run: SQLite fully green, MySQL green with the 2 legitimate skips, Dusk unchanged at 7/19 until its harness work. |
| 2026-10-06 (update 9) | Read the update-8 CI logs. **Best run yet, with one misleading number.** SQLite is fully green (2,774 tests, 0 failures — first time since the F18 incident). MySQL dropped to a single error (was 3 after update 7, 221 after update 6, 61 at baseline): the rollback tail, having cleared all four F22 defects, hit one more latent `down()` defect — **F24**, the galleries-consolidated migration dropped `gallery_images` before `analytics_events` (the renamed `gallery_events` still holds `gallery_events_image_id_foreign`). A full-chain static simulation of all 128 migrations (FK graph + rollback live-set replay) confirms F24 is the **last** 3730/1553 inversion in the rollback path; fixed with the established driver-guarded `Schema::withoutForeignKeyConstraints()` pattern. Dusk printed 23 failed / 3 passed — but the diagnostics step proved the web server **died mid-run again** (identical signature to the update-6-era incident; 21 of the 23 failures are `ERR_CONNECTION_REFUSED` fallout; only the 2 known InteractionSweepTest timing tests genuinely failed; in the update-7-era run the server survived and scored 7/19). Dusk is currently tracking server survival, not code quality — **F25** adds a self-healing `serve-supervisor.sh` (restart within ~1 s, death log with exit codes) plus `dmesg` OOM forensics in diagnostics. Update-9 zip ships only the 2 changed files + this report. Expected next run: SQLite green, MySQL green with the 2 legitimate skips, Dusk resilient — remaining genuine work is the InteractionSweepTest harness. |
| 2026-10-06 (update 10) | Read the update-9 CI logs. **The cleanest run of the engagement: both unit suites fully green for the first time.** SQLite 2,774/0 (1 legitimate skip), MySQL 2,774/0 (2 legitimate skips) — the F24 rollback fix held and `MigrateFreshTest` now travels the entire 128-migration down-chain on MySQL without error. Dusk printed 9 failed / 17 passed: the CI web server **did** die mid-run again (exit code 139 = SIGSEGV at 18:39:45Z) — but the F25 supervisor restarted it within ~1 s, so the damage was 2 error-page casualties (super-admin sweep's assertSee, control-center sweep's loginAs) instead of a 21-test cascade. The other 7 failures are genuine InteractionSweepTest harness defects, every one root-caused: the settle walker deadlocked on lazy images it never intersected and kept scrolling after the wait resolved (F26), the 48-hour first-visit welcome modal scroll-locked the body and intercepted every dashboard click for fresh users (F27), two `assertAttribute` calls had reversed arguments (F28), MFA verification had no retry when the TOTP code straddled its 30-second window (F29), and the login flow had no tolerance for the restart window (F30). All five fixed in `tests/Browser/InteractionSweepTest.php` — the only code file in the update-10 zip, plus this report. Expected next run: both unit suites green, Dusk at or near 0 failed; if a page is genuinely broken, the sweep will now say so honestly. |

| 2026-10-06 (update 11) | Read the update-10 CI logs (logs_101704223863.zip; the other archive attached that day, logs_101686666940, was a stale re-upload of the F18-era run and matched that run byte-for-byte — not a new data point). **Best run of the engagement:** SQLite 2,774/0 (1 legitimate skip) and MySQL 2,774/0 (2 legitimate skips) — both fully green for the second consecutive run; Pint (711 files), Frontend Build, Preflight (0 critical / 6 known warnings) and Dependency Audit all green; Dusk **24 passed / 2 failed** with the web server alive end-to-end (serve-supervisor.log: zero restarts — the F25/F30 resilience held, and the F26–F30 fixes cleared 7 of the 9 prior failures). Both remaining failures share one new finding, **F31**: an intermittent server-side 500 on POST `/mfa/verify` — `TypeError` at Carbon's `Units.php:556` (`rawAddUnit(): Argument #1 ($date) must be of type Carbon\\CarbonImmutable, Carbon\\CarbonImmutable given`), escaping the controller's catch-all (so it fires outside the action), newly visible because the F29 retry now captures the error page body; the body was truncated at 300 chars exactly before the vendor caller path, and the full trace sat in the daily log the diagnostics step wasn't reading. Offline reproduction with the exact locked dependency tree over real HTTP on PHP 8.2.32 and 8.4.23 (serve + cached config + file sessions + WAL sqlite + real TOTP flow) returned 302 every time — the defect is state-dependent. Update-11 zip ships the F31 evidence chain: Dusk diagnostics now dump the latest `laravel-*.log` daily files plus the serve PHP/opcache runtime, and the MFA controller catch blocks log the exception class and full stack. Next run should name the exact Carbon caller in the log; the fix follows from that trace. |