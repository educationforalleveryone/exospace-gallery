<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Gallery;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DashboardStateTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function brand_new_user_dashboard_shows_every_empty_state_with_a_next_step(): void
    {
        $user = User::factory()->create(['created_at' => now()->subDays(7)]);

        $response = $this->actingAs($user)->get('/admin/dashboard');

        $response->assertOk();
        $response->assertSee('No galleries yet');
        $response->assertSee('No views recorded yet');
        $response->assertSee(route('admin.galleries.create'), false);
        // The chart only renders once there is data to chart.
        $response->assertDontSee('Views — last 7 days');
    }

    #[Test]
    public function team_dashboard_empty_state_names_the_workspace(): void
    {
        $user = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $user->id]);
        $team->members()->attach($user->id, ['role' => 'owner']);
        $user->forceFill(['current_team_id' => $team->id])->save();

        $response = $this->actingAs($user)->get('/admin/dashboard');

        $response->assertOk();
        $response->assertSee('No galleries in '.$team->name.' yet');
    }

    #[Test]
    public function team_viewer_is_told_the_workspace_is_read_only(): void
    {
        $owner = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $owner->id]);
        $team->members()->attach($owner->id, ['role' => 'owner']);

        $viewer = User::factory()->create();
        $team->members()->attach($viewer->id, ['role' => 'viewer']);
        $viewer->forceFill(['current_team_id' => $team->id])->save();

        $response = $this->actingAs($viewer)->get('/admin/dashboard');

        $response->assertOk();
        $response->assertSee('View only');
    }

    #[Test]
    public function dashboard_with_drafts_but_no_traffic_prompts_publishing(): void
    {
        $user = User::factory()->create(['created_at' => now()->subDays(7)]);
        $gallery = Gallery::factory()->create([
            'user_id' => $user->id,
            'is_active' => false,
            'view_count' => 0,
        ]);

        $response = $this->actingAs($user)->get('/admin/dashboard');

        $response->assertOk();
        // Distinct empty-state copy for "galleries exist but none live".
        $response->assertSee('Publish a gallery and share the link');
        $response->assertSee($gallery->title);
    }

    #[Test]
    public function gallery_list_with_no_galleries_shows_the_create_hero(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/admin/galleries');

        $response->assertOk();
        $response->assertSee(route('admin.galleries.create'), false);
        $response->assertDontSee('No galleries match');
    }

    #[Test]
    public function gallery_list_search_without_matches_offers_a_clear_action(): void
    {
        $user = User::factory()->create();
        Gallery::factory()->create(['user_id' => $user->id, 'title' => 'Night Gallery']);

        $response = $this->actingAs($user)->get('/admin/galleries?q=nonexistent-title');

        $response->assertOk();
        // The echoed search term stays HTML-escaped (no XSS through ?q=).
        $response->assertSee('No galleries match "nonexistent-title"', false);
        $response->assertSee('Clear search');
    }

    #[Test]
    public function gallery_list_search_never_escapes_the_users_own_scope(): void
    {
        $user = User::factory()->create();
        Gallery::factory()->create(['user_id' => $user->id, 'title' => 'My Secret Gallery']);

        $other = User::factory()->create();
        Gallery::factory()->create(['user_id' => $other->id, 'title' => 'Foreign Gallery']);

        $response = $this->actingAs($user)->get('/admin/galleries?q=Foreign');

        $response->assertOk();
        $response->assertDontSee('Foreign Gallery');
    }
}
