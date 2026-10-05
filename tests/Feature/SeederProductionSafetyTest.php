<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\VenueTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class SeederProductionSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_seeder_refuses_to_run_in_production(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('demo user');

        // Invoked directly: db:seed itself would prompt for confirmation first,
        // and the guard must hold even when that prompt is bypassed (--force).
        (new DatabaseSeeder)->run();
    }

    public function test_database_seeder_creates_no_users_when_refused_in_production(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        try {
            (new DatabaseSeeder)->run();
        } catch (RuntimeException) {
            // expected — assertions below prove the refusal was clean
        }

        $this->assertSame(
            0,
            User::count(),
            'No user — least of all a demo one — may exist after a refused production seed.'
        );
    }

    public function test_venue_catalog_seeder_still_runs_in_production(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        (new VenueTemplateSeeder)->run();

        $this->assertSame(12, DB::table('venue_templates')->count());
        $this->assertSame(0, User::count(), 'The venue catalog seeder creates no credentials.');
    }

    public function test_database_seeder_works_outside_production(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(1, User::where('email', 'test@example.com')->count());
        $this->assertSame(12, DB::table('venue_templates')->count());
    }

    public function test_venue_seeder_is_non_destructive_over_existing_rows(): void
    {
        $this->seed(VenueTemplateSeeder::class);

        DB::table('venue_templates')->where('slug', 'white-cube')->update([
            'view_count' => 5000,
        ]);

        $this->seed(VenueTemplateSeeder::class);

        $this->assertSame(5000, (int) DB::table('venue_templates')->where('slug', 'white-cube')->value('view_count'),
            'Re-seeding the catalog must not reset operator-owned counters (updateOrCreate, never delete).');
        $this->assertSame(12, DB::table('venue_templates')->count());
    }
}
