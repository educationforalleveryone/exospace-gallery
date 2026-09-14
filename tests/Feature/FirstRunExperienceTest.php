<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\FirstGalleryCreatedEmail;
use App\Models\Gallery;
use App\Models\GalleryImage;
use App\Models\Team;
use App\Models\User;
use App\Models\VenueTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class FirstRunExperienceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function venueTemplate(string $plan = 'free', string $slug = 'white-cube', int $sort = 1): VenueTemplate
    {
        return VenueTemplate::create([
            'name'             => ucfirst(str_replace('-', ' ', $slug)),
            'slug'             => $slug,
            'description'      => 'A venue for testing.',
            'plan_required'    => $plan,
            'is_active'        => true,
            'is_draft'         => false,
            'sort_order'       => $sort,
            'default_settings' => [
                'wall_texture'    => 'white',
                'floor_material'  => 'concrete',
                'frame_style'     => 'minimal',
                'lighting_preset' => 'bright',
                'room_layout'     => 'square',
            ],
        ]);
    }

    private function storePayload(array $overrides = []): array
    {
        return array_merge([
            'title'           => 'My First Exhibition',
            'wall_texture'    => 'white',
            'frame_style'     => 'minimal',
            'lighting_preset' => 'bright',
            'floor_material'  => 'concrete',
            'room_layout'     => 'square',
        ], $overrides);
    }

    // ── First authenticated entry ────────────────────────────────────────

    public function test_a_brand_new_user_lands_on_a_dashboard_that_explains_the_next_step(): void
    {
        $user = User::factory()->create(['created_at' => now()->subHour()]);

        $response = $this->actingAs($user)->get('/admin/dashboard');

        $response->assertOk()
            ->assertSee('Welcome! Get started in 3 steps')
            ->assertSee(route('admin.galleries.create'), false);
    }

    public function test_a_returning_user_with_no_galleries_is_not_greeted_as_new(): void
    {
        $user = User::factory()->create(['created_at' => now()->subDays(7)]);

        $response = $this->actingAs($user)->get('/admin/dashboard');

        $response->assertOk()
            ->assertSee('Pick up where you left off')
            ->assertDontSee('Welcome! Get started in 3 steps');
    }

    // ── The onboarding checklist targets ─────────────────────────────────

    public function test_the_artwork_step_links_to_a_personal_gallery_not_a_team_gallery(): void
    {
        $user = User::factory()->create();
        $personal = Gallery::factory()->create([
            'user_id'   => $user->id,
            'is_active' => false,
            'view_count' => 0,
        ]);

        $team = Team::factory()->create(['owner_id' => $user->id]);
        $team->members()->attach($user->id, ['role' => 'owner']);
        Gallery::factory()->create([
            'user_id'   => $user->id,
            'team_id'   => $team->id,
            'is_active' => false,
        ]);

        $response = $this->actingAs($user)->get('/admin/dashboard');

        $response->assertOk()
            ->assertSee('Get started with Exospace')
            ->assertSee('/admin/galleries/'.$personal->id.'/edit', false);

        $teamGalleryId = Gallery::where('team_id', $team->id)->value('id');
        $response->assertDontSee('/admin/galleries/'.$teamGalleryId.'/edit', false);
    }

    public function test_the_publish_step_links_to_a_personal_draft_not_a_team_draft(): void
    {
        $user = User::factory()->create();
        $personalDraft = Gallery::factory()->create([
            'user_id'   => $user->id,
            'is_active' => false,
        ]);
        GalleryImage::factory()->create(['gallery_id' => $personalDraft->id]);

        $team = Team::factory()->create(['owner_id' => $user->id]);
        $team->members()->attach($user->id, ['role' => 'owner']);
        $teamDraft = Gallery::factory()->create([
            'user_id'   => $user->id,
            'team_id'   => $team->id,
            'is_active' => false,
        ]);

        $response = $this->actingAs($user)->get('/admin/dashboard');

        $response->assertOk()
            ->assertSee('Get started with Exospace')
            ->assertSee('/admin/galleries/'.$personalDraft->id.'/edit', false)
            ->assertDontSee('/admin/galleries/'.$teamDraft->id.'/edit', false);
    }

    public function test_the_checklist_disappears_once_a_personal_gallery_is_published(): void
    {
        $user = User::factory()->create();
        $gallery = Gallery::factory()->create([
            'user_id'   => $user->id,
            'is_active' => true,
            'view_count' => 0,
        ]);
        GalleryImage::factory()->create(['gallery_id' => $gallery->id]);

        $response = $this->actingAs($user)->get('/admin/dashboard');

        $response->assertOk()->assertDontSee('Get started with Exospace');
    }

    public function test_the_personal_checklist_is_not_shown_in_a_team_workspace(): void
    {
        $owner = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $owner->id]);
        $member = User::factory()->create();
        $team->members()->attach($member->id, ['role' => 'editor']);
        $member->switchTeam($team);

        Gallery::factory()->create([
            'user_id'   => $owner->id,
            'team_id'   => $team->id,
            'is_active' => false,
        ]);

        $response = $this->actingAs($member)->get('/admin/dashboard');

        $response->assertOk()->assertDontSee('Get started with Exospace');
    }

    // ── First gallery creation transition ────────────────────────────────

    public function test_the_create_page_guides_first_time_users_and_marks_locked_venues(): void
    {
        $user = User::factory()->create();
        $this->venueTemplate('free', 'white-cube', 1);
        $pro = $this->venueTemplate('pro', 'nebula-drift', 2);

        $response = $this->actingAs($user)->get('/admin/galleries/create');

        $response->assertOk()
            ->assertSee('Defaults below are fine for your first gallery')
            ->assertSee('aria-pressed="true"', false)
            ->assertSee('data-accessible="false"', false);

        $proCard = $response->getContent();
        $this->assertStringContainsString('data-venue-id="'.$pro->id.'"', $proCard);
    }

    public function test_creating_the_first_gallery_emails_the_user_and_lands_on_the_artwork_step(): void
    {
        Mail::fake();
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/admin/galleries', $this->storePayload());

        $gallery = Gallery::where('user_id', $user->id)->whereNull('team_id')->firstOrFail();
        $response->assertRedirect(route('admin.galleries.edit', $gallery));
        $response->assertSessionHas('status');

        Mail::assertQueued(FirstGalleryCreatedEmail::class, fn (FirstGalleryCreatedEmail $mail) => $mail->hasTo($user->email));

        // A second gallery must not repeat the first-gallery email.
        $this->actingAs($user)->post('/admin/galleries', $this->storePayload(['title' => 'Second Show']));
        Mail::assertQueued(FirstGalleryCreatedEmail::class, 1);
    }

    // ── Plan boundaries around onboarding choices ────────────────────────

    public function test_store_rejects_a_venue_above_the_user_plan(): void
    {
        $user = User::factory()->create();
        $proVenue = $this->venueTemplate('pro', 'nebula-drift', 2);

        $response = $this->actingAs($user)
            ->from('/admin/galleries/create')
            ->post('/admin/galleries', $this->storePayload(['venue_template_id' => $proVenue->id]));

        $response->assertRedirect('/admin/galleries/create');
        $response->assertSessionHas('error');
        $this->assertSame(0, Gallery::count(), 'No gallery may be created with a venue above the plan.');
    }

    public function test_store_accepts_a_venue_within_the_user_plan(): void
    {
        $user = User::factory()->create();
        $freeVenue = $this->venueTemplate('free', 'white-cube', 1);

        $response = $this->actingAs($user)->post('/admin/galleries', $this->storePayload([
            'venue_template_id' => $freeVenue->id,
        ]));

        $gallery = Gallery::where('user_id', $user->id)->firstOrFail();
        $response->assertRedirect(route('admin.galleries.edit', $gallery));
        $this->assertSame($freeVenue->id, (int) $gallery->venue_template_id);
    }

    // ── Publishing guidance on the first gallery ─────────────────────────

    public function test_the_first_draft_edit_page_explains_why_publishing_is_locked(): void
    {
        $user = User::factory()->create();
        $gallery = Gallery::factory()->inactive()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)->get("/admin/galleries/{$gallery->id}/edit");

        $response->assertOk()
            ->assertSee('Upload at least one artwork to enable publishing')
            ->assertSee('aria-disabled="true"', false)
            ->assertSee('Upload your first artwork above!');
    }
}
