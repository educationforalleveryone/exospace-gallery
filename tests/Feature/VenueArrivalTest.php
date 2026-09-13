<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Gallery;
use App\Models\GalleryImage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VenueArrivalTest extends TestCase
{
    use RefreshDatabase;

    private const ARRIVAL_JS    = 'resources/js/gallery/Arrival.js';
    private const ARRIVAL_MATH  = 'resources/js/gallery/ArrivalMath.js';

    public function test_payloads_carry_arrival_enabled_by_default(): void
    {
        $this->seed(\Database\Seeders\VenueTemplateSeeder::class);

        $gallery = $this->liveGallery();
        $html    = $this->get("/gallery/{$gallery->slug}")->getContent();

        $this->assertStringContainsString('"arrival_enabled":true', $html,
            'The public viewer payload must expose the arrival flag (default on).');

        $preview = $this->get(route('venues.preview', 'white-cube'))->getContent();
        $this->assertStringContainsString('"arrival_enabled":true', $preview,
            'The venue preview payload must expose the arrival flag (default on).');
    }

    public function test_admin_live_preview_payload_carries_arrival_key(): void
    {
        $gallery = $this->liveGallery();

        $response = $this->actingAs($gallery->user)
            ->get(route('admin.galleries.preview', $gallery));

        $response->assertOk();
        $this->assertStringContainsString('"arrival_enabled":true', $response->getContent(),
            'The admin live preview exercises the SAME first frame visitors get.');
    }

    public function test_flag_off_disables_arrival_in_every_payload(): void
    {
        $this->seed(\Database\Seeders\VenueTemplateSeeder::class);
        config(['feature_flags.flags.arrival_choreography' => false]);

        $gallery = $this->liveGallery();

        $this->assertStringContainsString(
            '"arrival_enabled":false',
            $this->get("/gallery/{$gallery->slug}")->getContent(),
            'Flag off must reach the public viewer payload.'
        );
        $this->assertStringContainsString(
            '"arrival_enabled":false',
            $this->get(route('venues.preview', 'white-cube'))->getContent(),
            'Flag off must reach the venue preview payload.'
        );
    }

    public function test_deep_link_still_reaches_the_runtime_alongside_arrival(): void
    {
        $gallery = $this->liveGallery();
        $artwork = $gallery->images->first();

        $html = $this->get("/gallery/{$gallery->slug}?artwork={$artwork->id}")->getContent();

        $this->assertStringContainsString('"deepLinkArtworkId":' . $artwork->id, $html,
            'Deep-link payloads must keep carrying the target artwork id…');
        $this->assertStringContainsString('"arrival_enabled":true', $html,
            '…alongside the arrival key — precedence is enforced in the runtime (deep link wins the camera).');
    }

    public function test_arrival_flag_is_registered_and_documented(): void
    {
        $config = file_get_contents(base_path('config/feature_flags.php'));

        $this->assertStringContainsString("'arrival_choreography'", $config,
            'feature_flags.php must register the arrival_choreography flag.');
        $this->assertStringContainsString("env('FEATURE_FLAG_ARRIVAL', true)", $config,
            'The flag must default to true and read FEATURE_FLAG_ARRIVAL.');

        $env = file_get_contents(base_path('.env.example'));
        $this->assertStringContainsString('FEATURE_FLAG_ARRIVAL=true', $env,
            '.env.example must document the rollback switch.');
    }

    public function test_main_js_hands_the_camera_to_the_arrival_on_enter(): void
    {
        $main = file_get_contents(base_path('resources/js/gallery/main.js'));

        $this->assertStringContainsString("import { playArrival }  from './Arrival.js';", $main,
            'main.js must import the arrival orchestrator.');
        $this->assertStringContainsString('playArrival(galleryScene);', $main,
            'main.js must call playArrival in the Enter handler.');
    }

    public function test_movement_ignores_input_while_the_dolly_owns_the_camera(): void
    {
        $movement = file_get_contents(base_path('resources/js/gallery/Movement.js'));

        // Desktop guard.
        $this->assertStringContainsString('if (this.arrivalActive) return;', $movement,
            'Movement must early-return while arrivalActive (both desktop and mobile paths).');
        $this->assertSame(2, substr_count($movement, 'if (this.arrivalActive) return;'),
            'Both updateMovement and updateMovementMobile must carry the arrival guard.');
    }

    public function test_focus_detection_stays_quiet_during_the_reveal(): void
    {
        $focus = file_get_contents(base_path('resources/js/gallery/FocusMode.js'));
        $this->assertStringContainsString('if (this.arrivalActive) return;', $focus,
            'checkArtworkFocus must skip while the arrival owns the camera.');
    }

    public function test_guided_tour_starts_from_the_hero(): void
    {
        $tour = file_get_contents(base_path('resources/js/gallery/Tour.js'));

        $this->assertStringContainsString('arrivalHeroId', $tour,
            'The tour must read the arrival hero for start-position alignment (roadmap §17).');
        $this->assertStringContainsString('atIndex === 0', $tour,
            'Alignment applies to the default entry point; explicit indices win.');
    }

    public function test_arrival_modules_contain_zero_venue_slugs(): void
    {
        $code = file_get_contents(base_path(self::ARRIVAL_JS)) . file_get_contents(base_path(self::ARRIVAL_MATH));

        foreach ([
            'white-cube', 'dark-museum', 'sculpture-garden', 'industrial-loft',
            'infinite-void', 'crystal-cathedral', 'nebula-drift', 'mirror-lake',
            'zen-gallery', 'luxury-penthouse', 'cyber-gallery',
        ] as $slug) {
            $this->assertStringNotContainsString($slug, $code,
                "DoD #7: the arrival modules must not know the slug '{$slug}'.");
        }
    }

    public function test_hero_selection_is_deterministic_and_rng_free(): void
    {
        $math = file_get_contents(base_path(self::ARRIVAL_MATH));

        $this->assertStringNotContainsString('Math.random', $math,
            'DoD #4: hero selection must be deterministic — same gallery, same hero, every load.');
        $this->assertStringContainsString('b.area - a.area) || (a.index - b.index)', $math,
            'Ties must resolve to the earlier hang order (stable, seed-independent).');
    }

    public function test_choreography_constants_match_the_roadmap_contract(): void
    {
        $math = file_get_contents(base_path(self::ARRIVAL_MATH));

        $this->assertStringContainsString("duration: 1.5", $math,
            'Roadmap contract: 1.5 s ease-out dolly.');
        $this->assertStringContainsString("ease: 'power2.out'", $math,
            'The dolly must ease OUT (fast reveal, gentle settle).');
        $this->assertStringContainsString('minGapForDolly', $math,
            'Tight domains must degrade to an instant composed cut, never a clipped move.');
    }

    public function test_arrival_honours_reduced_motion_and_deep_link_precedence(): void
    {
        $js = file_get_contents(base_path(self::ARRIVAL_JS));

        $this->assertStringContainsString('scene.reducedMotion || moveDistance === 0', $js,
            'Reduced motion ⇒ instant composed cut, no tween (§17 testing row).');
        $this->assertStringContainsString('deepLinkArtworkId', $js,
            'Deep-link precedence must be enforced before any camera movement (§17 testing row).');
        $this->assertStringContainsString('EXOSPACE_EMBED_MODE', $js,
            'Embeds skip the ceremony (they already skip the curtain).');
    }

    public function test_arrivalmath_is_dependency_free_for_ci_execution(): void
    {
        $math = file_get_contents(base_path(self::ARRIVAL_MATH));

        $this->assertStringNotContainsString('import ', $math,
            'ArrivalMath.js must stay dependency-free (zero imports) so the Node harness can pin the math.');
        $this->assertStringNotContainsString('three', strtolower(preg_replace('/\/\/[^\n]*/', '', $math)),
            'ArrivalMath.js must not reference three.js — pure math only.');
    }

    public function test_classic_spawn_is_retired_by_nothing(): void
    {
        $roomBuilder = file_get_contents(base_path('resources/js/gallery/RoomBuilder.js'));
        $arrival     = file_get_contents(base_path(self::ARRIVAL_JS));

        $this->assertStringContainsString('this.camera.position.set(-length / 2 + 1.5', $roomBuilder,
            'Corridor spawn point untouched (classic spawn retained).');
        $this->assertStringContainsString('this.camera.position.set(aCX', $roomBuilder,
            'L-shape spawn point untouched (classic spawn retained).');
        foreach (['- length / 2', 'aCX', 'lenA', 'wallHeight'] as $layoutLiteral) {
            $this->assertStringNotContainsString($layoutLiteral, $arrival,
                "Arrival must never hard-code layout knowledge ('{$layoutLiteral}' belongs to RoomBuilder).");
        }
        $this->assertStringContainsString('scene.camera.position.set(start.x, start.y, start.z)', $arrival,
            'Frame 1 poses from the composed start; the dolly ends at the untouched classic spawn.');
    }

    private function liveGallery(): Gallery
    {
        $user = User::factory()->create([
            'plan'          => 'pro',
            'max_galleries' => 5,
            'max_images'    => 100,
        ]);

        $gallery = Gallery::factory()->create([
            'user_id'   => $user->id,
            'is_active' => false,
        ]);

        GalleryImage::factory()->create(['gallery_id' => $gallery->id]);

        $this->actingAs($user)
            ->withHeaders(['referer' => url("/admin/galleries/{$gallery->id}/edit")])
            ->post("/admin/galleries/{$gallery->id}/publish")
            ->assertRedirect("/admin/galleries/{$gallery->id}/edit");

        return $gallery->fresh();
    }
}
