<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AnalyticsEvent;
use App\Models\Gallery;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RollupAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    // ── schedule registration ────────────────────────────────────────────

    public function test_rollup_analytics_is_registered_on_the_daily_schedule(): void
    {
        $this->app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

        $commands = collect($this->app->make(\Illuminate\Console\Scheduling\Schedule::class)->events())
            ->map(fn ($event) => trim((string) $event->command));

        $this->assertTrue(
            $commands->contains(fn ($cmd) => str_contains($cmd, 'exospace:rollup-analytics')),
            'exospace:rollup-analytics must stay on the daily schedule — without it raw events are never aggregated or pruned.'
        );
    }

    // ── retention pruning ────────────────────────────────────────────────
    // The rollup INSERT ... ON DUPLICATE KEY UPDATE statement is MySQL-only
    // and cannot execute on the sqlite test database; --prune-only exercises
    // the retention path without touching that statement.

    public function test_prune_deletes_only_events_older_than_the_retention_window(): void
    {
        $gallery = $this->makeGallery();

        $ancient = AnalyticsEvent::create([
            'gallery_id' => $gallery->id,
            'event' => 'view',
            'session_token' => hash('sha256', 'old-session'),
            'created_at' => now()->subDays(91),
        ]);

        $edge = AnalyticsEvent::create([
            'gallery_id' => $gallery->id,
            'event' => 'view',
            'session_token' => hash('sha256', 'edge-session'),
            'created_at' => now()->subDays(89),
        ]);

        $this->artisan('exospace:rollup-analytics', ['--prune-only' => true, '--retention' => 90])
            ->assertSuccessful();

        $this->assertDatabaseMissing('analytics_events', ['id' => $ancient->id]);
        $this->assertDatabaseHas('analytics_events', ['id' => $edge->id]);
    }

    public function test_prune_honors_a_custom_retention_window(): void
    {
        $gallery = $this->makeGallery();

        $withinWeek = AnalyticsEvent::create([
            'gallery_id' => $gallery->id,
            'event' => 'view',
            'session_token' => hash('sha256', 'week-session'),
            'created_at' => now()->subDays(3),
        ]);

        $beyondWeek = AnalyticsEvent::create([
            'gallery_id' => $gallery->id,
            'event' => 'view',
            'session_token' => hash('sha256', 'old-week-session'),
            'created_at' => now()->subDays(8),
        ]);

        $this->artisan('exospace:rollup-analytics', ['--prune-only' => true, '--retention' => 7])
            ->assertSuccessful();

        $this->assertDatabaseHas('analytics_events', ['id' => $withinWeek->id]);
        $this->assertDatabaseMissing('analytics_events', ['id' => $beyondWeek->id]);
    }

    private function makeGallery(): Gallery
    {
        $user = User::factory()->create();

        return Gallery::create([
            'user_id' => $user->id,
            'title' => 'Rollup Test Gallery',
            'slug' => 'rollup-test-gallery-'.uniqid(),
            'is_active' => true,
        ]);
    }
}
