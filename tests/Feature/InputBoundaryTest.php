<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Gallery;
use App\Models\User;
use App\Models\UserFeedback;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InputBoundaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_discover_degrades_gracefully_on_malformed_query_params(): void
    {
        $template = \App\Models\VenueTemplate::factory()->create();
        $user = User::factory()->has(Gallery::factory()->count(2)->forVenue($template))->create();

        // Extreme and negative page numbers render empty pages, not errors.
        $this->get('/discover?page=999999999')->assertOk();
        $this->get('/discover?page=-5')->assertOk();

        // Unknown sort values and non-numeric venue filters fall through to
        // safe defaults (alternate/noindex view at worst, never a 500).
        $this->get('/discover?sort=GARBAGE&venue=NOT_A_NUMBER')->assertOk();

        // Array query params used to crash Str::of() with a TypeError (500).
        // They must degrade to the default view.
        $this->get('/discover?sort[]=views')->assertOk();
        $this->get('/discover?venue[]=1')->assertOk();

        // Sanity: the legitimate filtered view still filters.
        $response = $this->get('/discover?venue='.$template->id);
        $response->assertOk();
    }

    public function test_public_gallery_view_tolerates_extreme_pagination(): void
    {
        $user = User::factory()->has(Gallery::factory()->count(1))->create();
        $gallery = $user->galleries()->first();

        $this->get('/gallery/'.$gallery->slug.'?page=99999')->assertOk();
    }

    public function test_admin_gallery_search_caps_term_and_page(): void
    {
        $user = User::factory()->has(Gallery::factory()->count(1))->create();

        $this->actingAs($user)
            ->get('/admin/galleries?q='.str_repeat('A', 5000))
            ->assertOk();
        $this->actingAs($user)
            ->get('/admin/galleries?page=5e10')
            ->assertOk();
    }

    public function test_api_gallery_index_tolerates_malformed_per_page(): void
    {
        // Array and garbage per_page collapse to clamped integers.
        $this->getJson('/api/v1/galleries?per_page[]=1')->assertOk();
        $this->getJson('/api/v1/galleries?per_page=NaN')->assertOk();
    }

    public function test_nps_endpoint_rejects_malformed_and_wrongly_typed_payloads(): void
    {
        $user = User::factory()->create();

        // Malformed JSON parses as an empty payload → validation error, not 500.
        $this->actingAs($user)
            ->call('POST', route('survey.nps'), [], [], [], [
                'HTTP_CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
            ], '{bad json')
            ->assertStatus(422);

        // Float and overflow scores fail the integer rule cleanly.
        $this->actingAs($user)
            ->postJson(route('survey.nps'), ['score' => 9.5])
            ->assertStatus(422);
        $this->actingAs(User::factory()->create())
            ->postJson(route('survey.nps'), ['score' => PHP_INT_MAX])
            ->assertStatus(422);

        $this->assertSame(0, \App\Models\SurveyResponse::count());
    }

    public function test_feedback_endpoint_rejects_array_typed_fields(): void
    {
        $user = User::factory()->create();

        // Array where a scalar is expected → validation errors (web flow 302s
        // back with the error bag), never a 500 or a persisted row.
        $this->actingAs($user)
            ->post('/feedback', ['category' => 'bug', 'message' => ['nested' => ['deep' => 'array']]])
            ->assertRedirect()
            ->assertSessionHasErrors('message');

        $this->actingAs($user)
            ->post('/feedback', ['category' => ['x'], 'message' => 'hi'])
            ->assertRedirect()
            ->assertSessionHasErrors('category');

        // Oversized-but-valid-encoding body: capped by the max:5000 rule.
        $this->actingAs($user)
            ->post('/feedback', ['category' => 'bug', 'message' => str_repeat('x', 2 * 1024 * 1024)])
            ->assertRedirect()
            ->assertSessionHasErrors('message');

        $this->assertSame(0, UserFeedback::count());
    }

    public function test_login_rejects_array_typed_credentials(): void
    {
        $this->post('/login', ['email' => ['a' => 1], 'password' => 'x'])
            ->assertRedirect()
            ->assertSessionHasErrors();

        $this->assertGuest();
    }
}
