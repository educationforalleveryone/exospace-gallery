<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Gallery;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GalleryApiPaginationClampTest extends TestCase
{
    use RefreshDatabase;

    public function test_per_page_below_one_is_clamped_not_unbounded(): void
    {
        foreach (['0', '-1', '-25'] as $perPage) {
            $response = $this->getJson('/api/v1/galleries?per_page='.$perPage);

            $response->assertOk();
            $this->assertGreaterThanOrEqual(
                1,
                $response->json('meta.pagination.per_page'),
                "per_page={$perPage} must be clamped to a positive page size, not disable the limit."
            );
            $this->assertLessThanOrEqual(
                100,
                $response->json('meta.pagination.per_page')
            );
        }
    }

    public function test_per_page_above_cap_is_clamped(): void
    {
        $response = $this->getJson('/api/v1/galleries?per_page=100000');

        $response->assertOk();
        $this->assertSame(100, $response->json('meta.pagination.per_page'));
    }

    public function test_gallery_images_per_page_below_one_is_clamped(): void
    {
        $user = User::factory()->create();
        $gallery = Gallery::factory()->create(['user_id' => $user->id, 'is_active' => true]);

        $response = $this->getJson("/api/v1/galleries/{$gallery->slug}/images?per_page=-3");

        $response->assertOk();
        $this->assertGreaterThanOrEqual(1, $response->json('meta.pagination.per_page'));
        $this->assertLessThanOrEqual(200, $response->json('meta.pagination.per_page'));
    }

    public function test_non_numeric_per_page_is_still_bounded(): void
    {
        $response = $this->getJson('/api/v1/galleries?per_page=abc');

        $response->assertOk();
        $perPage = $response->json('meta.pagination.per_page');
        $this->assertGreaterThanOrEqual(1, $perPage);
        $this->assertLessThanOrEqual(100, $perPage);
    }
}
