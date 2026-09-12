<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Gallery;
use App\Models\VenueTemplate;
use Illuminate\Console\Command;

class VenueCatalogReport extends Command
{
    private const REGISTER_MAP = [
        'clean'           => 'white-cube',
        'warm-industrial' => 'industrial-loft',
        'dramatic'        => 'dark-museum',
        'serene'          => 'zen-gallery',
        'luxurious'       => 'luxury-penthouse',
        'electric'        => 'cyber-gallery',
        'infinite'        => 'infinite-void',
        'ethereal'        => 'crystal-cathedral',
        'cosmic'          => 'nebula-drift',
        'reflective'      => 'mirror-lake',
        'natural'         => 'sculpture-garden',
        'intimacy'        => 'the-salon',
    ];

    private const UNCOVERED_REGISTERS = ['grandeur'];

    protected $signature = 'venues:catalog-report
                            {--json : Emit machine-readable JSON instead of console tables}
                            {--venue=* : Restrict the per-venue table to these slugs}';

    protected $description = 'Roll up per-venue adoption, demand, resonance and register coverage — the data input for the venue #12 decision (P2.4)';

    public function handle(): int
    {
        $venues = VenueTemplate::query()
            ->withCount([
                'galleries',
                'galleries as public_galleries_count' => fn ($q) => $q->publiclyViewable()->has('images', '>=', 1),
            ])
            ->orderByDesc('view_count')
            ->orderBy('sort_order')
            ->get();

        if ($only = (array) $this->option('venue')) {
            $venues = $venues->whereIn('slug', $only)->values();
        }

        $rows = $venues->map(fn (VenueTemplate $v) => $this->venueRow($v))->all();

        if ($this->option('json')) {
            $this->line(json_encode([
                'generated_at'      => now()->toIso8601String(),
                'venues'            => $rows,
                'tier_demand'       => $this->tierDemand($venues),
                'register_coverage' => $this->registerCoverage($venues),
                'decision_inputs'   => $this->decisionInputs($venues),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info('Venue catalog rollup — adoption / demand / resonance');
        $this->table(
            ['venue', 'plan', 'galleries', 'public', 'views', 'conv/1k'],
            array_map(fn (array $r) => [
                $r['slug'], $r['plan'], number_format($r['galleries_total']),
                number_format($r['galleries_public']), number_format($r['views']),
                $r['conversion_per_1k'] ?? '—',
            ], $rows),
        );

        $this->newLine();
        $this->info('Demand by tier (venue plan_required)');
        $this->table(
            ['plan', 'views', 'galleries', 'share of views'],
            array_map(fn (array $t) => [
                $t['plan'], number_format($t['views']), number_format($t['galleries']),
                $t['views_share_percent'] === null ? '—' : $t['views_share_percent'] . '%',
            ], $this->tierDemand($venues)),
        );

        $this->newLine();
        $this->info('Emotional register coverage (§3.3)');
        $coverage = $this->registerCoverage($venues);
        $this->table(
            ['register', 'venue', 'status'],
            array_merge(
                array_map(fn (array $c) => [$c['register'], $c['venue'] ?? '—', $c['venue'] ? 'covered' : 'MISSING'], $coverage),
            ),
        );

        $this->newLine();
        $this->line(implode("\n", $this->decisionInputs($venues)));
        $this->newLine();
        $this->line('Decision rule + brief: docs/VENUE_12_BRIEF.md (pre-committed — the rule decides, not the reviewer).');

        return self::SUCCESS;
    }

    private function venueRow(VenueTemplate $venue): array
    {
        return [
            'slug'               => $venue->slug,
            'plan'               => $venue->plan_required ?: 'free',
            'category'           => $venue->category,
            'is_active'          => (bool) $venue->is_active,
            'is_draft'           => (bool) $venue->is_draft,
            'archived'           => $venue->isArchived(),
            'galleries_total'    => (int) ($venue->galleries_count ?? $venue->galleries()->count()),
            'galleries_public'   => (int) ($venue->public_galleries_count ?? 0),
            'views'              => (int) ($venue->view_count ?? 0),
            'conversion_per_1k'  => $venue->conversionRate(),
        ];
    }

    private function tierDemand($venues): array
    {
        $totalViews = (int) $venues->sum(fn (VenueTemplate $v) => (int) ($v->view_count ?? 0));

        return collect(['free', 'pro', 'studio'])->map(function (string $plan) use ($venues, $totalViews) {
            $inTier = $venues->where('plan_required', $plan === '' ? 'free' : $plan)
                ->when($plan === 'free', fn ($c) => $c->merge($venues->where('plan_required', '')));
            $views = (int) $inTier->sum(fn (VenueTemplate $v) => (int) ($v->view_count ?? 0));

            return [
                'plan'                 => $plan,
                'views'                => $views,
                'galleries'            => (int) $inTier->sum(fn (VenueTemplate $v) => (int) ($v->galleries_count ?? 0)),
                'venue_count'          => $inTier->count(),
                'views_share_percent'  => $totalViews > 0 ? round(($views / $totalViews) * 100, 1) : null,
            ];
        })->all();
    }

    private function registerCoverage($venues): array
    {
        $bySlug = $venues->keyBy('slug');

        $rows = [];
        foreach (self::REGISTER_MAP as $register => $slug) {
            $rows[] = [
                'register' => $register,
                'venue'    => $bySlug->has($slug) ? $slug : null,
                'status'   => $bySlug->has($slug) ? 'covered' : 'uncovered',
            ];
        }

        foreach (self::UNCOVERED_REGISTERS as $register) {
            $rows[] = [
                'register' => $register,
                'venue'    => null,
                'status'   => 'uncovered',
            ];
        }

        return $rows;
    }

    private function decisionInputs($venues): array
    {
        $studioShare = collect($this->tierDemand($venues))
            ->firstWhere('plan', 'studio')['views_share_percent'] ?? null;
        $totalGalleries = (int) $venues->sum(fn (VenueTemplate $v) => (int) ($v->galleries_count ?? 0));
        $salon = 'intimacy (the small salon)';
        $hall = 'grandeur (the great hall)';

        return [
            "total galleries created: {$totalGalleries}",
            'studio-tier share of venue-attributed views: ' . ($studioShare === null ? 'no data yet (0 views)' : $studioShare . '%'),
            "pre-committed rule: studio share >= 50% builds {$hall}; below 50% (or no data) builds {$salon}",
            'rationale: premium demand concentrated at Studio tier justifies the costlier vertical build; otherwise the cheap, close-hung salon converts the free tier the catalog under-serves',
            'either way: venue #12 is built through the pipeline (clone → descriptors → preview → publish) per §16.7, and NO existing venue retires (roadmap DO NOT DO #2)',
        ];
    }
}
