<?php

namespace App\Models;

use App\Models\Concerns\HasSeoProfile;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Gallery extends Model
{
    use HasFactory, SoftDeletes;
    use HasSeoProfile; // SEO OS — admin overrides via seo_profiles
    protected $fillable = [
        'user_id', 'team_id', 'title', 'slug', 'description',
        'wall_texture', 'frame_style', 'lighting_preset',
        'floor_material', 'audio_path', 'custom_logo_path',
        'room_layout', 'venue_template_id', 'pin_hash',
        'is_active', 'view_count',
        'opens_at', 'closes_at',
        'published_at',
        'custom_domain',  // Studio-plan white-label CNAME support
        'is_featured',    // NEW (Round 4) — super-admin curated for /discover
        'curtain_logo_path',  // NEW (Round 4) — Studio-only custom entrance curtain logo
        'curtain_bg_color',   // NEW (Round 4) — Studio-only custom entrance curtain bg color
        'visual_overrides',   // NEW (Live Preview) — per-gallery tweaks on top of venue config
    ];

    protected $casts = [
        'is_active'  => 'boolean',
        'view_count' => 'integer',
        'opens_at'   => 'datetime',
        'closes_at'  => 'datetime',
        'published_at' => 'datetime',
        'visual_overrides' => 'array',
        'custom_domain_verified_at' => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();
        static::creating(function ($gallery) {
            if (empty($gallery->slug)) {
                $gallery->slug = Str::slug($gallery->title) . '-' . uniqid();
            }
        });

        // Normalise custom_domain on save: lowercase, strip scheme/path.
        static::saving(function ($gallery) {
            if (!empty($gallery->custom_domain)) {
                $domain = strtolower(trim($gallery->custom_domain));
                // Strip http:// or https:// prefix
                $domain = preg_replace('#^https?://#', '', $domain);
                // Strip any path component
                $domain = explode('/', $domain)[0];
                // Strip :port
                $domain = explode(':', $domain)[0];
                $gallery->custom_domain = $domain;
            }
        });

        // A soft-deleted gallery must not keep holding its unique custom
        // domain — the row stays in the table, so the domain would be
        // unusable by any other exhibition. The Coolify route and DNS
        // verification no longer exist at that point, so the domain has to
        // be re-claimed (and re-verified) through the normal flow.
        static::deleting(function (self $gallery) {
            if ($gallery->isForceDeleting()) {
                return;
            }

            $domain = $gallery->getOriginal('custom_domain') ?? $gallery->custom_domain;

            if (!empty($domain)) {
                \Illuminate\Support\Facades\Cache::forget("custom_domain:{$domain}");
            }

            $gallery->forceFill([
                'custom_domain'                     => null,
                'custom_domain_verification_token'  => null,
                'custom_domain_verified_at'         => null,
            ])->saveQuietly();
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function venueTemplate(): BelongsTo
    {
        return $this->belongsTo(VenueTemplate::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(GalleryImage::class)->orderBy('position_order');
    }

    public function coverImage(): HasOne
    {
        return $this->hasOne(GalleryImage::class)->orderBy('position_order');
    }

    public function events(): HasMany
    {
        return $this->hasMany(AnalyticsEvent::class);
    }

    public function scheduleEvents(): HasMany
    {
        return $this->hasMany(GalleryScheduleEvent::class)->orderBy('starts_at');
    }

    public function newsletterSignups(): HasMany
    {
        return $this->hasMany(NewsletterSignup::class);
    }

    public function artists(): BelongsToMany
    {
        return $this->belongsToMany(Artist::class, 'gallery_images')->distinct()->orderBy('name');
    }

    public function scopePubliclyViewable(Builder $q): Builder
    {
        return $q->where('is_active', true)
                 ->whereNull('pin_hash')
                 ->where(function ($q) {
                     // Not scheduled, OR currently open
                     $q->whereNull('opens_at')->orWhere('opens_at', '<=', now());
                 })
                 ->where(function ($q) {
                     $q->whereNull('closes_at')->orWhere('closes_at', '>=', now());
                 });
    }

    public function scopePubliclyAccessible(Builder $q): Builder
    {
        return $q->where('is_active', true)
                 ->whereDoesntHave('user', fn (Builder $q) => $q->whereNotNull('banned_at'));
    }

    public function scopeWithCustomDomain(Builder $q, string $host): Builder
    {
        return $q->where('custom_domain', $host);
    }

    public function getPublicUrlAttribute(): string
    {
        // If a custom domain is set, use it; otherwise use the standard slug URL.
        if ($this->custom_domain) {
            return 'https://' . $this->custom_domain;
        }
        return url("/gallery/{$this->slug}");
    }

    public function getCoverImageUrlAttribute(): ?string
    {
        $img = $this->coverImage;
        return $img ? asset($img->path) : null;
    }

    public function hasPinProtection(): bool
    {
        return !empty($this->pin_hash);
    }

    public function hasCustomDomain(): bool
    {
        return !empty($this->custom_domain);
    }

    // --- Time-gate helpers ---

    public function isScheduled(): bool
    {
        return !is_null($this->opens_at);
    }

    public function isOpen(): bool
    {
        $now = now();

        if ($this->opens_at && $now->lt($this->opens_at)) {
            return false; // Not open yet
        }

        if ($this->closes_at && $now->gt($this->closes_at)) {
            return false; // Exhibition ended
        }

        return true;
    }

    public function hasNotOpenedYet(): bool
    {
        return $this->opens_at && now()->lt($this->opens_at);
    }

    public function hasClosed(): bool
    {
        return $this->closes_at && now()->gt($this->closes_at);
    }

    public function verifyPin(string $pin): bool
    {
        return \Hash::check($pin, $this->pin_hash);
    }

    // ─── Custom-domain verification ─────────────────────────────────────

    public function isCustomDomainVerified(): bool
    {
        return ! empty($this->custom_domain)
            && ! empty($this->custom_domain_verified_at);
    }

    public function generateDomainVerificationToken(): string
    {
        $token = Str::random(32);

        $this->forceFill([
            'custom_domain_verification_token' => $token,
            'custom_domain_verified_at'        => null,
        ])->save();

        return $token;
    }

    public function domainVerificationTxtHost(): ?string
    {
        if (empty($this->custom_domain)) {
            return null;
        }
        return '_exospace.' . $this->custom_domain;
    }

    public function domainVerificationTxtValue(): ?string
    {
        if (empty($this->custom_domain_verification_token)) {
            return null;
        }
        return 'exospace-verify=' . $this->custom_domain_verification_token;
    }

    public function visualOverridesArray(): array
    {
        $v = $this->visual_overrides;
        if (!is_array($v)) {
            return ['visual_config' => [], 'material_config' => [], 'post_fx' => []];
        }
        return [
            'visual_config'   => is_array($v['visual_config']   ?? null) ? $v['visual_config']   : [],
            'material_config' => is_array($v['material_config'] ?? null) ? $v['material_config'] : [],
            'post_fx'         => is_array($v['post_fx']         ?? null) ? $v['post_fx']         : [],
        ];
    }

    public function hasVisualOverrides(): bool
    {
        $v = $this->visualOverridesArray();
        return !empty($v['visual_config']) || !empty($v['material_config']) || !empty($v['post_fx']);
    }
}
