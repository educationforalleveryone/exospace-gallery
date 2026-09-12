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

    public function index(Request $request)
    {
        $users = User::withCount('galleries')
            ->orderBy('created_at', 'desc')
            ->paginate(50);

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

        $onboardingDays = (int) $request->query('days', 30);
        if (! in_array($onboardingDays, [7, 30, 90], true)) {
            $onboardingDays = 30;
        }
        $metrics = app(\App\Services\OnboardingMetricsService::class);
        $onboarding = $metrics->snapshot($onboardingDays);

        $onboardingTrend = $metrics->trend($onboardingDays, 26);

        $releaseAnnotations = [];
        if (count($onboardingTrend) >= 2) {
            $firstCapture = \Carbon\Carbon::parse($onboardingTrend[0]['captured_on'] ?? now()->toDateString());
            $lastCapture = \Carbon\Carbon::parse(end($onboardingTrend)['captured_on'] ?? now()->toDateString());
            $releaseAnnotations = \App\Services\ReleaseCalendar::between(
                $firstCapture->copy()->subDays(7),
                $lastCapture,
            );
        }

        $retentionMetrics = app(\App\Services\CohortRetentionMetricsService::class);
        $retention = $retentionMetrics->matrix(8);
        $retentionTrendW1 = $retentionMetrics->trend(1, 26);
        $retentionTrendW2 = $retentionMetrics->trend(2, 26);

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

        $heartbeats = app(\App\Services\JobHeartbeatService::class);
        $backupTypes = ['db' => 'exospace:backup:db', 'files' => 'exospace:backup:files', 'clean' => 'exospace:backup:clean'];
        $backupHealth = ['worst' => 'fresh', 'types' => []];
        $anyObserved = false;
        $worstRank = ['fresh' => 0, 'stale' => 1, 'missing' => 2];
        foreach ($backupTypes as $key => $hbKey) {
            $status = $heartbeats->status($hbKey);
            $lastAt = $heartbeats->lastRunAt($hbKey);
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

    public function updatePlan(Request $request, User $user)
    {
        $this->preventSelfAction($user, 'change the plan of');

        $request->validate(['plan' => 'required|in:free,pro,studio']);

        $plan    = $request->plan;
        $oldPlan = $user->plan;
        $limits  = User::planLimits($plan);

        $result = $this->planLock->withUserLock($user->id, function () use ($user, $plan, $oldPlan, $limits) {
            $user->refresh();
            $currentPlan = $user->plan;

            if ($plan === 'free' && $currentPlan !== 'free') {
                app(\App\Services\PlanDowngradeService::class)
                    ->downgradeToFree($user, "Admin plan change ({$currentPlan} → free)");
            } else {
                $user->forceFill([
                    'plan'            => $plan,
                    'max_galleries'   => $limits['max_galleries'],
                    'max_images'      => $limits['max_images'],
                    'plan_started_at' => now(),
                    'plan_expires_at' => null, // Lifetime — matches webhook semantics (task H03)
                ])->save();
            }

            AdminAuditLog::record('plan_changed', $user, ['from' => $oldPlan, 'to' => $plan]);

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

    public function deleteUser(User $user)
    {
        $this->preventSelfAction($user, 'delete');

        $userName = $user->name;
        $userEmail = $user->email;
        $userPlan = $user->plan;

        AdminAuditLog::record('user_deleted', $user, ['email' => $userEmail, 'plan' => $userPlan]);

        app(\App\Services\UserDeletionService::class)
            ->deleteUser($user, 'Admin deletion');

        return redirect()->route('super.index')
                         ->with('success', "User \"{$userName}\" and all their data permanently deleted.");
    }

    public function banUser(Request $request, User $user)
    {
        $this->preventSelfAction($user, 'ban');

        $request->validate(['reason' => 'nullable|string|max:500']);

        $user->forceFill([
            'banned_at'  => now(),
            'ban_reason' => $request->input('reason') ?: 'No reason provided.',
            'remember_token' => null,
        ])->save();

        $sessionsPurged = false;

        if (config('session.driver') === 'database') {
            try {
                \DB::table('sessions')->where('user_id', $user->id)->delete();
                $sessionsPurged = true;
            } catch (\Throwable $e) {
                \Log::warning('banUser: failed to purge sessions', ['user_id' => $user->id, 'error' => $e->getMessage()]);
            }
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
            'sessions_purged' => $sessionsPurged,
            'tokens_revoked' => true,
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

    public function verifyEmail(User $user)
    {
        if ($user->hasVerifiedEmail()) {
            return back()->with('success', "{$user->name}'s email is already verified.");
        }

        $user->markEmailAsVerified();

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

    public function toggleSuperAdmin(User $user)
    {
        $this->preventSelfAction($user, 'change super admin status for');

        if ($user->is_super_admin) {
            $superAdminCount = User::where('is_super_admin', true)->count();
            if ($superAdminCount <= 1) {
                return back()->with('error', "Cannot revoke super admin for {$user->name} — they are the only super-admin. Promote another user to super-admin first.");
            }
        }

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

    public function userGalleries(User $user)
    {
        $galleries = $user->galleries()
            ->withCount('images')
            ->with(['images' => fn($q) => $q->orderBy('position_order')->limit(10)])
            ->latest()
            ->paginate(15);

        return view('super-admin.user-galleries', compact('user', 'galleries'));
    }

    public function toggleGallery(Gallery $gallery)
    {
        $oldActive = $gallery->is_active;
        $gallery->update(['is_active' => ! $gallery->is_active]);

        $status = $gallery->is_active ? 'activated' : 'deactivated';

        AdminAuditLog::record('gallery_toggled', $gallery, [
            'from' => $oldActive,
            'to'   => $gallery->is_active,
        ]);

        return back()->with('success', "Gallery \"{$gallery->title}\" {$status}.");
    }

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

    private function preventSelfAction(User $user, string $action): void
    {
        if ($user->id === auth()->id()) {
            abort(403, "You cannot {$action} your own account.");
        }
    }

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
