<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class User extends Authenticatable implements MustVerifyEmail
{
    use HasFactory, Notifiable;

    // ── Mass-assignment surface ───────────────────────────────────────────
    //
    // Only the three identity fields are mass-assignable. Everything that
    // affects billing, authorization, or plan state is guarded and must be
    // set explicitly via forceFill() (which bypasses $guarded) in the
    // trusted admin / webhook / middleware code paths.
    //
    // This prevents a class of privilege-escalation / billing-bypass bugs
    // where a future controller refactor accidentally passes
    // $request->validated() or $request->all() to User::create() / update()
    // / fill() and lets a client set is_super_admin=1, plan='studio',
    // max_images=99999, etc.
    //
    // Trusted callers that need to set the guarded fields:
    //   - WebhookController (upgrade / downgrade)
    //   - SuperAdmin\SystemController (plan change, ban, super-admin toggle)
    //   - CheckPlanExpiry middleware (expiry-driven downgrade)
    //   - PlanDowngradeService (Studio-only resource cleanup)
    //   - RegisteredUserController (initial user creation — only sets name/email/password)
    //   - PasswordController (password change — only sets password)
    //   - ProfileController (name/email change — only sets name/email)
    //   - TeamController / teams.switch-personal route (current_team_id)
    //
    // All of the above already use forceFill() or query-builder update()
    // (which bypasses $fillable/$guarded). If you add a new caller that
    // needs to set a guarded field, use forceFill(['field' => $value])->save()
    // and add a comment explaining why the mutation is trusted.
    protected $fillable = [
        'name',
        'email',
        'password',
        'marketing_consent',
    ];

    // TD-12 FIX: Removed the $guarded array. When $fillable is set (above),
    // Laravel ignores $guarded entirely — it was dead code that misled
    // maintainers into thinking it provided defense-in-depth. The $fillable
    // allow-list is the single source of truth for mass-assignment safety.
    // Trusted callers that need to set guarded fields use forceFill() —
    // see the class docblock above for the list of trusted callers.

    protected $hidden = [
        'password',
        'remember_token',
        'google2fa_secret', // (Task H56) — never expose in JSON
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
            'inactive_nudged_at'       => 'datetime', // (P0-7) — last inactive-nudge
            'plan_expiry_reminded_at'  => 'datetime', // (P0-7) — last plan-expiry reminder
            'marketing_consent' => 'boolean',      // (P0-3) — CAN-SPAM/GDPR consent
            'mfa_backup_codes'  => 'array',         // (P3-7) — hashed one-time codes
            // M-1: Subscription tracking columns (recurring billing)
            'subscription_cancelled_at' => 'datetime',
            'subscription_ends_at'      => 'datetime',
        ];
    }

    // ── Existing relationships ────────────────────────────────────────────

    /** Galleries the user personally owns */
    public function galleries(): HasMany
    {
        return $this->hasMany(Gallery::class);
    }

    /** Artist profiles created by this user (curator) */
    public function createdArtists(): HasMany
    {
        return $this->hasMany(Artist::class, 'created_by');
    }

    /** Billing transactions (2Checkout payments) */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /** Pending upgrade requests (awaiting 2Checkout IPN) */
    public function pendingUpgrades(): HasMany
    {
        return $this->hasMany(PendingUpgrade::class);
    }

    // ── Team relationships ────────────────────────────────────────────────

    /** Teams this user owns */
    public function ownedTeams(): HasMany
    {
        return $this->hasMany(Team::class, 'owner_id');
    }

    /** Teams this user belongs to (including ones they own via pivot) */
    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(Team::class, 'team_user')
                    ->withPivot('role')
                    ->withTimestamps();
    }

    /** The currently active team */
    public function currentTeam()
    {
        // PERF-14 FIX: This is now a proper BelongsTo relationship, so it
        // can be eager-loaded via ->with('currentTeam') instead of requiring
        // a separate query on access. Previously this was a non-relationship
        // method that called Team::find($this->current_team_id) inline —
        // un-eager-loadable, which caused an N+1 query on every page that
        // displays a list of users (admin user list, team members list, etc.)
        // because each $user->currentTeam() call hit the DB.
        //
        // The relationship returns null when current_team_id is null, which
        // matches the old behavior. Callers that previously called
        // currentTeam() as a method (e.g. $user->currentTeam()) should switch
        // to property access ($user->currentTeam) for the dynamic property
        // resolution — but the method-call form still works (Laravel returns
        // the loaded model from the relationship cache).
        //
        // To eager-load: User::with('currentTeam')->get();
        if (! $this->current_team_id) {
            return null;
        }

        // If the relationship has been eager-loaded, return the loaded model.
        // This preserves the old short-circuit behavior for users with no
        // current team, and avoids a query when the relationship is already
        // loaded.
        if ($this->relationLoaded('currentTeam')) {
            return $this->getRelation('currentTeam');
        }

        return Team::find($this->current_team_id);
    }

    /**
     * PERF-14: The actual BelongsTo relationship for eager loading.
     *
     * Use this in ->with() calls: User::with('currentTeamRelationship')->get()
     * Then access via $user->currentTeamRelationship (not $user->currentTeam,
     * which is the legacy method above).
     *
     * NOTE: We keep both the method form (currentTeam()) and the relationship
     * form (currentTeamRelationship()) for backward compatibility. Existing
     * callers continue to work via the method form. New callers that need
     * eager loading should use ->with('currentTeamRelationship').
     */
    public function currentTeamRelationship(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'current_team_id');
    }

    /** Switch the active team context */
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

    // ── Plan helpers ──────────────────────────────────────────────────────
    //
    // Plan tiers and limits — single source of truth.
    //
    //   Free   — 1 gallery,  10 images, white-cube + infinite-void venues
    //   Pro    — 5 galleries, 100 images, all venues except studio-only
    //   Studio — unlimited galleries, 500 images, all venues + custom materials
    //            + custom domains + white-label (no Exospace watermark)
    //
    // Why these numbers:
    //   - Free = enough to try the product, not enough for a real artist
    //   - Pro  = enough for a working artist (5 exhibitions, 100 works each)
    //   - Studio = enough for a gallery / agency (unlimited exhibitions,
    //     500 images each = ample for any real-world use)
    //
    // If you change these, also update:
    //   - resources/views/pages/pricing.blade.php (UI display)
    //   - database/seeders/VenueTemplateSeeder.php (plan_required per venue)
    //   - app/Services/VenueConfigExporter.php (plan gating for decorations)

    public static function planLimits(string $plan): array
    {
        // TD-27 FIX: Plan limits are now defined in config/plans.php (the
        // single source of truth). Previously they were hardcoded in this
        // method, with the same values duplicated across the pricing page,
        // billing portal, venue seeder, and venue config exporter — making
        // it easy to update one place and forget the others.
        //
        // The config returns ['max_galleries' => int, 'max_images' => int]
        // for each plan. Unknown plans fall back to 'free' limits.
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

    // ── M-1: Subscription helpers ────────────────────────────────────────

    /**
     * Does this user have an active recurring subscription?
     * (vs. a one-time purchase where plan_expires_at = null)
     */
    public function hasSubscription(): bool
    {
        return ! empty($this->subscription_id);
    }

    /**
     * Is the subscription currently active (not cancelled, not past_due)?
     */
    public function hasActiveSubscription(): bool
    {
        return $this->hasSubscription()
            && $this->subscription_status === 'active';
    }

    /**
     * Has the subscription been cancelled but still within the paid-for period?
     * (The user keeps access until subscription_ends_at, then is downgraded.)
     */
    public function hasCancelledSubscription(): bool
    {
        return $this->hasSubscription()
            && $this->subscription_status === 'cancelled'
            && $this->subscription_ends_at
            && $this->subscription_ends_at->isFuture();
    }

    /**
     * Can the user reactivate their cancelled subscription?
     * Only if the subscription hasn't ended yet (still in the paid-for period).
     */
    public function canReactivateSubscription(): bool
    {
        return $this->hasSubscription()
            && $this->subscription_status === 'cancelled'
            && $this->subscription_ends_at
            && $this->subscription_ends_at->isFuture();
    }

    public function canCreateGallery(): bool
    {
        // P2-6 FIX: Wrap in a transaction with lockForUpdate on the user row
        // to prevent the TOCTOU race where two concurrent requests both pass
        // the count check and both insert, exceeding the limit.
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

    // ── Boot ──────────────────────────────────────────────────────────────

    protected static function boot()
    {
        parent::boot();

        // When a user is created, default their plan limits from the plan.
        // When a user's plan changes, refresh their limits.
        static::creating(function (User $user) {
            if (! $user->plan) $user->plan = 'free';
            $limits = self::planLimits($user->plan);
            if (! $user->max_galleries) $user->max_galleries = $limits['max_galleries'];
            if (! $user->max_images)    $user->max_images    = $limits['max_images'];
            if (! $user->plan_started_at) $user->plan_started_at = now();
        });

        static::updating(function (User $user) {
            // If plan changed, refresh the limits.
            // TD-13 FIX: Use a separate plan_changed_at column for tracking
            // the most recent plan change. plan_started_at is preserved as
            // the ORIGINAL first-paid date for LTV/churn analytics.
            // (If plan_changed_at doesn't exist in the DB, the forceFill
            // in WebhookController/SystemController sets it — this hook
            // only handles limits.)
            if ($user->isDirty('plan')) {
                $limits = self::planLimits($user->plan);
                $user->max_galleries = $limits['max_galleries'];
                $user->max_images    = $limits['max_images'];
                // TD-13: Do NOT reset plan_started_at — it's the original
                // first-paid timestamp used for LTV analytics. The webhook
                // and admin controllers set plan_started_at = now() explicitly
                // on initial upgrade; subsequent plan changes should NOT
                // overwrite it.
            }
        });
    }
}
