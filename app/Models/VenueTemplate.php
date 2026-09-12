<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class VenueTemplate extends Model
{
    use HasFactory;

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

    public const PLANS = ['free', 'pro', 'studio'];

    public const LAYOUTS = ['square', 'corridor', 'l-shape', 'rotunda'];

    public const ENVIRONMENTS = ['studio', 'rural_evening', 'night', 'none'];

    public const STRUCTURED_VISUAL_KEYS = [
        'wall_height', 'wall_depth', 'ceiling_type', 'ceiling_height',
        'background_color', 'fog_color', 'fog_near', 'fog_far',
        'ambient_color', 'ambient_intensity', 'spot_intensity',
        'fill_intensity', 'tone_mapping_exposure', 'frame_override',
        'ceiling_color', 'ceiling_beams', 'ceiling_neon',
        'open_air', 'layout_shape', 'structure_pass',
        'void_dust', 'void_starfield', 'void_colonnade', 'void_shards', 'void_lake',
        'void_arcade',
        'placement',
    ];

    public const STRUCTURED_MATERIAL_KEYS = [
        'wall_color', 'wall_roughness', 'wall_metalness', 'wall_normal_strength',
        'floor_color', 'floor_roughness', 'floor_metalness', 'floor_normal_strength',
    ];

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
        'view_count', 'archived_at',

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
        'archived_at'  => 'datetime',
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

    protected static function boot()
    {
        parent::boot();

        static::creating(function (self $venue) {
            if (empty($venue->slug)) {
                $venue->slug = Str::slug($venue->name);
            }

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

    public function galleries(): HasMany
    {
        return $this->hasMany(Gallery::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true)->whereNull('archived_at');
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

    public function advancedVisualConfig(): array
    {
        return array_diff_key($this->visual_config ?? [], array_flip(self::STRUCTURED_VISUAL_KEYS));
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    public function conversionRate(): ?float
    {
        if (($this->view_count ?? 0) <= 0) {
            return null;
        }

        return round((($this->galleries_count ?? $this->galleries()->count()) / $this->view_count) * 1000, 1);
    }

    public function isAccessibleBy(User $user): bool
    {
        return match ($this->plan_required) {
            'free'   => true,
            'pro'    => $user->isPro(),
            'studio' => $user->plan === 'studio',
            default  => false,
        };
    }

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

    public function supportsLayout(string $layout): bool
    {
        if (empty($this->supported_layouts)) {
            return true;
        }
        return in_array($layout, $this->supported_layouts, true);
    }

    public static function forUser(User $user)
    {
        return static::active()
            ->published()
            ->accessibleByPlan($user->plan)
            ->orderBy('sort_order')
            ->get();
    }

    public function incrementViewCount(): void
    {
        $this->increment('view_count');
    }

    public function toViewerConfig(): array
    {
        return [
            'id'              => $this->id,
            'slug'            => $this->slug,
            'version'         => $this->version,
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
