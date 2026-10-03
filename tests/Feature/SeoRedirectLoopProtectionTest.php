<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\SeoRedirect;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SeoRedirectLoopProtectionTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsMfaSuperAdmin(): self
    {
        $admin = \App\Models\User::factory()->withMfa()->create([
            'is_super_admin'    => true,
            'email_verified_at' => now(),
        ]);

        return $this->actingAs($admin)->withSession([
            'mfa_verified'    => true,
            'mfa_verified_at' => now()->timestamp,
        ]);
    }

    #[Test]
    public function self_referencing_redirect_is_rejected(): void
    {
        $response = $this->actingAsMfaSuperAdmin()
            ->post('/master-control/seo/redirects', [
                'source_path' => '/same-page',
                'destination' => '/same-page',
                'status_code' => 301,
            ]);

        $response->assertSessionHasErrors('destination');
        $this->assertSame(0, SeoRedirect::count());
    }

    #[Test]
    public function duplicate_active_source_is_rejected(): void
    {
        SeoRedirect::create([
            'source_path' => 'existing-path',
            'destination' => '/discover',
            'status_code' => 301,
        ]);

        $response = $this->actingAsMfaSuperAdmin()
            ->post('/master-control/seo/redirects', [
                'source_path' => '/existing-path',
                'destination' => '/pricing',
                'status_code' => 301,
            ]);

        $response->assertSessionHasErrors('source_path');
        $this->assertSame(1, SeoRedirect::count(), 'Only the pre-existing redirect survives.');
    }

    #[Test]
    public function two_way_cycle_is_rejected(): void
    {
        SeoRedirect::create([
            'source_path' => 'first',
            'destination' => '/second',
            'status_code' => 301,
        ]);

        $response = $this->actingAsMfaSuperAdmin()
            ->post('/master-control/seo/redirects', [
                'source_path' => '/second',
                'destination' => '/first',
                'status_code' => 301,
            ]);

        $response->assertSessionHasErrors('destination');
        $this->assertSame(1, SeoRedirect::count());
    }

    #[Test]
    public function chain_that_walks_back_onto_the_new_source_is_rejected(): void
    {
        SeoRedirect::create([
            'source_path' => 'landing-a',
            'destination' => '/landing-b',
            'status_code' => 301,
        ]);
        SeoRedirect::create([
            'source_path' => 'landing-b',
            'destination' => '/landing-c',
            'status_code' => 301,
        ]);

        // c → a → b → c: the walk lands back on the new source.
        $response = $this->actingAsMfaSuperAdmin()
            ->post('/master-control/seo/redirects', [
                'source_path' => '/landing-c',
                'destination' => '/landing-a',
                'status_code' => 301,
            ]);

        $response->assertSessionHasErrors('destination');
        $this->assertSame(2, SeoRedirect::count());
    }

    #[Test]
    public function absolute_destination_cannot_loop(): void
    {
        SeoRedirect::create([
            'source_path' => 'promo',
            'destination' => '/pricing',
            'status_code' => 301,
        ]);

        // Same final path as the self-loop rejection case, but an absolute
        // URL leaves the host — it can never bounce back through the map.
        $this->actingAsMfaSuperAdmin()
            ->post('/master-control/seo/redirects', [
                'source_path' => '/pricing-info',
                'destination' => 'https://partners.example/pricing',
                'status_code' => 301,
            ])
            ->assertRedirect();

        $this->assertSame(2, SeoRedirect::count());
    }

    #[Test]
    public function valid_chained_redirect_is_still_accepted(): void
    {
        SeoRedirect::create([
            'source_path' => 'old-exhibition',
            'destination' => '/discover',
            'status_code' => 301,
        ]);

        $this->actingAsMfaSuperAdmin()
            ->post('/master-control/seo/redirects', [
                'source_path' => '/legacy-promo',
                'destination' => '/discover',
                'status_code' => 301,
            ])
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertSame(2, SeoRedirect::count());
    }

    #[Test]
    public function redirect_hits_are_recorded_for_the_admin_table(): void
    {
        $redirect = SeoRedirect::create([
            'source_path' => 'tracked-page',
            'destination' => '/discover',
            'status_code' => 301,
        ]);
        SeoRedirect::clearMapCache();

        $this->assertSame(0, $redirect->fresh()->hits);

        $this->get('/tracked-page?utm_source=x')->assertStatus(301);

        $fresh = $redirect->fresh();
        $this->assertSame(1, $fresh->hits);
        $this->assertNotNull($fresh->last_hit_at);
    }

    #[Test]
    public function recording_a_hit_for_a_missing_redirect_is_tolerated(): void
    {
        // Analytics are fire-and-forget: a stale map entry pointing at a
        // deleted row must stay a no-op, never an error on a public route.
        SeoRedirect::recordHit(999999);

        $this->assertSame(0, SeoRedirect::count());
    }
}
