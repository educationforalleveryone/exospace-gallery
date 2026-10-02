<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\GalleryController;
use App\Jobs\VerifyCustomDomain;
use App\Models\Gallery;
use App\Models\User;
use App\Services\CoolifyDomainManager;
use App\Services\VenueConfigExporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class CustomDomainVerificationTest extends TestCase
{
    use RefreshDatabase;

    private function studioGallery(): Gallery
    {
        $user = User::factory()->create(['plan' => 'studio', 'email_verified_at' => now()]);

        $gallery = Gallery::factory()->for($user, 'user')->create([
            'custom_domain' => 'gallery.example.com',
        ]);
        $gallery->generateDomainVerificationToken();

        return $gallery->refresh();
    }

    private function mockedCoolify(): Mockery\MockInterface
    {
        $coolify = Mockery::mock(CoolifyDomainManager::class);
        $this->app->instance(CoolifyDomainManager::class, $coolify);

        return $coolify;
    }

    private function dnsPassingController(CoolifyDomainManager $coolify): GalleryController
    {
        $controller = Mockery::mock(GalleryController::class, [$coolify, app(VenueConfigExporter::class)])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();
        $controller->shouldReceive('checkDnsTxtRecord')->andReturn(true);
        $this->app->instance(GalleryController::class, $controller);

        return $controller;
    }

    public function test_job_marks_verified_and_registers_with_coolify(): void
    {
        $gallery = $this->studioGallery();

        $coolify = $this->mockedCoolify();
        $coolify->shouldReceive('addDomain')->once()->with('gallery.example.com')
            ->andReturn(['success' => true, 'message' => 'ok']);

        $job = Mockery::mock(VerifyCustomDomain::class, [$gallery->id])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();
        $job->shouldReceive('checkDnsTxtRecord')->once()->andReturn(true);

        $job->handle($coolify);

        $gallery->refresh();
        $this->assertNotNull($gallery->custom_domain_verified_at);
    }

    public function test_job_unmarks_verification_when_coolify_registration_fails(): void
    {
        $gallery = $this->studioGallery();

        $coolify = $this->mockedCoolify();
        $coolify->shouldReceive('addDomain')->once()
            ->andReturn(['success' => false, 'message' => 'Coolify API call failed. Check the logs.']);

        $job = Mockery::mock(VerifyCustomDomain::class, [$gallery->id])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();
        $job->shouldReceive('checkDnsTxtRecord')->once()->andReturn(true);

        $job->handle($coolify);

        $gallery->refresh();
        $this->assertNull(
            $gallery->custom_domain_verified_at,
            'Verification must not stick when Coolify routing failed — the hourly job has to retry.'
        );
        $this->assertNotNull($gallery->custom_domain_verification_token, 'Token must survive for the retry.');
    }

    public function test_job_does_not_verify_or_register_without_dns(): void
    {
        $gallery = $this->studioGallery();

        $coolify = $this->mockedCoolify();
        $coolify->shouldNotReceive('addDomain');

        (new VerifyCustomDomain($gallery->id))->handle($coolify);

        $gallery->refresh();
        $this->assertNull($gallery->custom_domain_verified_at);
    }

    public function test_verify_endpoint_marks_verified_and_reports_success(): void
    {
        $gallery = $this->studioGallery();

        $coolify = $this->mockedCoolify();
        $coolify->shouldReceive('addDomain')->once()->with('gallery.example.com')
            ->andReturn(['success' => true, 'message' => 'ok']);

        $this->dnsPassingController($coolify);

        $this->actingAs($gallery->user)
            ->post(route('admin.galleries.verify-domain', $gallery))
            ->assertRedirect()
            ->assertSessionHas('status');

        $gallery->refresh();
        $this->assertNotNull($gallery->custom_domain_verified_at);
    }

    public function test_verify_endpoint_unmarks_verification_and_warns_when_coolify_fails(): void
    {
        $gallery = $this->studioGallery();

        $coolify = $this->mockedCoolify();
        $coolify->shouldReceive('addDomain')->once()
            ->andReturn(['success' => false, 'message' => 'Coolify API call failed. Check the logs.']);

        $this->dnsPassingController($coolify);

        $this->actingAs($gallery->user)
            ->post(route('admin.galleries.verify-domain', $gallery))
            ->assertRedirect()
            ->assertSessionHas('warning');

        $gallery->refresh();
        $this->assertNull(
            $gallery->custom_domain_verified_at,
            'The endpoint must leave the domain pending so it can be retried.'
        );
    }

    public function test_verify_endpoint_short_circuits_when_already_verified(): void
    {
        $gallery = $this->studioGallery();
        $gallery->forceFill(['custom_domain_verified_at' => now()])->save();

        $coolify = $this->mockedCoolify();
        $coolify->shouldNotReceive('addDomain');

        $this->actingAs($gallery->user)
            ->post(route('admin.galleries.verify-domain', $gallery))
            ->assertRedirect()
            ->assertSessionHas('status', 'Domain is already verified.');

        $gallery->refresh();
        $this->assertNotNull($gallery->custom_domain_verified_at);
    }
}
