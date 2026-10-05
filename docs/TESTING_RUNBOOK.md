# Exospace — Manual Testing & Go-Live Runbook

Operational companion to `docs/DISASTER-RECOVERY.md` (DR). DR covers *what to do when it
breaks*; this runbook covers *how to prove it works before and after go-live*. Backup
plumbing, retention rules and restore procedures are documented in DR §3–§4 and are not
repeated here.

Every command below was verified against this repository. Where a step could not be
executed by the author (external dashboards, browser binaries, live provider
credentials), it is marked **UNVERIFIED** with the reason — the command itself is still
taken from the project's own code or CI definitions, never invented.

Legend used in the "Safe in prod?" column:

| Mark | Meaning |
|---|---|
| ✅ read | Read-only or self-cleaning probe; safe against the production database |
| ⚠️ write | Writes application data (staging/local only) |
| ❌ destructive | Rebuilds or deletes data; **never** point at production |

---

## 0. Where each stage runs, and what the production container actually has

Three execution surfaces exist. Knowing which tools live where decides the whole plan.

| Surface | Has | Does NOT have | Used for |
|---|---|---|---|
| **Local machine / CI runner** | PHP 8.2 + dev deps (PHPUnit, Dusk, Pint), Node 22, MySQL/SQLite | — | Everything: full suites, QA profiles, Dusk, lint |
| **Coolify app container (terminal)** | `php` CLI + full runtime (predis, gd, exif, bcmath, …), `mysqldump`/`mysql` (nixpacks `mariadb` pkg), artisan commands, `node` binary (installed for the Vite build), built frontend in `public/build` | **No PHPUnit, Dusk or Pint** — nixpacks builds with `composer install --no-dev --optimize-autoloader` (nixpacks.toml, build phase). No test suites can run here. | Read-only health, preflight, integrity + backup verification, smoke probes, scheduler/queue checks |
| **Coolify scheduled task** | Same image as the app container | Same | `php artisan schedule:run` every minute (the app's only scheduler — `BYPASS_SCHEDULER=true`) |

Derivation of the container facts (verified from `nixpacks.toml`): the build phase runs
`npm ci && npm run build`, `node scripts/verify-vite-manifest.js`,
`composer validate/check-platform-reqs`, `composer install --no-dev`,
`php artisan route:cache && view:cache`, `bash scripts/lint-compiled-views.sh`. The
start command (`docker-start.sh`) runs `storage:link`, `view:cache`, `migrate --force`,
`exospace:preflight` (hard-fails the container on critical config errors), the queue
worker supervisor loop, and — because `BYPASS_SCHEDULER=true` — **not** the internal
scheduler loop (a separate Coolify scheduled task owns `schedule:run`).

**Consequence:** the full test suite, QA *test* profiles (quick_check … database) and
Dusk can only ever run locally or in CI. The container terminal gets the two
`prod-safe-read` profiles (`smoke`, `production_health`) plus the read-only
`exospace:*` verification commands.

### CI (GitHub Actions) — what already runs for you

Both workflows were read and verified; prefer CI over local for the heavy gates:

| Workflow | Trigger | Jobs |
|---|---|---|
| `.github/workflows/ci.yml` | every push/PR to main | PHP suite (SQLite), Frontend build + manifest check, Pint repo-wide, `exospace:preflight`, PHP suite (MySQL 8 service), Dusk browser tests, dependency audit (`composer audit --locked`, `npm audit --omit=dev`) |
| `.github/workflows/test-profiles.yml` | manual "Run workflow" + nightly 02:17 UTC | any registered QA profile against MySQL 8 or SQLite, JUnit artifact uploaded, optionally POSTed to the Control Center (`CONTROL_CENTER_INGEST_URL` + `QA_INGEST_TOKEN` secrets, consumed by `POST /api/control-center/runs` with the `X-QA-Token` header) |

---

## A. Safety rules — read before running anything

### A.1 Commands that must never touch the production database

| Command | Class | Why |
|---|---|---|
| `php artisan migrate:fresh` / `db:wipe` / `migrate:rollback` | ❌ destructive | Drops/reverts production schema. |
| PHPUnit/Dusk suites (`php artisan test`, `qa:run <test-only profile>`) | ❌ destructive | `RefreshDatabase`/`LazilyRefreshDatabase` migrate:fresh the **default** connection. They are `test-only` safety class. |
| `exospace:backup:restore … --execute --confirm=RESTORE` | ❌ destructive | Replaces database contents / overwrites media (DR §3.2–3.3). Plan mode without `--execute` is ✅ read. |
| `exospace:anonymize-*`, `exospace:process-gdpr-deletions` | ❌ destructive | Irreversible PII scrubbing / account deletion (scheduled jobs; run manually only on staging). |
| `exospace:backup clean` | ⚠️ write | Deletes old backup archives per retention (DR §4.1). |
| `php artisan down` / `up` | ⚠️ write | Maintenance flag (`APP_MAINTENANCE_DRIVER=cache` in prod → Redis). |
| `exospace:preflight`, `qa:doctor`, `qa:health`, `qa:smoke`, `exospace:verify-data-integrity`, `exospace:backup:verify`, `backup:list`, `migrate:status`, `about` | ✅ read | Safe on production (preflight writes only two self-cleaning cache keys; health probes write+forget one cache key each). |

The app also **enforces** this at runtime: `App\Services\TestCenter\EnvironmentSafety`
refuses every `test-only` profile when `--target=production` and permanently blocks
suite execution for the production environment (verified refusal message:
"🔒 Production is protected"). Only `smoke` and `production_health` (`prod-safe-read`)
are accepted against production.

### A.2 Recommended staging environment (clone of production for destructive drills)

Create a **second Coolify application** from the same repo (new UUID), with its own
MySQL database and **the same Redis instance is NOT safe — give staging its own Redis
DB/instance** (sessions, cache, locks and the queue are shared state; a staging test on
the production queue consumes production jobs). Mount the same three persistent paths:
`/app/storage/app/public`, `/app/storage/app/private`, `/app/storage/logs`.

Environment variables to set (names only — values come from the Coolify config /
provider dashboards, never from backups; see DR §3.4 step 2):

- App identity/network: `APP_NAME`, `APP_ENV=staging`, `APP_DEBUG=false`, `APP_KEY`
  (fresh key is fine — do **not** reuse the production key), `APP_URL` (the staging
  domain, must be https), `TRUSTED_PROXIES` (staging proxy subnet), `SESSION_*`,
  `SESSION_SECURE_COOKIE=true`.
- Data stores: `DB_CONNECTION=mysql` + `DB_HOST/DB_PORT/DB_DATABASE/DB_USERNAME/DB_PASSWORD`
  pointing at the staging MySQL; `REDIS_HOST/REDIS_PORT/REDIS_PASSWORD/REDIS_CLIENT`,
  `REDIS_QUEUE_CONNECTION`, `CACHE_STORE`, `SESSION_DRIVER`, `QUEUE_CONNECTION=redis`.
- Scheduler: `BYPASS_SCHEDULER=true` (staging gets its own Coolify scheduled task, §E).
- Feature/integration parity (so preflight and flows behave like prod): `MAIL_MAILER=resend`,
  `RESEND_API_KEY` (or a Resend sandbox domain), `MAIL_FROM_ADDRESS/MAIL_FROM_NAME`,
  `TWOCHECKOUT_*` (sandbox account + product IDs), `COOLIFY_API_TOKEN/BASE_URL/APPLICATION_UUID`
  (staging app's own UUID), `R2_*` + `BACKUP_DISKS` + `BACKUP_PASSWORD`
  (staging may reuse the R2 bucket with a different prefix only if you accept shared
  retention; otherwise `BACKUP_DISKS=local`), `OPERATIONAL_ALERT_WEBHOOK` (a staging
  Slack channel), `SENTRY_LARAVEL_DSN` (staging project), `METRICS_TOKEN`,
  `QA_INGEST_TOKEN`, `CONTROL_CENTER_ADMINS`.
- Tests on staging: `TEST_CENTER_STAGING_SUITES=true` **only if staging data is
  disposable** — it unlocks `test-only` profiles against the staging target
  (`config/test-center.php`); leave unset to keep staging read-only.

Then the restore drill of DR §3.2/§3.3 has a legitimate target: restore the newest
verified production artifact into staging and diff. `STAGING_URL` in the staging env
feeds `qa:run smoke --target=staging`.

### A.3 The `.env` tripwires the test system enforces

`qa:doctor` and `qa:run` refuse to run when production-ish session values leak from the
environment into a suite run (`SESSION_DOMAIN` non-empty, `SESSION_SECURE_COOKIE=true`,
or redis session driver outside `testing`). CI isolates via `cp .env.example .env`;
locally do the same — do not run suites against a copy of the production `.env`.

---

## B. Local / CI stage — full verification before shipping

Run on: **local machine** (or let CI do it). Safe in prod: n/a (never run locally
against production credentials; use the `.env.example` workflow below).

### B.0 One-time environment setup

```bash
composer install                      # dev deps INCLUDED (PHPUnit, Dusk, Pint)
cp .env.example .env                  # clean, non-poisoned local env — never the prod .env
php artisan key:generate
npm ci                                # requires package-lock.json (npm ci fails without it)
npm run build                         # public/build/manifest.json — page-render tests 500 without it
php artisan qa:doctor                 # gate: must end "READY" (warnings allowed)
```

Pass criteria: `qa:doctor` prints `READY` (or `READY WITH WARNINGS`) and exits 0.
It checks PHP ≥ 8.2, all required extensions, `vendor/bin/phpunit`, `APP_KEY`,
the Vite manifest, env poisoning, writable `storage/framework`, and (with
`--profile=<key>`) the TEST_MYSQL prerequisites of that profile.
Failure interpretation: every finding carries a `fix →` line — resolve those before
running suites, or expect cascading false failures (the doctor's own contract).

Expected state after these five commands (verified): `READY WITH WARNINGS`
(the only expected warning is "no .git directory" on archive-based checkouts).

### B.1 Full PHP test suite (SQLite, in-memory)

```bash
php -d memory_limit=2G vendor/bin/phpunit        # phpunit.xml = sqlite :memory:, array drivers
```

Where: local + CI (`ci.yml` job *PHP Tests (SQLite)*, same command).
Safe in prod: ❌ (RefreshDatabase).

- Expected: `OK (2774 tests, …)` — **verified**: 2,774 tests pass against this tree
  (run in alphabetical chunks after the fixes in §H; one expected failure exists only
  when 3D asset files are absent from the checkout, see §B.5).
- Memory flag is mandatory: parts of the suite peak above the CLI `memory_limit`
  default (this is also encoded in `ci.yml`; `qa:run` now carries the same ceiling).
- Failure interpretation: PHPUnit lists failures per test; fix code before proceeding.
  `INFRASTRUCTURE` classification in a `qa:run` summary means environment, not product.

MySQL-fidelity variant (what CI's *PHP Tests (MySQL)* job does): a MySQL 8 server +
`DB_CONNECTION=mysql` env overrides, then the same phpunit command.

### B.2 QA profiles (the recorded, gated way to run the same suites)

```bash
php artisan qa:run --list              # registry: verified, all 9 profiles listed
php artisan qa:doctor --profile=pre_release
php artisan qa:run quick_check         # ⚠️ write (local DB) — ~4 min, sqlite
php artisan qa:run pre_release         # ⚠️ write — the shipping gate; ~25 min
php artisan qa:run security | billing | seo   # focused profiles
php artisan qa:run database            # ⚠️ write — REQUIRES TEST_MYSQL_* (refuses without)
php artisan qa:run full_regression     # ⚠️ write — pre_release + explicit sqlite re-pass
```

Pass criteria: summary table ends `SUCCESS ✅ <profile>: N passed`; every run is
recorded into Control Center (`qa_test_runs`, visible in the super-admin dashboard).

Verified results from this tree (local, SQLite fallback for `pre_release`):

| Profile | Result |
|---|---|
| `quick_check` | PASSED — 438 tests |
| `security` | PASSED — 367 tests |
| `billing` | PASSED — 304 tests |
| `seo` | PASSED — 172 tests |
| `pre_release` | PASSED — 1,693 passed, 1 skipped (MySQL preferred; `TEST_MYSQL_HOST` unset → SQLite fallback warning is expected and honest) |
| `database` | BLOCKED — honest refusal with the exact `TEST_MYSQL_*` variables to set (verified) |
| `full_regression` | UNVERIFIED locally (same mechanics as `pre_release` + one extra SQLite pass; nightly in CI) |

Coverage note: `pre_release` runs the *union of the profile groups* in
`config/test-profiles.php` (~1,700 tests), not every file in `tests/Feature` — the full
`vendor/bin/phpunit` run (§B.1) is the only one that executes all 2,774. Do not treat
pre_release green as "everything ran"; both gates exist for different depths.

Interpretation of the `database` profile: blocked means "set `TEST_MYSQL_HOST/PORT/
DATABASE/USERNAME/PASSWORD` pointing at an ephemeral MySQL 8" — the profile exists to
catch SQLite-invisible schema drift (strict mode, partitions, collation).

### B.3 Frontend & lint gates

```bash
npm run build                          # vite build
node scripts/verify-vite-manifest.js   # fails the build if a Blade @vite ref is unbuilt
npm test                               # node --test scripts/harness/governor-core.test.mjs
vendor/bin/pint --test                 # repo-wide style gate (CI php-lint job)
composer validate --strict --no-check-publish
composer check-platform-reqs --lock --no-dev   # the nixpacks lockfile/platform guard
composer audit --locked && npm audit --omit=dev # dependency advisories
```

All verified green on this tree (Pint: 710 files after the §H parse-error fix;
`npm test`: 16 pass; manifest guard: "every @vite reference resolves").

### B.4 Dusk browser tests

Where: local (with Chrome) or CI (`ci.yml` *Browser Tests (Dusk)* job). Safe in prod: ❌.

Local recipe — taken verbatim from the verified CI job definition:

```bash
npm run build
chromedriver &                                          # default port 9515
cp .env.example .env && php artisan key:generate
# the CI job then sets: DB sqlite FILE-backed (database/database-dusk.sqlite),
# APP_URL=http://127.0.0.1:8000, SESSION_DRIVER=file, BCRYPT_ROUNDS=4,
# CONTROL_CENTER_ADMINS=cc-sweep@example.com, then `php artisan config:cache`
php artisan serve --port=8000 &
php artisan dusk
```

**UNVERIFIED locally** (no Chrome/ChromeDriver in the authoring environment — the two
Dusk classes, `SmokeTest` and the role/viewport `InteractionSweepTest`, are verified to
exist, parse and match the CI recipe). CI runs them on every push; the §H parse-error
fix was required for `dusk` and Pint to even load `tests/Browser/InteractionSweepTest.php`.

### B.5 Known good-but-noisy results (do not chase)

- `SculptureGardenAndCspTest::test_every_garden_manifest_role_ships_its_glb` fails when
  `public/assets/venues/sculpture-garden/*.glb` are absent from the checkout. Those
  binary assets are intentionally not shipped in source archives and exist in
  production (verified failure text names the missing GLB). On a full checkout / CI
  checkout with assets present it passes.
- A local `sitemap.xml` may 500 when serving against a **SQLite file database** outside
  the test harness: the sitemap query uses `CHAR_LENGTH` (MySQL-native; the test
  harness registers a SQLite shim). Production runs MySQL — verify sitemap there, not
  on a local sqlite serve.
- `qa:run pre_release`/`full_regression` print 4 harmless PHPUnit warnings about the
  four `tests/Unit/Ops*.php` files being matched twice (Unit directory + `Ops*` glob in
  the operations group). Duplicates are skipped; totals remain honest.

---

## C. Pre-deploy stage (on the release candidate)

1. **Build & asset verification** — already enforced inside the Nixpacks build
   (nixpacks.toml): `npm run build`, `verify-vite-manifest.js`,
   `composer validate --strict`, `composer check-platform-reqs --lock --no-dev`,
   `route:cache`, `view:cache` + `scripts/lint-compiled-views.sh`. A deploy that
   reaches "building" has already passed all of these; if any fails, Coolify shows the
   exact failing line. Verified green locally for every command in this list.
2. **Config preflight against a production-shaped env** (local, against a copy of the
   *production* `.env` with `APP_ENV=production`, `APP_URL=https://…`):

   ```bash
   php artisan exospace:preflight
   ```

   ✅ read (two self-cleaning cache keys). Pass: `Summary: 0 critical, N warnings`,
   exit 0. **Any `✗` critical line must be fixed before deploy** — the container start
   (`docker-start.sh`) runs the same command and hard-fails the container otherwise
   (missing 2Checkout secrets, `APP_DEBUG=true` in prod, empty `TRUSTED_PROXIES`,
   missing business address, unreachable Coolify API, missing `mysqldump`, …).
   Verified locally: with the repo's example env the command completes with
   `0 critical, 9 warnings` (dev-only warnings).
3. **Migration review** (local or CI):

   ```bash
   php artisan migrate:status          # against a staging/prod-shaped DB
   php artisan qa:run database         # with TEST_MYSQL_* — schema fidelity gate
   ```

   Rollback-relevant migrations are guarded per DR §3.6–3.7 (venue chain migrations
   converge semantically; `down()` removes only what `up()` added).
4. Confirm CI is green on the exact commit being deployed (ci.yml +, for the release
   commit, a manual `Test Profiles` run with `pre_release` + `mysql8`).

---

## D. Coolify post-deploy stage (container terminal)

Opening the terminal: Coolify UI → **Project → Application (Exospace) → Terminal** tab
(opens a shell inside the running app container; same image, same env as the web
runtime). The Coolify **Logs** tab shows the deploy-time output of `docker-start.sh`
(preflight verdict, migration list, "Queue worker supervisor started (PID …)").

Run the following in this order (all ✅ read unless marked). Each takes seconds.

```bash
# 1. Build/runtime sanity
php artisan about                       # env, drivers, cache/session/queue in one view

# 2. Configuration gate (same command the container start ran)
php artisan exospace:preflight
#    Pass: "Summary: 0 critical, N warnings", exit 0.
#    Critical → the container would have refused to start; check what it names.

# 3. Test-environment doctor (works without dev deps; checks runtime surface)
php artisan qa:doctor
#    In the container this intentionally reports PHPUnit "missing" as a CRITICAL —
#    expected under --no-dev; treat every OTHER critical as a real finding.

# 4. In-process health probes (DB / Redis / cache / queue depth / scheduler / disk)
php artisan qa:health
#    Pass: "HEALTHY · 7 checks". Interpretation per check:
#      db-select1          → MySQL reachable
#      cache-roundtrip     → Redis cache put/get/forget
#      redis-ping          → session/cache/queue backing store
#      queue-depth         → failed_jobs depth (warn > 50); pending counts the DB
#                            jobs table (Redis-queued jobs are not counted there)
#      scheduler-heartbeat → age of the `scheduler-last-run` cache stamp written
#                            every minute by the schedule (warn > 75 min)
#      disk-space          → > 500 MB free at storage/

# 5. Same probes, recorded into Control Center
php artisan qa:run production_health

# 6. HTTP smoke against the live URL (read-only; safe)
php artisan qa:run smoke --target=production
#    7 checks: /up, /health, robots.txt (+Sitemap ref), sitemap.xml (XML),
#    /login, /register, hashed build asset referenced on the homepage.
#    Equivalent direct form: php artisan qa:smoke --target-env=production

# 7. Data integrity audit (read-only invariants; exit 1 only on 'fail' severity)
php artisan exospace:verify-data-integrity

# 8. Backup verification (read-only; decrypts the newest artifact per disk)
php artisan exospace:backup:verify
#    Pass: table with "✅ OK" per disk + "All verified backup artifacts are readable…".
#    UNVERIFIED by the author (requires live R2 credentials); command surface,
#    options (--disk=, --file=) and output contract verified from code.

# 9. Scheduler + queue reality checks
php artisan schedule:list               # 38 entries must match routes/console.php
tail -n 40 /app/storage/logs/scheduler.log
#    Fresh timestamps every minute (the every-minute heartbeat prints a line each
#    tick; empty minutes print "No scheduled commands are ready to run.").
stat -c '%y' /app/storage/logs/scheduler.log   # mtime younger than 5 min
php artisan queue:failed                # empty list = healthy
curl -s http://127.0.0.1/up             # in-container reachability
```

Failure → next action, per signal:

| Signal | Meaning | Next |
|---|---|---|
| preflight critical | config regression | fix the named env var in Coolify → redeploy |
| `qa:health` scheduler-heartbeat stale | Coolify scheduled task dead | §E |
| scheduler.log mtime > 5 min | scheduler not appending | §E; also watch for the "Scheduler appears to be down" Slack alert |
| queue:failed growing | worker stuck / jobs erroring | check Logs tab for the worker supervisor restart lines; inspect `php artisan queue:failed` ids; `queue:retry <id>` only after diagnosis |
| verify-data-integrity exit 1 | hard invariant violated | treat as data incident — DR §5 |
| backup:verify "❌" per disk | no usable recovery point | DR §3.2 step 1 |
| smoke sitemap/robots failures | SEO surface broken | `php artisan exospace:seo-audit` + docs/SEO_MANUAL_OPERATIONS.md §5 |

Also confirm the two persistent-volume contracts from the deploy logs: ownership
adjustment lines for `/app/storage/app/public`, `/app/storage/app/private`,
`/app/storage/logs` (docker-start.sh fixes mount writability for the FPM user) and the
"Injected static-asset caching + gzip into nginx template." line.

---

## E. Scheduled tasks — proving the Coolify cron actually works

The scheduler contract (all verified from `routes/console.php` + `docker-start.sh` +
`OperationalAlertService`):

- The Coolify **scheduled task** runs `php artisan schedule:run >> /app/storage/logs/scheduler.log 2>&1`
  every minute (`BYPASS_SCHEDULER=true` disables the in-container loop, so this task is
  the only scheduler).
- Laravel decides per-minute what is due (38 registered tasks — verified via
  `schedule:list`).
- Every tick stamps the `scheduler-last-run` cache key (`scheduler-heartbeat`, every
  minute) → consumed by `qa:health`.
- `OperationalAlertService` (every 5 min via `operational-alerts`) raises **critical**
  Slack alerts when `scheduler.log` mtime is older than 5 minutes, or a job heartbeat
  (`exospace:backup:db` 36 h, `:files` 192 h, `:clean` 36 h, daily jobs 36 h, weekly
  192 h — `JobHeartbeatService::MONITORED_JOBS`) goes stale.
- `exospace:cleanup-stale` (04:00) rotates `scheduler.log` at 10 MB / 5 generations —
  the only rotation when `BYPASS_SCHEDULER=true`.

Verification steps (all ✅ read unless noted):

1. **Task exists and ticks**: Coolify → App → **Scheduled Tasks** → the task's last run
   timestamp updates every minute; container terminal: `stat -c '%y' /app/storage/logs/scheduler.log`
   younger than 5 min, and `qa:health` reports `scheduler-heartbeat age≈0–1 min`.
2. **Force-run ONE scheduled command now** (✅ runs that task immediately; the task
   itself determines write-safety — the examples below are safe/read-only):

   ```bash
   php artisan schedule:test --name='exospace:cleanup-stale'
   php artisan schedule:test --name='scheduler-heartbeat'
   ```

   `--name` must match the schedule entry **without** the `php artisan` prefix
   (verified: `--name='php artisan exospace:cleanup-stale'` finds nothing; the bare
   command name matches). Output: `Running ['artisan' …] DONE` plus the spawned
   command line. `schedule:test` with no `--name` opens an interactive picker.
3. **Verify the effect** of a forced run, per task:
   - `exospace:cleanup-stale` → "Cleanup complete" summary; scheduler.log rotation
     policy re-applied.
   - `exospace:reconcile-subscriptions --dry-run` (direct, not via scheduler) →
     drift report without changes.
   - `sitemap:warm` → cache keys warmed; then `curl -s https://exospace.gallery/sitemap.xml | head` is instant.
   - `ops:send-morning-digest` → message lands in the ops Slack channel (§F.3).
4. **Watchdog self-test** (only on staging): stop the Coolify scheduled task for >10
   minutes and confirm the critical "Scheduler appears to be down" Slack alert fires
   (dedup key `scheduler_stale`), then re-enable. UNVERIFIED by the author (requires a
   staging scheduler to stop); the watchdog code path (`checkSchedulerHealth`) is
   verified in `OperationalAlertService`.

---

## F. External end-to-end manual checks (pass/fail checklist)

Each item: action → where → pass criteria → failure next step. These need live
third-party dashboards; none could be executed by the author (marked UNVERIFIED) — the
commands/URLs come from the project's own code and configuration surface.

### F.1 Billing — 2Checkout sandbox purchase

1. Sandbox account (2Checkout/Verifone sandbox dashboard): create a test order for the
   Pro product (`TWOCHECKOUT_PRODUCT_ID_PRO`) using sandbox payment credentials.
   **UNVERIFIED** (external dashboard).
2. Pass: user plan flips to Pro; `transactions` row created with matching
   `invoice_ref`; invoice PDF appears under `/billing` and downloads
   (`/billing/invoice/{id}` — served from the **private** disk, DR §3.8).
3. Webhook receipt: super-admin → Billing/webhooks ledger shows the IPN processed
   (`processed_webhooks`); duplicate IPN replays are deduped (idempotency is covered by
   `tests/Feature/WebhookBillingTest.php`).
4. Failure: signature verification rejects → check `TWOCHECKOUT_SECRET_WORD` and the
   webhook IP allow-list env; the ledger marks the attempt `failed`.
5. Renewal / cancel / refund: exercise each in the sandbox; entitlements must follow
   (renewal extends `plan_expires_at`, cancel downgrades at period end, refund flips
   the transaction state + entitlement). Verified behaviors: `BillingCancelAndRenewalTest`,
   `SubscriptionReconciliationTest`, `SignedBuyLinkAndTrialFraudTest` (all passing).
   Drift repair lives in `exospace:reconcile-subscriptions` (`--dry-run` first).
6. Invoice PDF: open one sandbox invoice, confirm VAT/address block renders
   (`EXOSPACE_BUSINESS_ADDRESS`, `EXOSPACE_TAX_DEFAULT_COUNTRY`, supplier country US —
   `TaxComplianceTest` pins the logic).

### F.2 Email — Resend

1. From/reply-to: register a user + trigger welcome/verify/password-reset mail; check
   headers: `From:` = `MAIL_FROM_ADDRESS` (`noreply@exospace.gallery`), friendly name
   `MAIL_FROM_NAME`; reply-to per mailable (`EmailReplyToTest` pins this — passing).
2. Unsubscribe: marketing mail carries List-Unsubscribe (RFC 8058 one-click;
   `Rfc8058UnsubscribeTest` pins it). Click the link → `/unsubscribe` page → status
   persisted; one-click POST → verified page.
3. Deliverability smoke (UNVERIFIED — needs the Resend dashboard): send one real mail
   to an external inbox, confirm no spam placement, correct domain alignment.
4. Failure: `preflight` names Resend misconfigurations (`re_` key prefix check);
   queue depth for mail jobs → check `queue:failed`.

### F.3 Slack alerting — normal AND critical channels

Two webhooks exist by design: `OPERATIONAL_ALERT_WEBHOOK` (normal) and
`OPERATIONAL_ALERT_CRITICAL_WEBHOOK` (critical severity) — `AlertWebhookRoutingTest`
pins the routing (passing).

Force a real message through each channel (staging first, production acceptable —
messages are deduped per key):

```bash
php artisan tinker --execute="app(App\Services\OperationalAlertService::class)->alert('Runbook verification — normal channel', 'Manual test message', 'warning', 'runbook-verify-'.str()->random(4));"
php artisan tinker --execute="app(App\Services\OperationalAlertService::class)->alert('Runbook verification — critical channel', 'Manual test message', 'critical', 'runbook-verify-'.str()->random(4));"
```

Pass: first lands in the normal channel, second in the critical channel. (Both tinker
one-liners are safe: they only post a Slack message and write a dedup cache key.)
Failure: wrong/no message → check the two webhook env vars and that the Ops
escalation channel config (`OPS_ESCALATION_WEBHOOK`) isn't intercepting.

### F.4 Sentry — test event + PII scrubbing

```bash
php artisan tinker --execute="app(App\Ops\Services\OpsExceptionReporter::class)->record(new RuntimeException('runbook sentry probe — no PII after this point: email/user@example.com'));"
```

Then Sentry dashboard (`SENTRY_ENVIRONMENT=production` project): the issue appears.
Pass criteria: message visible; **no user PII** in the event payload beyond the
redactor's output (`LogRedactor` scrubs emails/ips/creds — `OpsLogRedactorTest` pins
it). Also flip `SENTRY_TRACES_SAMPLE_RATE` stays 0.0 in prod (perf traces off).
UNVERIFIED end-to-end (needs the live Sentry project); the reporter plumbing is
verified by `OpsSentry*Test` (passing).

### F.5 Backups — R2 upload + full restore drill into staging

Daily 01:00 `exospace:backup db`, weekly Sun 01:30 `exospace:backup files`, both write
AES-256 encrypted zips to every disk in `BACKUP_DISKS` and verify the artifact before
stamping the heartbeat (DR §1, §4).

1. Upload proof (production container): `php artisan exospace:backup:verify` →
   `✅ OK` per disk; R2 dashboard → bucket shows the newest `YYYY-MM-DD-HH-MM-SS.zip`.
   Also confirm Master Control → Backup tile is green (`MasterControlBackupTileTest`).
2. Full restore drill → **staging only** (❌ never against production): follow DR
   §3.2 step-by-step — plan mode first (`exospace:backup:restore --disk=r2 --only-db`),
   then `--execute --confirm=RESTORE` inside a maintenance window, then DR §5
   verification (`exospace:backup:verify`, preflight, `qa:doctor`,
   `VenueEnvironmentAuthorityTest`, one render per venue family, one invoice download,
   Slack digest resumed).
   **UNVERIFIED by the author** (requires live R2 + a staging clone); the restore
   command's plan/execute/confirm contract is verified from `RestoreFromBackup` code
   and its passing tests (`RestoreFromBackupTest`).

### F.6 Custom domain flow (Studio plan)

1. Studio user sets a custom domain in gallery settings → app stores
   `custom_domain` + issues verification (`CustomDomainVerificationTest` pins DNS
   verification + `VerifyCustomDomain` job).
2. `exospace:verify-pending-domains` (hourly, or force via
   `php artisan schedule:test --name='exospace:verify-pending-domains'`) dispatches
   verification; Coolify API (token/base-url/uuid from env) adds the domain.
3. Pass: DNS TXT/`_verify` record → gallery served on the new domain with valid TLS
   (Coolify proxy issues cert); `exospace:preflight` Coolify ping reports the new
   domain count.
4. Failure: 401 from Coolify → token; 404 → wrong `COOLIFY_APPLICATION_UUID`.

### F.7 SSL / HTTPS / cookies

- `https://exospace.gallery` serves with a valid cert; HTTP → HTTPS redirect.
- Session cookie: `Secure`, `HttpOnly`, `SameSite=lax` (preflight criticals pin all
  three); `SESSION_DOMAIN` empty/unset in production unless a subdomain strategy is
  intended.
- Security headers on HTML responses (CSP, X-Frame-Options, X-Content-Type-Options) —
  `SecurityHeadersPolicyTest` / `CspSecurityHeaderRegressionTest` pin them; spot-check
  with `curl -sI https://exospace.gallery | head`.

### F.8 Maintenance mode drill (staging first, then a 60-second production window)

```bash
php artisan down     # ⚠️ writes the maintenance flag (cache driver in prod)
curl -s -o /dev/null -w '%{http_code}\n' https://exospace.gallery/   # expect 503, branded page
php artisan up
```

Pass: branded 503 (test `maintenance_mode_renders_branded_503_page` pins the page),
retry-after honored when present. Scheduler + queue keep running while down (schedule
runs in maintenance mode only for maintenance-tolerant tasks — the tick itself is
unaffected).

### F.9 SEO surfaces

```bash
php artisan qa:run smoke --target=production   # robots + sitemap checks included
php artisan exospace:seo-audit                 # full audit; --slack posts even minors
```

Pass: robots.txt carries `Sitemap:`, `/sitemap.xml` returns XML 200, sitemap groups
render (events/artists/venues), no redirect loops (`SeoRedirectLoopProtectionTest`).
Ongoing manual SEO ops → `docs/SEO_MANUAL_OPERATIONS.md` (§1 GSC/Bing setup, §5
routine).

### F.10 Upload ceiling (50 M)

As a Pro/Studio user, upload a ~49 MB gallery image and a ~49 MB venue preview model.
Pass: upload succeeds; Laravel validation remains the authority at 50 M (transport
ceiling is 64 M — php-fpm `-d` flags + nginx `client_max_body_size 64M`, both patched
by `docker-start.sh`; DR §3.8 explains the three layers). Oversized → clean Laravel
validation error, not a PHP warning. Failure: check the three env layers
(`NIXPACKS_PHP_UPLOAD_MAX_FILESIZE`, `PHP_UPLOAD_MAX_FILESIZE`, `PHP_POST_MAX_SIZE`)
match DR §3.8.

### F.11 Mobile 3D gallery check

On a real phone (or Chrome devtools device emulation, 390×844): open a published
gallery → three.js scene renders, drag/orbit works, focus/tour buttons respond, no
console errors, back/forward navigation intact. The automated proxy is
`tests/Browser/InteractionSweepTest` (mobile viewport sweep — runs in CI Dusk; see
§B.4). Manual pass/fail is subjective → record result in the go-live gate (§G).
UNVERIFIED by the author (no device).

---

## G. Go-live gate

Work top-down; **all boxes must be green**. Where a step maps to an earlier section,
the criteria live there.

1. ☐ CI green on the deploy commit (ci.yml all jobs) — §B.
2. ☐ `qa:doctor` READY locally; full suite `OK (2774 tests)` (asset caveat §B.5) — §B.
3. ☐ `qa:run pre_release` PASSED (+ `database` profile against TEST_MYSQL before any
   schema-touching deploy) — §B.2.
4. ☐ `exospace:preflight` 0 critical against the production env — §C.2.
5. ☐ Nixpacks build passes (manifest guard + view lint + lockfile guard are inside it) — §0/§C.
6. ☐ Post-deploy container checklist §D steps 1–9 all green (preflight / qa:health
   HEALTHY / smoke passed / integrity clean / backup:verify OK / scheduler.log fresh /
   queue:failed empty).
7. ☐ Scheduler task ticking + one forced `schedule:test` executed — §E.
8. ☐ Slack normal + critical probes delivered; morning digest (08:15) received — §F.3.
9. ☐ Sentry probe visible, PII-scrubbed — §F.4.
10. ☐ Backup: newest artifact verified on local **and** r2 — §F.5 (restore drill may
    follow within the first week on staging; DR §3.2).
11. ☐ 2Checkout sandbox purchase + webhook + invoice — §F.1.
12. ☐ Resend from/reply-to/unsubscribe — §F.2.
13. ☐ Custom-domain + SSL + cookies + headers — §F.6/§F.7.
14. ☐ Maintenance drill done and the site is `up` — §F.8.
15. ☐ Sitemap/robots + `exospace:seo-audit` clean — §F.9.
16. ☐ 50 M upload and mobile 3D walkthrough — §F.10/§F.11.

**Rollback procedure** (≤ 15 min target, DR §3.1):

1. Coolify → App → Deployments → **Redeploy previous successful build** (the image is
   already built; rollback does not rebuild).
2. If the rolled-back deploy added migrations: container terminal
   `php artisan migrate:status` — venue migrations are guarded; do NOT hand-rollback
   venue chain migrations (DR §3.6).
3. Hard-refresh one admin Live Preview panel (iframe bundle caching, DR §3.1 step 3).
4. Re-run §D steps 2–7 on the rolled-back container.

**First 24 hours monitoring plan** — everything rides the existing signals (DR §6):

| When | Check | Tool |
|---|---|---|
| +15 min | §D checklist once more (esp. `qa:health`, smoke) | container terminal |
| Hourly | scheduler.log freshness + `queue:failed` | container terminal / Coolify logs |
| Hour 1–2 | first scheduled jobs fired (04:00 batch if overnight): heartbeats fresh | `qa:health`, Slack silence = healthy |
| Morning | **ops morning digest 08:15 received** (health score, incidents, backups, Sentry trend) | Slack |
| Continuous | Sentry issues (PII-scrubbed), critical Slack channel, heartbeat alerts (backup:db stale > 36 h, scheduler dead > 5 min) | dashboards |
| Optional pull | `/metrics?token=<METRICS_TOKEN>` JSON or `?format=prometheus` (queue depth, failed jobs, storage) | external monitor |
| Daily | `exospace:backup:verify` green | container terminal |

A silent Slack is itself an outage signal (DR §5): the 08:15 digest must arrive.

---

## H. Fixes applied while verifying this runbook (all verified before/after)

| # | File | Defect | Fix |
|---|---|---|---|
| 1 | `.env.example` | `SESSION_DOMAIN=null` became the literal string `"null"` at runtime → `qa:doctor` CRITICAL + every `qa:run` BLOCKED ("Suspicious SESSION_DOMAIN=null…") with the stock CI env | value removed with an explanatory comment |
| 2 | `phpunit.xml` | `APP_DEBUG` unpinned → with the documented `.env.example` setup, 500s rendered through Ignition, breaking branded-error-page/redaction tests | `<env name="APP_DEBUG" value="false" force="true"/>` |
| 3 | `tests/Feature/MultiDiskBackupConfigTest.php` | `putenv`+`refreshApplication` raced Laravel's dotenv (immutable writer records the var as "loaded" and clobbers the override on the next boot) → 3 deterministic failures | env mirrored into `$_SERVER/$_ENV/putenv` + config reload (the file's own convention); no app reboot |
| 4 | `app/Console/Commands/QaRunProfile.php` | `--no-record` declared but never honored | honored across blocked/not-ready/probe/phpunit paths with an unsaved summary model |
| 5 | `.github/workflows/test-profiles.yml` | CI never provisioned the default-connection record store → first `record()` died on unknown database/missing `qa_test_runs` **after** tests ran; the parent process also inherited redis drivers (no Redis service in CI) breaking `Cache::lock()` and the doctor's session-poison tripwire; and both env-append heredocs used an indented terminator bash never matches, silently swallowing the step body | provisioning step (CREATE DATABASE + `migrate --force`) before Doctor; parent env isolated to testing/array/sync drivers; heredocs replaced with echo appends |
| 6 | `app/Console/Commands/QaRunProfile.php`, `config/test-center.php` | PHPUnit subprocess inherited the CLI `memory_limit` default → full profiles OOM mid-run and truncated the JUnit artifact | subprocess carries `memory_limit` (default 2G, `QA_PHPUNIT_MEMORY_LIMIT`), matching CI's documented requirement |
| 7 | `app/Console/Commands/QaRunProfile.php`, `tests/TestCase.php` | MySQL-fidelity passes were force-downgraded to sqlite `:memory:` by the generated suite XML, while unconfigured-mysql runs leaked mysql `DB_*` into the child; TestCase's eager sqlite `getPdo()` then failed every setUp | DB_* forcing now scoped to sqlite-based passes, TEST_MYSQL-less mysql preference degrades to sqlite (mirroring the announced fallback), TestCase skips unconnectable sqlite connections |

`php artisan dusk` and the CI `php-lint` job additionally required the missing `.` in
`tests/Browser/InteractionSweepTest.php`'s `ONE_PIXEL_JPEG` concatenation (parse
error) — fixed and Pint-formatted; the decoded constant is byte-identical to the
intended 1×1 JPEG.
