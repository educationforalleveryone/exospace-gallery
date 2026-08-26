<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Gallery;
use App\Models\AdminAuditLog;
use App\Services\PlanLockService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SystemController extends Controller
{
    public function __construct(
        private readonly PlanLockService $planLock,
    ) {}

    // ── Dashboard ─────────────────────────────────────────────────────────

    public function index(Request $request)
    {
        // FIX: Paginate users instead of loading all at once
        // This prevents N+1 queries and memory issues (OOM)
        $users = User::withCount('galleries')
            ->orderBy('created_at', 'desc')
            ->paginate(50);

        // ITERATION 4: the nine platform stat counts were N sequential
        // full-table scans on EVERY page load (plan has no index pre-Iter-4;
        // gallery_images is the largest table). They move slowly — cache
        // them via Cache::flexible (5 min fresh / 10 min stale-while-
        // revalidate), the same pattern as Admin\DashboardController.
        $stats = \Illuminate\Support\Facades\Cache::flexible(
            'master-control:platform-stats',
            [now()->addMinutes(5), now()->addMinutes(10)],
            fn () => [
                'total_users'     => User::count(),
                'total_galleries' => Gallery::count(),
                'free_users'      => User::where('plan', 'free')->count(),
                'pro_users'       => User::where('plan', 'pro')->count(),
                'studio_users'    => User::where('plan', 'studio')->count(),
                'total_images'    => DB::table('gallery_images')->whereNull('deleted_at')->count(),
                'total_views'     => Gallery::sum('view_count'),
                'banned_users'    => User::whereNotNull('banned_at')->count(),
                'unverified_users'=> User::whereNull('email_verified_at')->count(),
            ],
        );

        // ITERATION 4: onboarding funnel + TTFE surfaced continuously (was
        // weekly-console-report only). Cached 30/60 min by the service —
        // the queries are cohort scans, not something a dashboard refresh
        // should re-run. Period selector: 7 / 30 / 90 days.
        $onboardingDays = (int) $request->query('days', 30);
        if (! in_array($onboardingDays, [7, 30, 90], true)) {
            $onboardingDays = 30;
        }
        $metrics = app(\App\Services\OnboardingMetricsService::class);
        $onboarding = $metrics->snapshot($onboardingDays);

        // ITERATION 5: TTFE history — weekly snapshots persisted by
        // exospace:onboarding-analytics, charted here so the headline
        // metric is a trend, not a point value. Cheap indexed read on a
        // tiny table; deliberately uncached so the trend and the funnel
        // tiles above it can never disagree.
        $onboardingTrend = $metrics->trend($onboardingDays, 26);

        // ITERATION 6: release annotations for the TTFE trend chart —
        // releases from the product's own changelog (ReleaseCalendar,
        // the same data /changelog renders) that fall inside the charted
        // window, so an operator can SEE whether a release moved the
        // headline metric. Only computed when a chart will actually be
        // drawn (>= 2 points); releases outside the window are simply
        // not charted. The window reaches one capture-interval (7 days)
        // before the first point so a release in the week leading into
        // the first data point annotates at index 0 rather than
        // vanishing.
        $releaseAnnotations = [];
        if (count($onboardingTrend) >= 2) {
            $firstCapture = \Carbon\Carbon::parse($onboardingTrend[0]['captured_on'] ?? now()->toDateString());
            $lastCapture = \Carbon\Carbon::parse(end($onboardingTrend)['captured_on'] ?? now()->toDateString());
            $releaseAnnotations = \App\Services\ReleaseCalendar::between(
                $firstCapture->copy()->subDays(7),
                $lastCapture,
            );
        }

        // ITERATION 6: cohort retention — live matrix (cached 30/60 min
        // by the service) + the W1/W2 trend from persisted snapshots
        // (weekly exospace:cohort-retention). Same graduation the TTFE
        // metric got in Iteration 5: stdout-only → visible history.
        $retentionMetrics = app(\App\Services\CohortRetentionMetricsService::class);
        $retention = $retentionMetrics->matrix(8);
        $retentionTrendW1 = $retentionMetrics->trend(1, 26);
        $retentionTrendW2 = $retentionMetrics->trend(2, 26);

        // ITERATION 7: >2σ anomalies on the TTFE series — flags weeks
        // that deviate from the trailing mean with no release to blame.
        // Computed only when a chart will draw (>= 2 points); the
        // detector self-guards (min 4 prior non-null points). The
        // payload carries the chart index + z + direction so the
        // inline plugin can ring the right point without re-deriving
        // the math in JS. Same shape as releaseAnnotations (a list of
        // {index, label} objects) so the view treats them symmetrically.
        //
        // ITERATION 8: payload now carries sigma + sigma_eff so the
        // plugin's title attribute can show the operator the trailing
        // mean + σ + z (audit-fix D-4 — they previously couldn't
        // sanity-check the threshold by hand).
        $anomalyAnnotations = [];
        if (count($onboardingTrend) >= 2) {
            $series = array_map(
                static fn (array $p) => $p['ttfe_avg'],
                $onboardingTrend,
            );
            $raw = \App\Services\TrendAnomalies::detect($series);
            foreach ($raw as $a) {
                $anomalyAnnotations[] = [
                    'index'     => $a['index'],
                    'label'     => $onboardingTrend[$a['index']]['captured_at'] ?? '',
                    'value'     => $a['value'],
                    'mean'      => $a['mean'],
                    'sigma'     => $a['sigma'],
                    'sigma_eff' => $a['sigma_eff'],
                    'z'         => $a['z'],
                    'direction' => $a['direction'],
                ];
            }
        }

        // ITERATION 8: >2σ anomalies on the W1 + W2 retention series —
        // same algorithm, same flat-baseline guard. Reuses the general-
        // purpose detector shipped in Iter-7 (no algorithm change). The
        // view's retention-trend plugin rings low-retention points amber
        // (worse — churn up) and high-retention points emerald (better —
        // churn down). The chart header gains a ` · N anomal(y|ies)`
        // suffix when present, mirroring the TTFE header.
        //
        // Direction convention note: for TTFE, 'high' = worse (slower
        // onboarding). For retention, 'high' = better (more users came
        // back). The plugin INVERTS the color mapping per series so
        // the visual language stays consistent (amber = bad, emerald =
        // good) regardless of which metric the ring annotates.
        $retentionW1Anomalies = [];
        $retentionW2Anomalies = [];
        if (count($retentionTrendW1) >= 2) {
            $w1Series = array_map(
                static fn (array $p) => $p['retained_pct'],
                $retentionTrendW1,
            );
            foreach (\App\Services\TrendAnomalies::detect($w1Series) as $a) {
                $retentionW1Anomalies[] = [
                    'index'     => $a['index'],
                    'label'     => $retentionTrendW1[$a['index']]['captured_at'] ?? '',
                    'value'     => $a['value'],
                    'mean'      => $a['mean'],
                    'sigma'     => $a['sigma'],
                    'sigma_eff' => $a['sigma_eff'],
                    'z'         => $a['z'],
                    'direction' => $a['direction'],
                ];
            }
        }
        if (count($retentionTrendW2) >= 2) {
            $w2Series = array_map(
                static fn (array $p) => $p['retained_pct'],
                $retentionTrendW2,
            );
            foreach (\App\Services\TrendAnomalies::detect($w2Series) as $a) {
                $retentionW2Anomalies[] = [
                    'index'     => $a['index'],
                    'label'     => $retentionTrendW2[$a['index']]['captured_at'] ?? '',
                    'value'     => $a['value'],
                    'mean'      => $a['mean'],
                    'sigma'     => $a['sigma'],
                    'sigma_eff' => $a['sigma_eff'],
                    'z'         => $a['z'],
                    'direction' => $a['direction'],
                ];
            }
        }

        // ITERATION 9 — funnel-stage conversion-rate trend + >2σ anomalies
        // per stage. The 5-bar funnel on the dashboard is a point value
        // (one window); a sudden stage drop ("this week only 10% of new
        // signups created a gallery vs the 30% trailing avg") is invisible
        // without a trend. The onboarding trend already persists per-stage
        // counts in the snapshot table (registered, created_gallery,
        // uploaded_image, published, got_views); workstream A exposes them
        // via OnboardingMetricsService::trend() (audit-fix E-2) and
        // computes the 4 stage-conversion rates per snapshot:
        //
        //   S1: created_gallery / registered        — did they start a gallery?
        //   S2: uploaded_image / created_gallery   — did they add art?
        //   S3: published / uploaded_image         — did they ship?
        //   S4: got_views / published               — did anyone see it?
        //
        // Each rate is a 0..100 percentage. TrendAnomalies::detect runs on
        // each series (it self-guards on MIN_PRIORS so a fresh install
        // returns []). Direction convention matches the retention chart
        // (high = better = emerald; low = worse = amber) — a stage-rate
        // drop is the bad outcome we want to ring.
        //
        // The output shape is a list of stages; each stage has a `series`
        // (one rate per snapshot, oldest-first), an `anomalies` list (each
        // entry mirrors the shape the TTFE plugin expects: {index, value,
        // mean, sigma, sigma_eff, z, direction, label}), and metadata
        // (key, label, color, from/to stage labels). The view's per-stage
        // tooltip override plugin (workstream C) uses the metadata to
        // render the breakdown when hovering a ringed point.
        $funnelStageTrend = [];
        if (count($onboardingTrend) >= 2) {
            $stages = [
                's1' => ['label' => 'Registered → Created gallery', 'from' => 'registered',       'to' => 'created_gallery', 'color' => '#60a5fa'],
                's2' => ['label' => 'Created gallery → Uploaded image', 'from' => 'created_gallery','to' => 'uploaded_image',  'color' => '#a78bfa'],
                's3' => ['label' => 'Uploaded image → Published',     'from' => 'uploaded_image', 'to' => 'published',       'color' => '#34d399'],
                's4' => ['label' => 'Published → Got first view',      'from' => 'published',      'to' => 'got_views',       'color' => '#fbbf24'],
            ];
            foreach ($stages as $key => $meta) {
                $series = [];
                foreach ($onboardingTrend as $p) {
                    $denominator = (int) ($p[$meta['from']] ?? 0);
                    $numerator   = (int) ($p[$meta['to']]   ?? 0);
                    // A 0-denominator stage is a null rate (no users
                    // reached the prior stage that week). TrendAnomalies
                    // skips nulls in its trailing window so a fresh install
                    // with sparse snapshots doesn't false-positive.
                    $series[] = $denominator > 0 ? round(($numerator / $denominator) * 100, 1) : null;
                }
                $anomalies = [];
                foreach (\App\Services\TrendAnomalies::detect($series) as $a) {
                    $anomalies[] = [
                        'index'     => $a['index'],
                        'label'     => $onboardingTrend[$a['index']]['captured_at'] ?? '',
                        'value'     => $a['value'],
                        'mean'      => $a['mean'],
                        'sigma'     => $a['sigma'],
                        'sigma_eff' => $a['sigma_eff'],
                        'z'         => $a['z'],
                        'direction' => $a['direction'],
                    ];
                }
                $funnelStageTrend[] = [
                    'key'        => $key,
                    'label'      => $meta['label'],
                    'color'      => $meta['color'],
                    'series'     => $series,
                    'anomalies'  => $anomalies,
                ];
            }
        }

        // ITERATION 8: backup health tile on Master Control — surfaces
        // the worst of the three backup heartbeat statuses (fresh /
        // stale / missing) as an at-a-glance tile, so an operator sees
        // backup state without waiting for a Slack page. The data is
        // the SAME the heartbeat monitor already reads from cache (no
        // new queries); the tile is just the operator-facing surface.
        //
        // Convention: missing > stale > fresh for "worst". A fresh
        // install with no stamps AND no acks (never observed missing)
        // shows the tile hidden (the heartbeat monitor hasn't started
        // tracking yet — same convention as the missing-job grace
        // window in OperationalAlertService).
        $heartbeats = app(\App\Services\JobHeartbeatService::class);
        $backupTypes = ['db' => 'exospace:backup:db', 'files' => 'exospace:backup:files', 'clean' => 'exospace:backup:clean'];
        $backupHealth = ['worst' => 'fresh', 'types' => []];
        $anyObserved = false;
        $worstRank = ['fresh' => 0, 'stale' => 1, 'missing' => 2];
        foreach ($backupTypes as $key => $hbKey) {
            $status = $heartbeats->status($hbKey);
            $lastAt = $heartbeats->lastRunAt($hbKey);
            // A job that has been acked-missing OR has stamped at least
            // once is "observed" — the monitor is tracking it.
            if ($status !== 'missing' || $heartbeats->firstObservedMissingAt($hbKey) !== null) {
                $anyObserved = true;
            }
            $backupHealth['types'][$key] = [
                'status'  => $status,
                'last_at' => $lastAt?->diffForHumans(),
                'label'   => ucfirst($key),
            ];
            if ($worstRank[$status] > $worstRank[$backupHealth['worst']]) {
                $backupHealth['worst'] = $status;
            }
        }
        // Hide the tile entirely on a fresh install where the heartbeat
        // monitor hasn't started tracking any backup job yet — same
        // convention as OperationalAlertService::checkJobHeartbeats(),
        // which has a first-observation grace window. The Iter-7
        // backup-wrapper docs explicitly say: "for the first 36h after
        // deploy, the monitor's missing-job grace window applies (no
        // false alert)." The tile mirrors that.
        $backupHealth['show'] = $anyObserved;

        return view('super-admin.index', compact(
            'users',
            'stats',
            'onboarding',
            'onboardingDays',
            'onboardingTrend',
            'releaseAnnotations',
            'retention',
            'retentionTrendW1',
            'retentionTrendW2',
            'anomalyAnnotations',
            'retentionW1Anomalies',
            'retentionW2Anomalies',
            'backupHealth',
            'funnelStageTrend',
        ));
    }

    // ── Update plan ───────────────────────────────────────────────────────

    public function updatePlan(Request $request, User $user)
    {
        $this->preventSelfAction($user, 'change the plan of');

        $request->validate(['plan' => 'required|in:free,pro,studio']);

        $plan    = $request->plan;
        $oldPlan = $user->plan;
        $limits  = User::planLimits($plan);

        // P3-11 FIX: Acquire per-user plan lock so an admin-initiated plan
        // change can't race with a concurrent webhook upgrade or a user-
        // initiated upgrade via BillingController. Without this, the
        // last-writer-wins UPDATE could leave the user on the wrong plan
        // (e.g. admin downgrades to free, webhook upgrades to pro 50ms later,
        // final state is pro — defeating the admin action).
        $result = $this->planLock->withUserLock($user->id, function () use ($user, $plan, $oldPlan, $limits) {
            // Re-fetch the user inside the lock — a concurrent path may have
            // already changed their plan.
            $user->refresh();
            $currentPlan = $user->plan;

            if ($plan === 'free' && $currentPlan !== 'free') {
                // Downgrade path — use PlanDowngradeService so Studio-only
                // resources (custom_domain, branding files) are cleaned up,
                // not just the plan column flipped (task C05).
                app(\App\Services\PlanDowngradeService::class)
                    ->downgradeToFree($user, "Admin plan change ({$currentPlan} → free)");
            } else {
                // Upgrade or lateral move — no cleanup needed. Use forceFill
                // because plan / max_* / plan_* are guarded columns (task C09).
                //
                // (Task H03) Plan-expiry semantics: webhook-granted plans are
                // lifetime (plan_expires_at = null). Admin-granted plans now
                // match that semantic — previously they expired in 1 year via
                // CheckPlanExpiry middleware, which contradicted the pricing
                // page's "lifetime access, no subscription" promise and silently
                // downgraded customers a year later.
                //
                // If you actually want expiring plans (e.g. for promotional
                // grants), set plan_expires_at explicitly via forceFill here
                // and document the expiry in the admin form.
                $user->forceFill([
                    'plan'            => $plan,
                    'max_galleries'   => $limits['max_galleries'],
                    'max_images'      => $limits['max_images'],
                    'plan_started_at' => now(),
                    'plan_expires_at' => null, // Lifetime — matches webhook semantics (task H03)
                ])->save();
            }

            AdminAuditLog::record('plan_changed', $user, ['from' => $oldPlan, 'to' => $plan]);

            // Send confirmation email to the user for upgrade path (task H03).
            // Downgrade path goes through PlanDowngradeService which already
            // handles notification internally (via the downgrade log entry).
            if ($plan !== 'free' && $oldPlan !== $plan) {
                try {
                    \Illuminate\Support\Facades\Mail::to($user->email)
                        ->send(new \App\Mail\PlanUpgradedEmail($user, $plan, null));
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning('SystemController: PlanUpgradedEmail send failed', [
                        'user_id' => $user->id,
                        'error'   => $e->getMessage(),
                    ]);
                }
            }
        });

        if ($result === \App\Services\PlanLockService::LOCK_BUSY) {
            return back()->with('warning', "Another billing operation is in progress for {$user->name}. Please wait a moment and try again.");
        }

        return back()->with('success', "Plan updated to {$plan} for {$user->name}.");
    }

    // ── Delete user ───────────────────────────────────────────────────────

    public function deleteUser(User $user)
    {
        $this->preventSelfAction($user, 'delete');

        $userName = $user->name;
        $userEmail = $user->email;
        $userPlan = $user->plan;

        AdminAuditLog::record('user_deleted', $user, ['email' => $userEmail, 'plan' => $userPlan]);

        // Delegate to UserDeletionService — same code path as the self-serve
        // ProfileController::destroy. Handles file cleanup (images, audio,
        // logos, artist portraits), Coolify custom-domain removal, owned-team
        // cleanup, and the final user row delete. (Tasks C05 + C10.)
        app(\App\Services\UserDeletionService::class)
            ->deleteUser($user, 'Admin deletion');

        return redirect()->route('super.index')
                         ->with('success', "User \"{$userName}\" and all their data permanently deleted.");
    }

    // ── Ban / Unban ───────────────────────────────────────────────────────

    public function banUser(Request $request, User $user)
    {
        $this->preventSelfAction($user, 'ban');

        $request->validate(['reason' => 'nullable|string|max:500']);

        $user->forceFill([
            'banned_at'  => now(),
            'ban_reason' => $request->input('reason') ?: 'No reason provided.',
            // Invalidate any remember-me cookie — SessionGuard re-auths
            // from this token, so it must not outlive the ban.
            'remember_token' => null,
        ])->save();

        // ITERATION-1 P0 FIX (ban enforcement): kill every live access path
        // at ban time — this is the authoritative enforcement point.
        // Previously banning only set banned_at; the CheckBanned middleware
        // was supposed to block the user, but it ran before StartSession
        // (see bootstrap/app.php) and never saw session-authenticated
        // users, so a banned user's sessions, remember-me cookies and API
        // tokens all kept working.
        //   - sessions: the remember-me cookie can re-authenticate the user
        //     even after their session rows are gone, so the cookie is
        //     invalidated too (by deleting its server-side series).
        //   - tokens: Sanctum bearer tokens are revoked outright.
        try {
            \DB::table('sessions')->where('user_id', $user->id)->delete();
        } catch (\Throwable $e) {
            \Log::warning('banUser: failed to purge sessions', ['user_id' => $user->id, 'error' => $e->getMessage()]);
        }
        try {
            \DB::table('personal_access_tokens')
                ->where('tokenable_type', User::class)
                ->where('tokenable_id', $user->id)
                ->delete();
        } catch (\Throwable $e) {
            \Log::warning('banUser: failed to revoke API tokens', ['user_id' => $user->id, 'error' => $e->getMessage()]);
        }

        AdminAuditLog::record('user_banned', $user, [
            'reason'         => $request->input('reason') ?: 'No reason provided',
            'sessions_purged' => true,
            'tokens_revoked'  => true,
        ]);

        return back()->with('success', "{$user->name} has been banned.");
    }

    public function unbanUser(User $user)
    {
        $this->preventSelfAction($user, 'unban');

        $user->forceFill([
            'banned_at'  => null,
            'ban_reason' => null,
        ])->save();

        AdminAuditLog::record('user_unbanned', $user);

        return back()->with('success', "{$user->name} has been unbanned.");
    }

    // ── Email verification ────────────────────────────────────────────────

    public function verifyEmail(User $user)
    {
        if ($user->hasVerifiedEmail()) {
            return back()->with('success', "{$user->name}'s email is already verified.");
        }

        $user->markEmailAsVerified();

        // (Task H07 / audit H17) — audit this action. Email verification
        // is security-relevant (verified users can do paid things). Was
        // previously silent.
        AdminAuditLog::record('email_verified', $user);

        return back()->with('success', "{$user->name}'s email manually verified.");
    }

    public function unverifyEmail(User $user)
    {
        $this->preventSelfAction($user, 'unverify email for');

        $user->forceFill(['email_verified_at' => null])->save();

        // (Task H07 / audit H17) — audit this action.
        AdminAuditLog::record('email_unverified', $user);

        return back()->with('success', "{$user->name}'s email verification revoked.");
    }

    // ── Toggle super admin ────────────────────────────────────────────────

    /**
     * D-10 FIX (Iter-004): Two safeguards added.
     *
     * 1. Last-admin guard: if the target user is currently a super-admin
     *    AND they are the ONLY super-admin, refuse to revoke. This prevents
     *    a super-admin from demoting themselves (preventSelfAction blocks
     *    this) OR demoting the only other super-admin, which would lock
     *    the system out of all super-admin access.
     *
     * 2. Cooldown: if the target user was granted super-admin within the
     *    last 24 hours, refuse to revoke. This prevents a compromised
     *    super-admin from granting super-admin to an attacker account and
     *    then immediately revoking their own access (covering tracks).
     *    The cooldown gives other super-admins time to notice the new
     *    grant (via the SendSuperAdminActionAlert email) and respond.
     *
     * The full two-person approval flow (request → second admin approves)
     * is documented as a future recommendation in the Iteration_Report —
     * it's a larger feature requiring a pending_super_admin_grants table
     * and a separate approval route. The last-admin guard + cooldown are
     * the minimum viable protections for this iteration.
     */
    public function toggleSuperAdmin(User $user)
    {
        $this->preventSelfAction($user, 'change super admin status for');

        // D-10 FIX: Last-admin guard.
        // If revoking super-admin AND the target is the only super-admin, refuse.
        if ($user->is_super_admin) {
            $superAdminCount = User::where('is_super_admin', true)->count();
            if ($superAdminCount <= 1) {
                return back()->with('error', "Cannot revoke super admin for {$user->name} — they are the only super-admin. Promote another user to super-admin first.");
            }
        }

        // D-10 FIX: Cooldown on recently-granted super-admin.
        // If the target was granted super-admin within the last 24 hours,
        // refuse to revoke. This prevents a compromised super-admin from
        // granting + revoking in quick succession to cover tracks.
        //
        // We check the admin_audit_logs for the most recent 'super_admin_toggled'
        // action on this user where the new state was is_super_admin=true.
        // If that log entry is less than 24 hours old, refuse.
        if ($user->is_super_admin) {
            $recentGrant = AdminAuditLog::where('target_type', User::class)
                ->where('target_id', $user->id)
                ->where('action', 'super_admin_toggled')
                ->where('created_at', '>=', now()->subHours(24))
                ->orderByDesc('created_at')
                ->first();

            if ($recentGrant) {
                return back()->with('error', "Cannot revoke super admin for {$user->name} — they were granted super-admin less than 24 hours ago. Wait at least 24 hours before revoking (cooldown to prevent track-covering).");
            }
        }

        $user->forceFill(['is_super_admin' => ! $user->is_super_admin])->save();

        AdminAuditLog::record('super_admin_toggled', $user);

        $status = $user->is_super_admin ? 'granted super admin' : 'revoked super admin';

        return back()->with('success', "Super admin access {$status} for {$user->name}.");
    }

    // ── User galleries view ───────────────────────────────────────────────

    public function userGalleries(User $user)
    {
        // S-5 FIX: Paginate instead of loading ALL galleries + ALL images.
        // Previously: $user->galleries()->with(['images' => ...])->get()
        // — a Studio user with 50 galleries × 500 images = 25,000 rows in memory.
        // Now: paginated 15 per page, images limited to first 10 per gallery.
        $galleries = $user->galleries()
            ->withCount('images')
            ->with(['images' => fn($q) => $q->orderBy('position_order')->limit(10)])
            ->latest()
            ->paginate(15);

        return view('super-admin.user-galleries', compact('user', 'galleries'));
    }

    // ── Toggle gallery ────────────────────────────────────────────────────

    public function toggleGallery(Gallery $gallery)
    {
        $oldActive = $gallery->is_active;
        $gallery->update(['is_active' => ! $gallery->is_active]);

        $status = $gallery->is_active ? 'activated' : 'deactivated';

        // (Task H07 / audit H17) — audit this action. A super-admin
        // deactivating a gallery is a material action that should be
        // auditable. Was previously silent.
        AdminAuditLog::record('gallery_toggled', $gallery, [
            'from' => $oldActive,
            'to'   => $gallery->is_active,
        ]);

        return back()->with('success', "Gallery \"{$gallery->title}\" {$status}.");
    }

    // ── Pending Upgrades (Task H67) ──────────────────────────────────────

    public function pendingUpgrades()
    {
        $pendingUpgrades = \App\Models\PendingUpgrade::with('user')
            ->orderByDesc('created_at')
            ->paginate(25);

        return view('super-admin.pending-upgrades', compact('pendingUpgrades'));
    }

    public function manualUpgrade(\App\Models\PendingUpgrade $pending)
    {
        $user = $pending->user;

        if (! $user) {
            return back()->with('error', 'User not found for this pending upgrade.');
        }

        if ($pending->status !== 'pending') {
            return back()->with('error', "This pending upgrade is already {$pending->status}.");
        }

        // Manually upgrade the user (bypasses 2Checkout payment)
        $plan = $pending->plan;
        $limits = User::planLimits($plan);

        $user->forceFill([
            'plan'            => $plan,
            'max_galleries'   => $limits['max_galleries'],
            'max_images'      => $limits['max_images'],
            'plan_started_at' => now(),
            'plan_expires_at' => null, // lifetime
        ])->save();

        // Mark the pending upgrade as converted
        $pending->forceFill(['status' => 'converted'])->save();

        // Record a transaction (manual — no invoice_id from 2Checkout)
        \DB::table('transactions')->insert([
            'user_id'        => $user->id,
            'invoice_id'     => 'MANUAL-' . $pending->id . '-' . time(),
            'sale_id'        => null,
            'product_id'     => $pending->product_id,
            'plan'           => $plan,
            'amount'         => 0.00,
            'currency'       => 'USD',
            'customer_email' => $user->email,
            'customer_name'  => $user->name,
            'status'         => 'manual',
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        AdminAuditLog::record('manual_upgrade', $user, [
            'plan'              => $plan,
            'pending_upgrade_id'=> $pending->id,
        ]);

        return back()->with('success', "Manually upgraded {$user->name} to {$plan}.");
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function preventSelfAction(User $user, string $action): void
    {
        if ($user->id === auth()->id()) {
            abort(403, "You cannot {$action} your own account.");
        }
    }

    // ── M-13: Admin impersonation ─────────────────────────────────────────

    /**
     * Start impersonating a user.
     *
     * Route: POST /master-control/users/{user}/impersonate
     * Middleware: auth, verified, super_admin, mfa, password.confirm, feature_flag:admin_impersonation
     */
    public function impersonate(Request $request, User $user)
    {
        $admin = $request->user();

        // M-14: Check feature flag
        if (! \App\Services\FeatureFlag::isEnabled('admin_impersonation')) {
            abort(404);
        }

        $impersonationService = app(\App\Services\ImpersonationService::class);

        if (! $impersonationService->start($admin, $user)) {
            return redirect()->route('super.index')
                ->with('error', 'Cannot impersonate this user (self, super-admin, or already impersonating).');
        }

        return redirect()->route('admin.dashboard')
            ->with('status', "You are now viewing the site as {$user->name}. Click 'Return to admin' to stop.");
    }

    /**
     * Stop impersonating and return to the admin session.
     *
     * Route: POST /master-control/stop-impersonating
     * Middleware: auth (no super_admin required — the impersonated user
     *             needs to be able to call this, and the ImpersonationService
     *             checks the session key to verify impersonation is active).
     */
    public function stopImpersonating(Request $request)
    {
        $impersonationService = app(\App\Services\ImpersonationService::class);

        if (! $impersonationService->isImpersonating()) {
            return redirect()->route('admin.dashboard')
                ->with('error', 'You are not currently impersonating anyone.');
        }

        $impersonationService->stop();

        return redirect()->route('super.index')
            ->with('status', 'Impersonation ended. You are back to your admin account.');
    }
}
