<?php

namespace Tests\Feature;

use App\Models\Gallery;
use App\Models\NewsletterSignup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NewsletterAccessBoundaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_can_sign_up_to_a_public_active_gallery(): void
    {
        $gallery = Gallery::factory()->create(['is_active' => true]);

        $response = $this->postJson(route('gallery.newsletter', $gallery->slug), [
            'email' => 'collector@example.com',
            'name' => 'Collector',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('is_new', true);

        $this->assertSame(1, NewsletterSignup::where('gallery_id', $gallery->id)
            ->where('email', 'collector@example.com')->count());
    }

    public function test_draft_gallery_does_not_accept_newsletter_signups(): void
    {
        $gallery = Gallery::factory()->inactive()->create();

        $response = $this->postJson(route('gallery.newsletter', $gallery->slug), [
            'email' => 'collector@example.com',
        ]);

        $response->assertNotFound();
        $this->assertSame(0, NewsletterSignup::count());
    }

    public function test_banned_owner_gallery_does_not_accept_newsletter_signups(): void
    {
        $owner = User::factory()->banned()->create();
        $gallery = Gallery::factory()->create([
            'user_id' => $owner->id,
            'is_active' => true,
        ]);

        $response = $this->postJson(route('gallery.newsletter', $gallery->slug), [
            'email' => 'collector@example.com',
        ]);

        $response->assertNotFound();
        $this->assertSame(0, NewsletterSignup::count());
    }

    public function test_pin_protected_gallery_rejects_signups_without_verified_session(): void
    {
        $gallery = Gallery::factory()->pinProtected()->create(['is_active' => true]);

        $response = $this->postJson(route('gallery.newsletter', $gallery->slug), [
            'email' => 'collector@example.com',
        ]);

        $response->assertNotFound();
        $this->assertSame(0, NewsletterSignup::count());
    }

    public function test_pin_protected_gallery_accepts_signups_after_pin_verification(): void
    {
        $gallery = Gallery::factory()->pinProtected('1234')->create(['is_active' => true]);

        $this->post(route('gallery.pin.verify', $gallery->slug), ['pin' => '1234']);

        $response = $this->postJson(route('gallery.newsletter', $gallery->slug), [
            'email' => 'collector@example.com',
        ]);

        $response->assertOk()->assertJsonPath('success', true);

        $this->assertSame(1, NewsletterSignup::where('gallery_id', $gallery->id)
            ->where('email', 'collector@example.com')->count());
    }

    public function test_unknown_gallery_slug_returns_404(): void
    {
        $this->postJson(route('gallery.newsletter', 'no-such-gallery'), [
            'email' => 'collector@example.com',
        ])->assertNotFound();
    }
}
