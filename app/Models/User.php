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
    ];

    // Explicitly guarded — these fields CANNOT be set via fill() / create()
    // / update() with an array. Use forceFill() in trusted code paths only.
    protected $guarded = [
        'id',
        'is_super_admin',
        'plan',
        'max_galleries',
        'max_images',
        'plan_started_at',
        'plan_expires_at',
        'current_team_id',
        'banned_at',
        'ban_reason',
        'email_verified_at',  // set via markEmailAsVerified() / forceFill()
        'remember_token',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password'          => 'hashed',
            'is_super_admin'    => 'boolean',
            'plan_expires_at'   => 'datetime',
            'plan_started_at'   => 'datetime',
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
        if (! $this->current_team_id) {
            return null;
        }
        return Team::find($this->current_team_id);
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
        return match($plan) {
            'studio' => ['max_galleries' => 999, 'max_images' => 500],
            'pro'    => ['max_galleries' => 5,   'max_images' => 100],
            default  => ['max_galleries' => 1,   'max_images' => 10],
        };
    }

    public function isPro(): bool
    {
        return in_array($this->plan, ['pro', 'studio']);
    }

    public function isStudio(): bool
    {
        return $this->plan === 'studio';
    }

    public function canCreateGallery(): bool
    {
        // DB-level count to avoid race conditions in concurrent requests
        return \DB::table('galleries')
            ->where('user_id', $this->id)
            ->whereNull('team_id')
            ->count() < $this->max_galleries;
    }

    public function currentImageCount(): int
    {
        return \DB::table('gallery_images')
            ->join('galleries', 'galleries.id', '=', 'gallery_images.gallery_id')
            ->where('galleries.user_id', $this->id)
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
            // If plan changed, refresh the limits
            if ($user->isDirty('plan')) {
                $limits = self::planLimits($user->plan);
                $user->max_galleries = $limits['max_galleries'];
                $user->max_images    = $limits['max_images'];
                $user->plan_started_at = now();
            }
        });
    }
}
