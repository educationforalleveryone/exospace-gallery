<?php

namespace Tests\Feature;

use App\Models\OnboardingSnapshot;
use App\Models\User;
use App\Services\ReleaseCalendar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReleaseAnnotationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function actingAsMfaSuperAdmin()
    {
        $admin = User::factory()->withMfa()->create([
            'is_super_admin' => true,
            'email_verified_at' => now(),
        ]);

        return $this->actingAs($admin)->withSession([
            'mfa_verified' => true,
            'mfa_verified_at' => now()->timestamp,
        ]);
    }

    private function seedTrendPoint(string $weeksAgo): void
    {
        OnboardingSnapshot::create([
            'window_days' => 30, 'registered' => 8, 'published' => 2,
            'ttfe_avg' => 60.0, 'ttfg_avg' => 20.0,
            'captured_at' => now()->subWeeks((int) $weeksAgo)->startOfHour(),
        ]);
    }

    private function seedTrendPointAt(string $date): void
    {
        OnboardingSnapshot::create([
            'window_days' => 30, 'registered' => 8, 'published' => 2,
            'ttfe_avg' => 60.0, 'ttfg_avg' => 20.0,
            'captured_at' => \Carbon\Carbon::parse($date)->startOfHour(),
        ]);
    }

    public function test_release_calendar_exposes_dated_releases(): void
    {
        $dates = ReleaseCalendar::releaseDates();

        $this->assertNotEmpty($dates);
        foreach ($dates as $release) {
            $this->assertArrayHasKey('version', $release);
            $this->assertArrayHasKey('date', $release);
            $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $release['date']);
        }
    }

    public function test_between_filters_to_window_and_merges_same_day_releases(): void
    {
        $julyWindow = ReleaseCalendar::between(
            now()->startOfYear()->setDate(2026, 7, 5),
            now()->startOfYear()->setDate(2026, 7, 20),
        );

        $this->assertEmpty($julyWindow, 'releases after 2026-07-04 are outside the window');

        $wide = ReleaseCalendar::between(
            now()->startOfYear()->setDate(2026, 6, 1),
            now()->startOfYear()->setDate(2026, 7, 31),
        );

        $this->assertCount(2, $wide);
        $this->assertSame('v1.0', $wide[0]['version']);
        $this->assertSame('2026-06-30', $wide[0]['date']);
        $this->assertSame(
            'v1.7 · v1.6 · v1.5',
            $wide[1]['version'],
            'same-day releases merge into one annotation marker',
        );
        $this->assertSame('2026-07-04', $wide[1]['date']);
    }

    public function test_changelog_page_renders_from_the_shared_calendar(): void
    {
        $response = $this->get('/changelog');

        $response->assertOk()->assertSee('v1.0', false);
    }

    public function test_release_entries_expose_only_public_keys(): void
    {
        // Contract guard: internal metadata (rollout plans, rollback steps,
        // embargo reminders) must be added to ReleaseCalendar::internalNotes(),
        // never as an extra key on a release entry — this fails the build if
        // one sneaks in.
        $renderedKeys = [];

        foreach (ReleaseCalendar::releases() as $release) {
            $renderedKeys = array_unique(array_merge($renderedKeys, array_keys($release)));
        }

        $this->assertEmpty(
            array_diff($renderedKeys, ReleaseCalendar::PUBLIC_KEYS),
            'Release entries carry non-public keys — move them to internalNotes().',
        );
    }

    public function test_internal_notes_never_render_on_the_public_changelog(): void
    {
        // Structure guard: notes must belong to real versions.
        $versions = array_column(ReleaseCalendar::releases(), 'version');

        foreach (array_keys(ReleaseCalendar::internalNotes()) as $version) {
            $this->assertContains($version, $versions, "internal note for [{$version}] has no matching release.");
        }

        // Leak guard: nothing from internalNotes() may reach the public page.
        $response = $this->get('/changelog');
        $response->assertOk();

        foreach (ReleaseCalendar::internalNotes() as $notes) {
            foreach ((array) $notes as $note) {
                $response->assertDontSee($note, false);
            }
        }
    }

    public function test_changelog_renders_every_release_publicly(): void
    {
        $response = $this->get('/changelog');
        $response->assertOk();

        foreach (ReleaseCalendar::releases() as $release) {
            // Default (escaped) matching — titles like "Dunning & Invoicing"
            // render as &amp; in the HTML haystack.
            $response->assertSee($release['version']);
            $response->assertSee($release['title']);
            $response->assertSee(\Carbon\Carbon::parse($release['date'])->format('M j, Y'));
        }
    }

    public function test_master_control_embeds_release_annotations_when_chart_drawn(): void
    {
        $this->seedTrendPointAt('2026-07-07');
        $this->seedTrendPointAt('2026-07-14');

        $response = $this->actingAsMfaSuperAdmin()->get('/master-control');

        $response->assertOk()
            ->assertSee('ttfe-trend-chart', false)
            ->assertSee('"version":"v1.0"', false)
            ->assertSee('"v1.7 \u00b7 v1.6 \u00b7 v1.5"', false)
            ->assertSee('2 release markers', false);
    }

    public function test_master_control_has_no_annotation_payload_before_a_trend_exists(): void
    {
        $response = $this->actingAsMfaSuperAdmin()->get('/master-control');

        $response->assertOk()
            ->assertDontSee('id="ttfe-trend-chart"', false)
            ->assertSee('var releases = [];', false)
            ->assertDontSee('"version":', false);
    }
}
