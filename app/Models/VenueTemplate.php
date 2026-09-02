<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * App\Models\VenueTemplate
 *
 * Event / Venue Template — the single source of truth for how a 3D gallery
 * looks and behaves. Replaces the previous stub model (which only stored
 * name/description/capacity) with a fully data-driven configuration that
 * the 3D viewer consumes via JSON.
 *
 * Schema highlights (see migration 2026_06_21_000001_extend_venue_templates_table):
 *   - visual_config     JSON  wall_height, fog, ambient, spot, fill, tone_mapping, frame_override, ceiling_type
 *   - material_config   JSON  wall + floor color/roughness/metalness/normal_strength
 *   - decorations       JSON  array of 3D props (GLB) with position/rotation/scale
 *   - lighting_fixtures JSON  array of custom light fixtures
 *   - supported_layouts JSON  subset of [square, corridor, l-shape, rotunda]
 *   - preview_model_path      GLB file for admin 3D preview
 *   - hdri_path               custom HDRI environment map
 *   - default_audio_path      default ambient audio for galleries using this venue
 *   - category                gallery / museum / warehouse / outdoor / futuristic / minimal / luxury / abstract
 *   - is_featured, is_draft, view_count, author_id, version, published_at
 *
 * Backward compatibility:
 *   - The legacy `default_settings` JSON column is preserved.
 *   - The JS `applyVenueOverrides(slug)` switch in view.blade.php continues
 *     to function as a fallback when visual_config is null.
 *   - Existing galleries continue to work without changes.
 */
class VenueTemplate extends Model
{
    use HasFactory;

    /** Categories used for filtering and badges in the admin UI. */
    public const CATEGORIES = [
        'gallery'     => 'Gallery',
        'museum'      => 'Museum',
        'warehouse'   => 'Warehouse',
        'outdoor'     => 'Outdoor',
        'futuristic'  => 'Futuristic',
        'minimal'     => 'Minimal',
        'luxury'      => 'Luxury',
        'abstract'    => 'Abstract',
    ];

    /** Plan tiers — kept in sync with User::planLimits(). */
    public const PLANS = ['free', 'pro', 'studio'];

    /** Room layouts supported by the 3D viewer. */
    public const LAYOUTS = ['square', 'corridor', 'l-shape', 'rotunda'];

    protected $fillable = [
        // Identity
        'name', 'slug', 'description',
        'category', 'tags',

        // Plan gating & capacity
        'plan_required', 'capacity_min', 'capacity_max',

        // Asset paths
        'thumbnail',             // legacy — kept for back-compat
        'thumbnail_path',        // uploaded thumbnail image
        'preview_model_path',    // GLB for 3D preview
        'hdri_path',             // custom HDRI environment
        'default_audio_path',    // default ambient audio

        // Configuration JSON blobs
        'default_settings',      // legacy — kept for back-compat
        'visual_config',
        'material_config',
        'decorations',
        'lighting_fixtures',
        'supported_layouts',

        // Status & discovery
        'is_active', 'is_featured', 'is_draft', 'sort_order',
        'view_count',

        // Ownership & versioning
        'author_id', 'version', 'published_at',
    ];

    protected $casts = [
        'default_settings'  => 'array',
        'tags'              => 'array',
        'visual_config'     => 'array',
        'material_config'   => 'array',
        'decorations'       => 'array',
        'lighting_fixtures' => 'array',
        'supported_layouts' => 'array',

        'is_active'     => 'boolean',
        'is_featured'   => 'boolean',
        'is_draft'      => 'boolean',

        'capacity_min' => 'integer',
        'capacity_max' => 'integer',
        'view_count'   => 'integer',

        'published_at' => 'datetime',
    ];

    protected $attributes = [
        'category'         => 'gallery',
        'plan_required'    => 'free',
        'capacity_min'     => 10,
        'is_active'        => true,
        'is_featured'      => false,
        'is_draft'         => false,
        'view_count'       => 0,
        'sort_order'       => 0,
        'version'          => '1.0.0',
        'supported_layouts' => '["square","corridor","l-shape","rotunda"]',
    ];

    // ─────────────────────────────────────────────────────────────────────
    //  Boot
    // ─────────────────────────────────────────────────────────────────────

    protected static function boot()
    {
        parent::boot();

        static::creating(function (self $venue) {
            if (empty($venue->slug)) {
                $venue->slug = Str::slug($venue->name);
            }
            // P2-5 FIX: Removed the while-loop slug uniqueness check.
            // Same TOCTOU race as Artist — the DB unique constraint is
            // the source of truth. Controllers catch QueryException and retry.

            // If author_id isn't set, leave it null (system-owned template).
            // Set published_at if this isn't a draft.
            if (!$venue->is_draft && !$venue->published_at) {
                $venue->published_at = now();
            }
        });

        static::updating(function (self $venue) {
            // When a draft is published, stamp published_at.
            if (!$venue->is_draft && !$venue->published_at) {
                $venue->published_at = now();
            }
        });
    }

    // ─────────────────────────────────────────────────────────────────────
    //  Relationships
    // ─────────────────────────────────────────────────────────────────────

    public function galleries(): HasMany
    {
        return $this->hasMany(Gallery::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    // ─────────────────────────────────────────────────────────────────────
    //  Scopes
    // ─────────────────────────────────────────────────────────────────────

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true);
    }

    public function scopePublished(Builder $q): Builder
    {
        return $q->where('is_draft', false);
    }

    public function scopeFeatured(Builder $q): Builder
    {
        return $q->where('is_featured', true);
    }

    public function scopeInCategory(Builder $q, ?string $category): Builder
    {
        return $category ? $q->where('category', $category) : $q;
    }

    public function scopeAccessibleByPlan(Builder $q, string $plan): Builder
    {
        // Free users see free venues; Pro users see free+pro; Studio sees all.
        $allowed = match ($plan) {
            'studio' => ['free', 'pro', 'studio'],
            'pro'    => ['free', 'pro'],
            default  => ['free'],
        };
        return $q->whereIn('plan_required', $allowed);
    }

    // ─────────────────────────────────────────────────────────────────────
    //  Accessors & helpers
    // ─────────────────────────────────────────────────────────────────────

    public function isAccessibleBy(User $user): bool
    {
        return match ($this->plan_required) {
            'free'   => true,
            'pro'    => $user->isPro(),
            'studio' => $user->plan === 'studio',
            default  => false,
        };
    }

    /**
     * Iteration 0 (roadmap P0.4 — honest capacity): the picker previously
     * showed "min–max artworks" (e.g. White Cube "20–60"), but capacity_min
     * is not enforced anywhere and actively misleads — a Free customer
     * (max 10 images) reading "20–60 artworks" on the default venue is
     * being told the venue does not fit their show. The honest, useful
     * datum is the upper bound. capacity_min remains in the DB and the
     * super-admin form as administrative guidance.
     */
    public function capacityLabel(): string
    {
        if (is_null($this->capacity_max)) {
            return 'Any exhibition size';
        }
        return "Up to {$this->capacity_max} artworks";
    }

    public function categoryLabel(): string
    {
        return self::CATEGORIES[$this->category] ?? ucfirst($this->category);
    }

    /**
     * Thumbnail URL — prefers the new uploaded thumbnail_path, falls back
     * to the legacy `thumbnail` column, falls back to null.
     */
    public function getThumbnailUrlAttribute(): ?string
    {
        if ($this->thumbnail_path) {
            return asset('storage/' . $this->thumbnail_path);
        }
        if ($this->thumbnail) {
            return asset($this->thumbnail);
        }
        return null;
    }

    public function getPreviewModelUrlAttribute(): ?string
    {
        return $this->preview_model_path
            ? asset('storage/' . $this->preview_model_path)
            : null;
    }

    public function getHdriUrlAttribute(): ?string
    {
        return $this->hdri_path
            ? asset('storage/' . $this->hdri_path)
            : null;
    }

    public function getDefaultAudioUrlAttribute(): ?string
    {
        return $this->default_audio_path
            ? asset('storage/' . $this->default_audio_path)
            : null;
    }

    /**
     * Does this venue support a given room layout?
     * If `supported_layouts` is null/empty, all layouts are allowed.
     */
    public function supportsLayout(string $layout): bool
    {
        if (empty($this->supported_layouts)) {
            return true;
        }
        return in_array($layout, $this->supported_layouts, true);
    }

    /**
     * Active + published + supports-layout filter — used by the gallery
     * create/edit controllers to populate the venue picker.
     */
    public static function forUser(User $user)
    {
        return static::active()
            ->published()
            ->accessibleByPlan($user->plan)
            ->orderBy('sort_order')
            ->get();
    }

    /**
     * Increment view count without firing model events (cheap).
     * Used when a gallery using this venue is viewed.
     */
    public function incrementViewCount(): void
    {
        $this->increment('view_count');
    }

    /**
     * Get a sanitized config array suitable for export to the 3D viewer
     * via the VenueConfigExporter service. This is the canonical shape
     * the JS expects — see app/Services/VenueConfigExporter.php.
     */
    public function toViewerConfig(): array
    {
        return [
            'id'              => $this->id,
            'slug'            => $this->slug,
            'name'            => $this->name,
            'category'        => $this->category,
            'visual_config'   => $this->visual_config ?? [],
            'material_config' => $this->material_config ?? [],
            'decorations'     => $this->decorations ?? [],
            'lighting_fixtures' => $this->lighting_fixtures ?? [],
            'supported_layouts' => $this->supported_layouts ?? self::LAYOUTS,
            'hdri_url'        => $this->hdri_url,
            'default_audio_url' => $this->default_audio_url,
            'default_settings' => $this->default_settings ?? [],
        ];
    }
}
