<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\Gallery;
use App\Models\GalleryImage;
use App\Models\GalleryScheduleEvent;
use App\Models\User;
use App\Services\VenueConfigExporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InputValidationBoundariesTest extends TestCase
{
    use RefreshDatabase;

    private User $curator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->curator = User::factory()->create(['email_verified_at' => now()]);
    }

    private function gallery(): Gallery
    {
        return Gallery::factory()->create(['user_id' => $this->curator->id]);
    }

    private function updatePayload(array $overrides = []): array
    {
        return array_merge([
            'title'           => 'Validation Boundary Gallery',
            'wall_texture'    => 'white',
            'frame_style'     => 'modern',
            'lighting_preset' => 'dramatic',
            'floor_material'  => 'marble',
            'room_layout'     => 'rotunda',
        ], $overrides);
    }

    // ── Gallery image reordering ─────────────────────────────────────────

    public function test_reorder_accepts_a_valid_positional_list(): void
    {
        $gallery = $this->gallery();
        $a = GalleryImage::factory()->create(['gallery_id' => $gallery->id, 'position_order' => 1]);
        $b = GalleryImage::factory()->create(['gallery_id' => $gallery->id, 'position_order' => 2]);
        $c = GalleryImage::factory()->create(['gallery_id' => $gallery->id, 'position_order' => 3]);

        $this->actingAs($this->curator)
            ->postJson(route('admin.galleries.reorder-images', $gallery), [
                'order' => [$c->id, $a->id, $b->id],
            ])
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertSame(1, $gallery->images()->whereKey($c->id)->value('position_order'));
        $this->assertSame(2, $gallery->images()->whereKey($a->id)->value('position_order'));
        $this->assertSame(3, $gallery->images()->whereKey($b->id)->value('position_order'));
    }

    public function test_reorder_rejects_an_oversized_order_list(): void
    {
        $gallery = $this->gallery();

        $ids = [];
        for ($i = 0; $i < 501; $i++) {
            $ids[] = GalleryImage::factory()->create(['gallery_id' => $gallery->id])->id;
            if (count($ids) > 501) {
                array_shift($ids);
            }
        }

        $this->actingAs($this->curator)
            ->postJson(route('admin.galleries.reorder-images', $gallery), ['order' => $ids])
            ->assertJsonValidationErrors('order');
    }

    public function test_reorder_rejects_duplicate_image_ids(): void
    {
        $gallery = $this->gallery();
        $a = GalleryImage::factory()->create(['gallery_id' => $gallery->id]);

        $this->actingAs($this->curator)
            ->postJson(route('admin.galleries.reorder-images', $gallery), [
                'order' => [$a->id, $a->id],
            ])
            ->assertJsonValidationErrors(['order.0', 'order.1']);
    }

    public function test_reorder_rejects_non_integer_entries(): void
    {
        $gallery = $this->gallery();

        $this->actingAs($this->curator)
            ->postJson(route('admin.galleries.reorder-images', $gallery), [
                'order' => ['first'],
            ])
            ->assertJsonValidationErrors('order.0');
    }

    public function test_reorder_tolerates_a_json_object_body_without_a_server_error(): void
    {
        // A hand-edited request can send an object instead of a positional
        // list; that must never surface as a 500 from the position math.
        $gallery = $this->gallery();
        $a = GalleryImage::factory()->create(['gallery_id' => $gallery->id, 'position_order' => 9]);
        $b = GalleryImage::factory()->create(['gallery_id' => $gallery->id, 'position_order' => 9]);

        $this->actingAs($this->curator)
            ->postJson(route('admin.galleries.reorder-images', $gallery), [
                'order' => ['first' => $a->id, 'second' => $b->id],
            ])
            ->assertOk();

        $this->assertSame(1, $gallery->images()->whereKey($a->id)->value('position_order'));
        $this->assertSame(2, $gallery->images()->whereKey($b->id)->value('position_order'));
    }

    // ── Per-artwork metadata ─────────────────────────────────────────────

    public function test_artwork_metadata_accepts_valid_sales_fields(): void
    {
        $gallery = $this->gallery();
        $image = GalleryImage::factory()->create(['gallery_id' => $gallery->id]);

        $this->actingAs($this->curator)
            ->putJson(route('admin.galleries.images.metadata', [$gallery, $image]), [
                'title'         => 'Untitled',
                'price'         => '1250.50',
                'currency'      => 'USD',
                'for_sale'      => 1,
                'edition_size'  => 25,
            ])
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertSame('1250.50', (string) $image->fresh()->price);
        $this->assertSame('USD', $image->fresh()->currency);
        $this->assertSame(25, $image->fresh()->edition_size);
    }

    public function test_artwork_metadata_rejects_an_edition_size_beyond_the_column_range(): void
    {
        $gallery = $this->gallery();
        $image = GalleryImage::factory()->create(['gallery_id' => $gallery->id]);

        $this->actingAs($this->curator)
            ->putJson(route('admin.galleries.images.metadata', [$gallery, $image]), [
                'edition_size' => 99999999999,
            ])
            ->assertJsonValidationErrors('edition_size');
    }

    public function test_artwork_metadata_rejects_a_non_currency_code(): void
    {
        $gallery = $this->gallery();
        $image = GalleryImage::factory()->create(['gallery_id' => $gallery->id]);

        $this->actingAs($this->curator)
            ->putJson(route('admin.galleries.images.metadata', [$gallery, $image]), [
                'currency' => '12<',
            ])
            ->assertJsonValidationErrors('currency');
    }

    // ── Exhibition schedule events ───────────────────────────────────────

    public function test_schedule_event_accepts_a_valid_capacity(): void
    {
        $gallery = $this->gallery();

        $this->actingAs($this->curator)
            ->post(route('admin.galleries.events.store', $gallery), [
                'title'     => 'Opening night',
                'type'      => 'opening',
                'starts_at' => now()->addWeek()->format('Y-m-d\TH:i'),
                'timezone'  => 'UTC',
                'capacity'  => 40,
            ])
            ->assertRedirect()
            ->assertSessionHas('status');

        $event = GalleryScheduleEvent::query()->where('gallery_id', $gallery->id)->first();
        $this->assertNotNull($event);
        $this->assertSame(40, $event->capacity);
    }

    public function test_schedule_event_rejects_a_capacity_beyond_the_column_range(): void
    {
        $gallery = $this->gallery();

        $this->actingAs($this->curator)
            ->postJson(route('admin.galleries.events.store', $gallery), [
                'title'     => 'Opening night',
                'type'      => 'opening',
                'starts_at' => now()->addWeek()->format('Y-m-d\TH:i'),
                'capacity'  => 99999999999,
            ])
            ->assertJsonValidationErrors('capacity');

        $this->assertSame(0, GalleryScheduleEvent::query()->where('gallery_id', $gallery->id)->count());
    }

    // ── Bulk image deletion ──────────────────────────────────────────────

    public function test_bulk_delete_rejects_duplicate_ids(): void
    {
        $gallery = $this->gallery();
        $image = GalleryImage::factory()->create(['gallery_id' => $gallery->id]);

        $this->actingAs($this->curator)
            ->postJson(route('admin.images.bulk_destroy'), [
                'ids' => [$image->id, $image->id],
            ])
            ->assertJsonValidationErrors('ids.0');

        $this->assertNotNull($image->fresh());
    }

    public function test_bulk_delete_rejects_an_oversized_id_list(): void
    {
        $ids = [];
        for ($i = 0; $i < 501; $i++) {
            $ids[] = $i + 100000;
        }

        $this->actingAs($this->curator)
            ->postJson(route('admin.images.bulk_destroy'), ['ids' => $ids])
            ->assertJsonValidationErrors('ids');
    }

    public function test_bulk_delete_deletes_owned_images_from_a_valid_list(): void
    {
        $gallery = $this->gallery();
        $a = GalleryImage::factory()->create(['gallery_id' => $gallery->id]);
        $b = GalleryImage::factory()->create(['gallery_id' => $gallery->id]);

        $this->actingAs($this->curator)
            ->postJson(route('admin.images.bulk_destroy'), ['ids' => [$a->id, $b->id]])
            ->assertOk()
            ->assertJsonPath('deleted', 2);

        $this->assertSoftDeleted($a);
        $this->assertSoftDeleted($b);
    }

    // ── Per-gallery visual override payloads ─────────────────────────────

    public function test_visual_override_payloads_are_normalized_to_flat_scalar_settings(): void
    {
        $gallery = $this->gallery();

        $json = json_encode([
            'visual_config'   => [
                'fog_near'   => 12,
                'nested'     => ['deep' => ['deeper' => 1]],
            ],
            'material_config' => [
                'floor_roughness' => 0.5,
                5                 => 'positional',
            ],
            'unknown_bucket'  => ['a' => 1],
            'scalar_root'     => 'x',
        ]);

        $this->actingAs($this->curator)
            ->put(route('admin.galleries.update', $gallery), $this->updatePayload([
                'visual_overrides_json' => $json,
            ]))
            ->assertRedirect()
            ->assertSessionDoesntHaveErrors();

        $gallery->refresh();
        $this->assertSame([
            'visual_config'   => ['fog_near' => 12],
            'material_config' => ['floor_roughness' => 0.5],
        ], $gallery->visual_overrides);
    }

    public function test_visual_override_payloads_drop_oversized_keys_and_values(): void
    {
        $sanitized = VenueConfigExporter::sanitizeGalleryOverrides([
            'visual_config' => [
                str_repeat('k', 65) => 1,
                str_repeat('k', 64) => 2,
                'frame_override'    => str_repeat('v', 256),
                'frame_style'       => 'classic',
                'open_air'          => false,
            ],
        ]);

        $this->assertSame([
            str_repeat('k', 64) => 2,
            'frame_style'       => 'classic',
            'open_air'          => false,
        ], $sanitized['visual_config']);
    }

    public function test_visual_override_payloads_cap_bucket_key_counts(): void
    {
        $keys = [];
        for ($i = 0; $i < 60; $i++) {
            $keys['override_key_' . $i] = $i;
        }

        $sanitized = VenueConfigExporter::sanitizeGalleryOverrides(['visual_config' => $keys]);

        $this->assertCount(40, $sanitized['visual_config']);
        $this->assertArrayNotHasKey('override_key_40', $sanitized['visual_config']);
    }

    public function test_preview_runtime_overrides_are_sanitized(): void
    {
        $gallery = $this->gallery();

        $override = base64_encode(json_encode([
            'visual_config' => ['fog_near' => 5, 'nested' => ['x' => 1]],
        ]));

        $this->actingAs($this->curator)
            ->get(route('admin.galleries.preview', $gallery) . '?override=' . $override)
            ->assertOk();
    }

    // ── Schedule date semantics ──────────────────────────────────────────

    public function test_closes_at_is_accepted_without_opens_at(): void
    {
        $this->curator = User::factory()->pro()->create(['email_verified_at' => now()]);
        $gallery = $this->gallery();
        $closesAt = now()->addMonth();

        $this->actingAs($this->curator)
            ->put(route('admin.galleries.update', $gallery), $this->updatePayload([
                'closes_at' => $closesAt->format('Y-m-d\TH:i'),
            ]))
            ->assertRedirect()
            ->assertSessionDoesntHaveErrors();

        $gallery->refresh();
        $this->assertNull($gallery->opens_at);
        $this->assertTrue($gallery->closes_at->equalTo($closesAt->startOfMinute()));
    }

    public function test_closes_at_before_opens_at_is_rejected(): void
    {
        $this->curator = User::factory()->pro()->create(['email_verified_at' => now()]);
        $gallery = $this->gallery();

        $this->actingAs($this->curator)
            ->put(route('admin.galleries.update', $gallery), $this->updatePayload([
                'opens_at'  => now()->addMonth()->format('Y-m-d\TH:i'),
                'closes_at' => now()->addWeek()->format('Y-m-d\TH:i'),
            ]))
            ->assertSessionHasErrors('closes_at');
    }

    // ── Artist search term handling ──────────────────────────────────────

    public function test_artist_search_matches_normal_terms(): void
    {
        Artist::factory()->create(['name' => 'Ada Picasso', 'created_by' => $this->curator->id]);

        $this->actingAs($this->curator)
            ->getJson(route('admin.artists.search', ['q' => 'Picasso']))
            ->assertOk()
            ->assertJsonFragment(['name' => 'Ada Picasso']);
    }

    public function test_artist_search_tolerates_extremely_long_terms(): void
    {
        Artist::factory()->create(['name' => 'Ada Picasso', 'created_by' => $this->curator->id]);

        $this->actingAs($this->curator)
            ->getJson(route('admin.artists.search', ['q' => str_repeat('x', 5000)]))
            ->assertOk()
            ->assertJsonCount(0);

        $this->actingAs($this->curator)
            ->getJson(route('admin.artists.search', ['q' => str_repeat('Ada Picasso ', 500)]))
            ->assertOk();
    }

    public function test_artist_directory_index_tolerates_an_extremely_long_term(): void
    {
        Artist::factory()->create(['name' => 'Ada Picasso', 'created_by' => $this->curator->id]);

        $this->actingAs($this->curator)
            ->get(route('admin.artists.index', ['q' => str_repeat('x', 5000)]))
            ->assertOk();
    }

    // ── SEO acquisition reporting window ─────────────────────────────────

    public function test_seo_acquisition_window_is_clamped(): void
    {
        $superAdmin = User::factory()->withMfa()->create([
            'is_super_admin'    => true,
            'email_verified_at' => now(),
        ]);

        foreach (['999999999999999999', '0', '-5', '90'] as $days) {
            $this->actingAs($superAdmin)
                ->withSession([
                    'mfa_verified'    => true,
                    'mfa_verified_at' => now()->timestamp,
                ])
                ->get('/master-control/seo?tab=acquisition&days=' . $days)
                ->assertOk();
        }
    }
}
