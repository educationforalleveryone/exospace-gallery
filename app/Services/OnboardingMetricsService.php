<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\OnboardingSnapshot;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class OnboardingMetricsService
{
    public function snapshot(int $days = 30): array
    {
        $days = max(1, min(365, $days));

        return Cache::flexible(
            "onboarding:metrics:{$days}",
            [now()->addMinutes(30), now()->addMinutes(60)],
            fn () => $this->compute($days),
        );
    }

    public function compute(int $days = 30): array
    {
        $days = max(1, min(365, $days));
        $cutoff = now()->subDays($days);

        $registered = User::where('created_at', '>=', $cutoff)->count();

        $createdGallery = User::where('created_at', '>=', $cutoff)
            ->whereHas('galleries')
            ->count();

        $uploadedImage = User::where('users.created_at', '>=', $cutoff)
            ->whereExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('gallery_images')
                    ->join('galleries', 'galleries.id', '=', 'gallery_images.gallery_id')
                    ->whereColumn('galleries.user_id', 'users.id')
                    ->whereNull('galleries.deleted_at')
                    ->whereNull('gallery_images.deleted_at');
            })
            ->count();

        $published = User::where('users.created_at', '>=', $cutoff)
            ->whereExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('galleries')
                    ->whereColumn('galleries.user_id', 'users.id')
                    ->where('galleries.is_active', true)
                    ->whereNull('galleries.deleted_at');
            })
            ->count();

        $gotViews = User::where('users.created_at', '>=', $cutoff)
            ->whereExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('galleries')
                    ->whereColumn('galleries.user_id', 'users.id')
                    ->where('galleries.view_count', '>', 0)
                    ->whereNull('galleries.deleted_at');
            })
            ->count();

        return [
            'days'             => $days,
            'registered'       => $registered,
            'created_gallery'  => $createdGallery,
            'uploaded_image'   => $uploadedImage,
            'published'        => $published,
            'got_views'        => $gotViews,
            'ttfg_hours'       => $this->firstEventDiffHours($cutoff, 'galleries.created_at', true),
            'ttfe_hours'       => $this->firstEventDiffHours($cutoff, 'galleries.published_at', true),
        ];
    }

    private function firstEventDiffHours(\DateTimeInterface $cutoff, string $eventColumn, bool $requireNotNull): ?array
    {
        $rows = DB::table('users')
            ->join('galleries', 'galleries.user_id', '=', 'users.id')
            ->where('users.created_at', '>=', $cutoff)
            ->whereNull('galleries.deleted_at')
            ->when($requireNotNull, fn ($q) => $q->whereNotNull($eventColumn))
            ->selectRaw("users.id, users.created_at as user_created_at, MIN({$eventColumn}) as event_at")
            ->groupBy('users.id', 'users.created_at')
            ->get();

        $hours = [];
        foreach ($rows as $row) {
            if (! $row->event_at || ! $row->user_created_at) {
                continue;
            }
            $diff = \Carbon\Carbon::parse($row->user_created_at)
                ->diffInHours(\Carbon\Carbon::parse($row->event_at), false);
            if ($diff >= 0) {
                $hours[] = $diff;
            }
        }

        if ($hours === []) {
            return null;
        }

        return [
            'min' => round(min($hours), 1),
            'avg' => round(array_sum($hours) / count($hours), 1),
            'max' => round(max($hours), 1),
        ];
    }

    public function persistSnapshot(int $days = 30): OnboardingSnapshot
    {
        $days = max(1, min(365, $days));
        $data = $this->compute($days);
        $capturedAt = now()->startOfHour();

        return OnboardingSnapshot::updateOrCreate(
            ['window_days' => $days, 'captured_at' => $capturedAt],
            [
                'registered'      => $data['registered'],
                'created_gallery' => $data['created_gallery'],
                'uploaded_image'  => $data['uploaded_image'],
                'published'       => $data['published'],
                'got_views'       => $data['got_views'],
                'ttfg_min'        => $data['ttfg_hours']['min'] ?? null,
                'ttfg_avg'        => $data['ttfg_hours']['avg'] ?? null,
                'ttfg_max'        => $data['ttfg_hours']['max'] ?? null,
                'ttfe_min'        => $data['ttfe_hours']['min'] ?? null,
                'ttfe_avg'        => $data['ttfe_hours']['avg'] ?? null,
                'ttfe_max'        => $data['ttfe_hours']['max'] ?? null,
            ],
        );
    }

    public function trend(int $days = 30, int $limit = 26): array
    {
        $days = max(1, min(365, $days));

        return OnboardingSnapshot::query()
            ->trend($days, $limit)
            ->get()
            ->map(fn (OnboardingSnapshot $row) => [
                'captured_at'    => $row->captured_at?->format('M j'),
                'captured_on'    => $row->captured_at?->toDateString(),
                'registered'     => (int) $row->registered,
                'created_gallery'=> (int) $row->created_gallery,
                'uploaded_image' => (int) $row->uploaded_image,
                'published'      => (int) $row->published,
                'got_views'       => (int) $row->got_views,
                'ttfe_avg'        => $row->ttfe_avg !== null ? (float) $row->ttfe_avg : null,
                'ttfg_avg'        => $row->ttfg_avg !== null ? (float) $row->ttfg_avg : null,
            ])
            ->all();
    }
}
