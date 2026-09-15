<?php

declare(strict_types=1);

namespace App\Observers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SitemapCacheObserver
{
    /**
      * @var array<int, true>
      */
    private array $justCreated = [];

    /**
      * @var array<class-string<Model>, array<int, string>>
      */
    private const WATCHED = [
        \App\Models\Gallery::class => [
            'slug', 'title', 'description', 'is_active', 'pin_hash',
            'opens_at', 'closes_at', 'venue_template_id', 'custom_domain',
            'custom_domain_verified_at', 'deleted_at',
        ],
        \App\Models\Artist::class => [
            'slug', 'name', 'bio', 'location', 'deleted_at',
        ],
        \App\Models\GalleryImage::class => [
            'gallery_id', 'artist_id', 'title', 'description',
            'medium', 'year', 'deleted_at', 'position_order',
        ],
        \App\Models\GalleryScheduleEvent::class => [
            'gallery_id', 'title', 'description', 'type',
            'starts_at', 'ends_at', 'is_active',
        ],
        \App\Models\VenueTemplate::class => [
            'slug', 'name', 'description', 'is_active', 'is_draft',
            'published_at', 'archived_at',
        ],
    ];

    public function created(Model $model): void
    {
        // A new row is (potentially) a new URL — always bump.
        $this->justCreated[spl_object_id($model)] = true;
        $this->bump();
    }

    public function saved(Model $model): void
    {
        $oid = spl_object_id($model);

        // The insert's own saved() dispatch: created() already bumped.
        if (isset($this->justCreated[$oid])) {
            unset($this->justCreated[$oid]);
            return;
        }

        if ($this->isRelevant($model)) {
            $this->bump();
        }
    }

    public function deleted(Model $model): void
    {
        $this->bump();
    }

    public function restored(Model $model): void
    {
        if ($this->usesSoftDeletes($model)) {
            return;
        }

        $this->bump();
    }

    public function forceDeleted(Model $model): void
    {
        // forceDelete() issues a bare DELETE — no save events fire.
        $this->bump();
    }

    private function isRelevant(Model $model): bool
    {
        $watched = self::WATCHED[$model::class] ?? null;

        if ($watched === null) {
            return true;
        }

        return $model->wasChanged($watched);
    }

    private function usesSoftDeletes(Model $model): bool
    {
        return in_array(SoftDeletes::class, class_uses_recursive($model), true);
    }

    private function bump(): void
    {
        // The bump must land after the mutation commits: a bump inside an
        // open transaction would let a concurrent rebuild cache pre-commit
        // data under the new version. Outside a transaction this runs
        // immediately; a rolled-back transaction discards it.
        \Illuminate\Support\Facades\DB::afterCommit(function () {
            \App\Support\SitemapVersion::bump();
        });
    }
}
