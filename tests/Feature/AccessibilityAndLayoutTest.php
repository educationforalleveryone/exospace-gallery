<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Iteration-012 regression tests for accessibility & UI/UX findings:
 *   - J-1: Dropdown component keyboard-accessible (button trigger, ARIA, arrow keys)
 *   - J-2: Navigation dropdowns (notif bell, team switcher, mobile hamburger) have ARIA state
 *   - J-4: img tags missing alt attributes
 *   - H-1: Contact page uses the public layout
 *   - H-2: Discover page uses the public layout
 */
class AccessibilityAndLayoutTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function j1_dropdown_component_uses_button_trigger_with_aria(): void
    {
        $source = file_get_contents(resource_path('views/components/dropdown.blade.php'));

        $this->assertStringContainsString('<button type="button"', $source, 'J-1: trigger must be a <button>, not a <div>');
        $this->assertStringContainsString('aria-haspopup="true"', $source, 'J-1: button must have aria-haspopup');
        $this->assertStringContainsString('aria-expanded', $source, 'J-1: button must have aria-expanded (Alpine-bound)');
        $this->assertStringContainsString('aria-controls', $source, 'J-1: button must have aria-controls');
        $this->assertStringContainsString('role="menu"', $source, 'J-1: panel must have role="menu"');
        $this->assertStringContainsString('aria-labelledby', $source, 'J-1: panel must have aria-labelledby');
        $this->assertStringContainsString('keydown.arrow-down', $source, 'J-1: panel must handle ArrowDown');
        $this->assertStringContainsString('keydown.arrow-up', $source, 'J-1: panel must handle ArrowUp');
        $this->assertStringContainsString('keydown.escape', $source, 'J-1: panel must handle Escape');

        // The old <div @click> pattern must be gone.
        $this->assertStringNotContainsString('<div @click="open = ! open">', $source, 'J-1: old clickable-div trigger must be removed');
    }

    /** @test */
    public function j1_dropdown_link_has_menuitem_role(): void
    {
        $source = file_get_contents(resource_path('views/components/dropdown-link.blade.php'));

        // ITERATION-1 FIX: match the component's actual Blade quoting
        // (['role' => 'menuitem', 'tabindex' => '-1']).
        $this->assertStringContainsString("'role' => 'menuitem'", $source, 'J-1: dropdown-link must have role="menuitem"');
        $this->assertStringContainsString("'tabindex' => '-1'", $source, 'J-1: dropdown-link must have tabindex="-1" (focusable via arrow keys)');
    }

    /** @test */
    public function j2_notification_bell_has_aria_state(): void
    {
        $source = file_get_contents(resource_path('views/layouts/navigation.blade.php'));

        $this->assertStringContainsString('id="notif-dropdown-trigger"', $source, 'J-2: notif bell must have id');
        $this->assertStringContainsString('aria-controls="notif-dropdown-panel"', $source, 'J-2: notif bell must have aria-controls');
        $this->assertStringContainsString('id="notif-dropdown-panel"', $source, 'J-2: notif panel must have id');
        $this->assertStringContainsString('aria-labelledby="notif-dropdown-trigger"', $source, 'J-2: notif panel must have aria-labelledby');
    }

    /** @test */
    public function j2_team_switcher_has_aria_state(): void
    {
        $source = file_get_contents(resource_path('views/layouts/navigation.blade.php'));

        $this->assertStringContainsString('id="team-dropdown-trigger"', $source, 'J-2: team switcher must have id');
        $this->assertStringContainsString('aria-controls="team-dropdown-panel"', $source, 'J-2: team switcher must have aria-controls');
        $this->assertStringContainsString('id="team-dropdown-panel"', $source, 'J-2: team panel must have id');
        $this->assertStringContainsString('aria-label="Switch team context"', $source, 'J-2: team switcher must have aria-label');
    }

    /** @test */
    public function j2_mobile_hamburger_has_aria_state(): void
    {
        $source = file_get_contents(resource_path('views/layouts/navigation.blade.php'));

        $this->assertStringContainsString('id="mobile-nav-toggle"', $source, 'J-2: hamburger must have id');
        $this->assertStringContainsString("aria-label=\"open ? 'Close menu' : 'Open menu'\"", $source, 'J-2: hamburger must have aria-label');
        $this->assertStringContainsString('aria-controls="mobile-nav"', $source, 'J-2: hamburger must have aria-controls');
        $this->assertStringContainsString('id="mobile-nav"', $source, 'J-2: mobile nav panel must have id');
        $this->assertStringContainsString('aria-labelledby="mobile-nav-toggle"', $source, 'J-2: mobile nav panel must have aria-labelledby');
    }

    /** @test */
    public function j4_analytics_page_artwork_thumbnail_has_alt(): void
    {
        $source = file_get_contents(resource_path('views/admin/galleries/analytics.blade.php'));

        // Find the <img> tag for the artwork thumbnail.
        $this->assertStringContainsString('alt="{{ $img->title ?: $img->original_name ?: \'Artwork\' }}"', $source, 'J-4: artwork thumbnail must have alt attribute');
        $this->assertStringNotContainsString('<img src="{{ asset($img->path) }}" class="w-10 h-10', $source, 'J-4: img tag must not be missing alt');
    }

    /** @test */
    public function j4_dashboard_gallery_row_has_alt(): void
    {
        $source = file_get_contents(resource_path('views/components/dashboard/gallery-row.blade.php'));
        $this->assertStringContainsString('alt="{{ $gallery->title ?: \'Gallery cover\' }}"', $source, 'J-4: gallery-row cover must have alt');
    }

    /** @test */
    public function j4_artist_form_portrait_has_alt(): void
    {
        $source = file_get_contents(resource_path('views/admin/artists/_form-fields.blade.php'));
        $this->assertStringContainsString('alt="{{ $artist->name ?: \'Artist portrait\' }}"', $source, 'J-4: artist portrait must have alt');
    }

    /** @test */
    public function j4_venue_form_thumbnail_has_alt(): void
    {
        $source = file_get_contents(resource_path('views/super-admin/venues/_form-fields.blade.php'));
        $this->assertStringContainsString('alt="{{ $venue->name ?: \'Venue thumbnail\' }}"', $source, 'J-4: venue thumbnail must have alt');
    }

    /** @test */
    public function j4_featured_index_cover_has_alt(): void
    {
        $source = file_get_contents(resource_path('views/super-admin/featured/index.blade.php'));
        $this->assertStringContainsString('alt="{{ $gallery->title ?: \'Featured gallery cover\' }}"', $source, 'J-4: featured cover must have alt');
    }

    /** @test */
    public function h1_contact_page_uses_public_layout(): void
    {
        $source = file_get_contents(resource_path('views/pages/contact.blade.php'));

        $this->assertStringStartsWith("@extends('layouts.public')", $source, 'H-1: contact page must extend layouts.public');
        $this->assertStringContainsString("@section('title'", $source, 'H-1: contact page must define title section');
        $this->assertStringContainsString("@section('description'", $source, 'H-1: contact page must define description section');
        $this->assertStringContainsString("@section('content')", $source, 'H-1: contact page must define content section');

        // Standalone HTML structure must be gone.
        $this->assertStringNotContainsString('<!DOCTYPE', $source, 'H-1: no <!DOCTYPE> in view (layout provides it)');
        $this->assertStringNotContainsString('<html', $source, 'H-1: no <html> in view');
        $this->assertStringNotContainsString('<head>', $source, 'H-1: no <head> in view');
        $this->assertStringNotContainsString('<body>', $source, 'H-1: no <body> in view');

        // The standalone <nav> must be gone (public layout provides its own).
        $this->assertStringNotContainsString('<!-- Nav -->', $source, 'H-1: standalone nav removed');

        // The [Country] placeholder must be gone.
        $this->assertStringNotContainsString('[Country]', $source, 'H-1: [Country] placeholder removed');
    }

    /** @test */
    public function h2_discover_page_uses_public_layout(): void
    {
        $source = file_get_contents(resource_path('views/discover/index.blade.php'));

        // ITERATION-1 FIX: metadata moved from Blade sections to the
        // controller-built SeoData object (SEO OS Iteration 2) — the
        // layout/content contracts are what matter for accessibility.
        $this->assertStringContainsString("@extends('layouts.public')", $source, 'H-2: discover page must extend layouts.public');
        $this->assertStringContainsString("@section('content')", $source, 'H-2: discover page must define content section');
        $this->assertStringContainsString("@endsection", $source, 'H-2: discover page must end with @endsection');

        // The old <x-guest-layout> pattern must be gone.
        $this->assertStringNotContainsString('<x-guest-layout>', $source, 'H-2: <x-guest-layout> removed');
        $this->assertStringNotContainsString('</x-guest-layout>', $source, 'H-2: </x-guest-layout> removed');
        $this->assertStringNotContainsString('<x-slot name="header">', $source, 'H-2: <x-slot name="header"> removed');
    }

    /** @test */
    public function h2_discover_page_still_renders_via_http(): void
    {
        // Smoke test: ensure the page compiles after the layout conversion.
        // We don't need real galleries — just verify no Blade syntax errors.
        $gallery = \App\Models\Gallery::factory()->create([
            'is_active' => true,
            'is_featured' => true,
        ]);
        \App\Models\GalleryImage::factory()->create([
            'gallery_id' => $gallery->id,
        ]);

        $response = $this->get(route('discover'));

        $response->assertStatus(200);
        $response->assertSee('Featured 3D Exhibitions');
    }
}
