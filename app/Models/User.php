<?php

namespace App\Models;

use App\Notifications\Auth\ResetPassword;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable implements MustVerifyEmail
{
    use HasFactory, Notifiable, HasApiTokens;

    protected $fillable = [
        'name',
        'email',
        'password',
        'marketing_consent',
        // SEO OS (Iteration 7): acquisition attribution captured at signup.
        'acquisition_channel', 'acquisition_referrer',
        'acquisition_landing_page', 'acquisition_utm', 'acquisition_captured_at',
        'has_password',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'google2fa_secret', // (Task H56) — never expose in JSON
        'mfa_backup_codes',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password'          => 'hashed',
            'is_super_admin'    => 'boolean',
            'plan_expires_at'   => 'datetime',
            'plan_started_at'   => 'datetime',
            'mfa_enabled_at'    => 'datetime',     // (Task H56)
            'google2fa_ts'      => 'integer',
            'inactive_nudged_at'       => 'datetime', // (P0-7) — last inactive-nudge
            'plan_expiry_reminded_at'  => 'datetime', // (P0-7) — last plan-expiry reminder
            'marketing_consent' => 'boolean',      // (P0-3) — CAN-SPAM/GDPR consent
            // SEO OS (Iteration 7): acquisition attribution
            'acquisition_utm'   => 'array',
            'acquisition_captured_at' => 'datetime',
            'mfa_backup_codes'  => 'array',         // (P3-7) — hashed one-time codes
            // M-1: Subscription tracking columns (recurring billing)
            'subscription_cancelled_at' => 'datetime',
            'subscription_ends_at'      => 'datetime',
            // M-9: Dunning tracking columns (failed payment recovery)
            'dunning_last_sent_at'      => 'datetime',
            // M-7: Trial period
            'trial_ends_at'             => 'datetime',
            'has_password'      => 'boolean',
            'password_set_at'   => 'datetime',
            'last_login_at'     => 'datetime',
        ];
    }

    public function hasOAuthProvider(string $provider): bool
    {
        return ! empty($this->{"{$provider}_id"});
    }

    public function linkedOAuthProviders(): array
    {
        $linked = [];
        if ($this->google_id) $linked[] = 'google';
        if ($this->github_id) $linked[] = 'github';
        return $linked;
    }

    // ── D-4 FIX (Iter-004): Password history helper ──────────────────────

    public function isPasswordInHistory(string $password): bool
    {
        $recentHashes = \Illuminate\Support\Facades\DB::table('password_histories')
            ->where('user_id', $this->id)
            ->orderByDesc('id')
            ->limit(5)
            ->pluck('password_hash');

        foreach ($recentHashes as $oldHash) {
            if (\Illuminate\Support\Facades\Hash::check($password, $oldHash)) {
                return true;
            }
        }

        return false;
    }

    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new ResetPassword($token));
    }

    public function sendEmailVerificationNotification(): void
    {
        $this->notify(new \App\Notifications\Auth\VerifyEmail());
    }

    public function storePasswordInHistory(): void
    {
        \Illuminate\Support\Facades\DB::table('password_histories')->insert([
            'user_id'       => $this->id,
            'password_hash' => $this->getOriginal('password'),
            'created_at'    => now(),
        ]);

        $keepIds = \Illuminate\Support\Facades\DB::table('password_histories')
            ->where('user_id', $this->id)
            ->orderByDesc('id')
            ->limit(10)
            ->pluck('id');

        \Illuminate\Support\Facades\DB::table('password_histories')
            ->where('user_id', $this->id)
            ->when($keepIds->isNotEmpty(), fn ($q) => $q->whereNotIn('id', $keepIds))
            ->delete();
    }

    public function isInTrial(): bool
    {
        return $this->trial_ends_at !== null && $this->trial_ends_at->isFuture();
    }

    public function hasUsedTrial(): bool
    {
        return $this->trial_ends_at !== null;
    }

    public function startTrial(string $plan): void
    {
        $limits = self::planLimits($plan);

        $this->forceFill([
            'plan'            => $plan,
            'max_galleries'   => $limits['max_galleries'],
            'max_images'      => $limits['max_images'],
            'plan_started_at' => now(),
            'plan_expires_at' => now()->addDays(14),
            'trial_ends_at'   => now()->addDays(14),
        ])->save();
    }

    public function galleries(): HasMany
    {
        return $this->hasMany(Gallery::class);
    }

    public function createdArtists(): HasMany
    {
        return $this->hasMany(Artist::class, 'created_by');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function pendingUpgrades(): HasMany
    {
        return $this->hasMany(PendingUpgrade::class);
    }

    public function ownedTeams(): HasMany
    {
        return $this->hasMany(Team::class, 'owner_id');
    }

    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(Team::class, 'team_user')
                    ->withPivot('role')
                    ->withTimestamps();
    }

    public function currentTeam()
    {
        if (! $this->current_team_id) {
            return null;
        }

        if ($this->relationLoaded('currentTeam')) {
            return $this->getRelation('currentTeam');
        }

        $team = Team::find($this->current_team_id);

        if (! $team || ! $this->belongsToTeam($team)) {
            return null;
        }

        return $team;
    }

    public function currentTeamRelationship(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'current_team_id');
    }

    public function switchTeam(Team $team): bool
    {
        if (! $this->belongsToTeam($team)) {
            return false;
        }
        $this->forceFill(['current_team_id' => $team->id])->save();
        return true;
    }

    public function belongsToTeam(Team $team): bool
    {
        return $team->isOwner($this) || $team->hasMember($this);
    }

    public function teamRole(Team $team): ?string
    {
        if ($team->isOwner($this)) return 'owner';
        return $team->memberRole($this);
    }

    public static function planLimits(string $plan): array
    {
        return config("plans.limits.{$plan}", config('plans.limits.free'));
    }

    public function isPro(): bool
    {
        return in_array($this->plan, ['pro', 'studio']);
    }

    public function isStudio(): bool
    {
        return $this->plan === 'studio';
    }

    public function hasSubscription(): bool
    {
        return ! empty($this->subscription_id);
    }

    public function hasActiveSubscription(): bool
    {
        return $this->hasSubscription()
            && $this->subscription_status === 'active';
    }

    public function hasCancelledSubscription(): bool
    {
        return $this->hasSubscription()
            && $this->subscription_status === 'cancelled'
            && $this->subscription_ends_at
            && $this->subscription_ends_at->isFuture();
    }

    public function canReactivateSubscription(): bool
    {
        return $this->hasSubscription()
            && $this->subscription_status === 'cancelled'
            && $this->subscription_ends_at
            && $this->subscription_ends_at->isFuture();
    }

    public function canCreateGallery(): bool
    {
        return \DB::transaction(function () {
            // Lock the user row so concurrent requests wait
            \DB::table('users')
                ->where('id', $this->id)
                ->lockForUpdate()
                ->first();

            return \DB::table('galleries')
                ->where('user_id', $this->id)
                ->whereNull('team_id')
                ->whereNull('deleted_at')
                ->count() < $this->max_galleries;
        });
    }

    public function currentImageCount(): int
    {
        return \DB::table('gallery_images')
            ->join('galleries', 'galleries.id', '=', 'gallery_images.gallery_id')
            ->where('galleries.user_id', $this->id)
            ->whereNull('galleries.deleted_at')
            ->whereNull('gallery_images.deleted_at')
            ->count();
    }

    public function isSuperAdmin(): bool
    {
        return $this->is_super_admin === true;
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function (User $user) {
            if (! $user->acquisition_channel && session()->has('acquisition')) {
                $acq = (array) session('acquisition');
                $user->acquisition_channel   = $acq['channel'] ?? null;
                $user->acquisition_referrer  = $acq['referrer'] ?? null;
                $user->acquisition_landing_page = $acq['landing_page'] ?? null;
                $user->acquisition_utm       = !empty($acq['utm']) ? $acq['utm'] : null;
                $user->acquisition_captured_at = now();
            }

            if (! $user->plan) $user->plan = 'free';
            $limits = self::planLimits($user->plan);
            if (! $user->max_galleries) $user->max_galleries = $limits['max_galleries'];
            if (! $user->max_images)    $user->max_images    = $limits['max_images'];
            if (! $user->plan_started_at) $user->plan_started_at = now();

            if ($user->has_password === null) {
                $user->has_password = ! empty($user->password);
            }
            if (! $user->password_set_at && $user->has_password) {
                $user->password_set_at = now();
            }
        });

        static::updating(function (User $user) {
            if ($user->isDirty('plan')) {
                $limits = self::planLimits($user->plan);
                $user->max_galleries = $limits['max_galleries'];
                $user->max_images    = $limits['max_images'];
            }

            if ($user->isDirty('password') && ! empty($user->password)) {
                $user->has_password = true;
                $user->password_set_at = now();
            }
        });
    }
}
