<?php

namespace Tests\Feature;

use App\Models\Gallery;
use App\Models\User;
use App\Services\PlanDowngradeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * P0-1 regression test: PlanDowngradeService orWhere scope leak.
 *
 * Before the fix, the downgrade query was:
 *
 *     $user->galleries()
 *         ->whereNotNull('custom_domain')
 *         ->orWhereNotNull('custom_logo_path')
 *         ->orWhereNotNull('curtain_logo_path')
 *         ->orWhereNotNull('audio_path')
 *         ->chunkById(50, ...);
 *
 * SQL operator precedence (AND binds tighter than OR) compiled this to:
 *
 *     WHERE (user_id = ? AND custom_domain IS NOT NULL)
 *        OR (custom_logo_path IS NOT NULL)        -- UN-SCOPED to user!
 *        OR (curtain_logo_path IS NOT NULL)       -- UN-SCOPED to user!
 *        OR (audio_path IS NOT NULL)              -- UN-SCOPED to user!
 *
 * Downgrading ANY single user wiped custom_logo_path, curtain_logo_path,
 * and audio_path (and deleted the corresponding files on disk) for EVERY
 * gallery in the entire database that had any of those fields populated.
 *
 * This test creates two Studio users — each with galleries that have the
 * Studio-only fields populated — downgrades User A, and asserts that
 * User B's galleries are completely untouched.
 */
class PlanDowngradeIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Fake the public disk so file deletes are no-ops on the real FS.
        // We pre-create the files via Storage::disk('public')->put() so
        // the delete path is exercised, but nothing touches the host.
        Storage::fake('public');
    }

    /**
     * The headline regression test: downgrading User A must not touch
     * User B's Studio-only resources.
     */
    public function test_downgrading_one_user_does_not_clear_another_users_studio_fields(): void
    {
        // ── Setup: two Studio users, each with a gallery that has all four
        //    Studio-only fields populated. ───────────────────────────────
        [$userA, $galleryA] = $this->createStudioUserWithGallery('user-a');
        [$userB, $galleryB] = $this->createStudioUserWithGallery('user-b');

        // Snapshot the original values for User B so we can assert no drift.
        $originalB = [
            'custom_domain'        => $galleryB->getOriginal('custom_domain'),
            'custom_logo_path'     => $galleryB->getOriginal('custom_logo_path'),
            'curtain_logo_path'    => $galleryB->getOriginal('curtain_logo_path'),
            'audio_path'           => $galleryB->getOriginal('audio_path'),
        ];

        $this->assertNotNull($originalB['custom_domain']);
        $this->assertNotNull($originalB['custom_logo_path']);
        $this->assertNotNull($originalB['curtain_logo_path']);
        $this->assertNotNull($originalB['audio_path']);

        // ── Act: downgrade User A only. ─────────────────────────────────
        // We call the service directly to bypass the Coolify HTTP call
        // that the middleware path would trigger for User A's custom_domain.
        // User A's domain cleanup calls CoolifyDomainManager::removeDomain
        // which would fail in a test env without HTTP. Mocking the service
        // container binding here lets the call succeed silently.
        $this->mockCoolifyDomainManager();

        app(PlanDowngradeService::class)->downgradeToFree($userA, 'Test: P0-1 regression');

        // ── Assert: User A was downgraded. ──────────────────────────────
        $userA->refresh();
        $this->assertEquals('free', $userA->plan);

        // ── Assert: User A's gallery had Studio fields cleared. ─────────
        $galleryA->refresh();
        $this->assertNull($galleryA->getOriginal('custom_domain'));
        $this->assertNull($galleryA->getOriginal('custom_logo_path'));
        $this->assertNull($galleryA->getOriginal('curtain_logo_path'));
        $this->assertNull($galleryA->getOriginal('audio_path'));

        // ── Assert (the bug): User B's gallery is COMPLETELY UNTOUCHED. ─
        $galleryB->refresh();
        $this->assertEquals(
            $originalB['custom_domain'],
            $galleryB->getOriginal('custom_domain'),
            'User B\'s custom_domain was wiped by downgrading User A — the orWhere scope leak is back.'
        );
        $this->assertEquals(
            $originalB['custom_logo_path'],
            $galleryB->getOriginal('custom_logo_path'),
            'User B\'s custom_logo_path was wiped by downgrading User A — the orWhere scope leak is back.'
        );
        $this->assertEquals(
            $originalB['curtain_logo_path'],
            $galleryB->getOriginal('curtain_logo_path'),
            'User B\'s curtain_logo_path was wiped by downgrading User A — the orWhere scope leak is back.'
        );
        $this->assertEquals(
            $originalB['audio_path'],
            $galleryB->getOriginal('audio_path'),
            'User B\'s audio_path was wiped by downgrading User A — the orWhere scope leak is back.'
        );

        // ── Assert: User B's files are still on disk. ───────────────────
        $disk = Storage::disk('public');
        $this->assertTrue(
            $disk->exists(\Illuminate\Support\Str::after($originalB['custom_logo_path'], 'storage/')),
            'User B\'s custom_logo file was deleted from disk by downgrading User A.'
        );
        $this->assertTrue(
            $disk->exists(\Illuminate\Support\Str::after($originalB['curtain_logo_path'], 'storage/')),
            'User B\'s curtain_logo file was deleted from disk by downgrading User A.'
        );
        $this->assertTrue(
            $disk->exists(\Illuminate\Support\Str::after($originalB['audio_path'], 'storage/')),
            'User B\'s audio file was deleted from disk by downgrading User A.'
        );

        // ── Assert: User B's plan is still Studio. ──────────────────────
        $userB->refresh();
        $this->assertEquals('studio', $userB->plan, 'User B was downgraded by downgrading User A.');
    }

    /**
     * A user with NO Studio fields populated should be downgraded safely
     * without errors (the closure should produce zero matching rows).
     */
    public function test_downgrading_user_with_no_studio_fields_is_a_safe_noop(): void
    {
        $user = User::factory()->studio()->create();
        // Gallery with none of the four Studio-only fields set.
        Gallery::factory()->create([
            'user_id'            => $user->id,
            'custom_domain'      => null,
            'custom_logo_path'   => null,
            'curtain_logo_path'  => null,
            'audio_path'         => null,
        ]);

        app(PlanDowngradeService::class)->downgradeToFree($user, 'Test: no Studio fields');

        $user->refresh();
        $this->assertEquals('free', $user->plan);
    }

    /**
     * A user with MORE than 50 galleries (the chunk size) must have all of
     * them cleaned up — the chunkById + closure pattern must paginate
     * correctly across multiple chunks.
     */
    public function test_downgrading_user_with_more_than_50_galleries_clears_all_of_them(): void
    {
        $this->mockCoolifyDomainManager();

        $user = User::factory()->studio()->create();

        // Create 60 galleries, each with custom_logo_path set.
        // (Avoid custom_domain to skip the Coolify mock path; we already
        // mock it but keeping the test focused on the chunking behavior.)
        for ($i = 0; $i < 60; $i++) {
            $logoPath = 'logos/logo-' . $i . '-' . uniqid() . '.png';
            Storage::disk('public')->put($logoPath, 'fake-image-bytes');
            Gallery::factory()->create([
                'user_id'           => $user->id,
                'custom_domain'     => null,
                'custom_logo_path'  => 'storage/' . $logoPath,
            ]);
        }

        app(PlanDowngradeService::class)->downgradeToFree($user, 'Test: chunked downgrade');

        $user->refresh();
        $this->assertEquals('free', $user->plan);

        // ALL 60 galleries should have custom_logo_path cleared.
        $remaining = Gallery::where('user_id', $user->id)
            ->whereNotNull('custom_logo_path')
            ->count();
        $this->assertEquals(0, $remaining, 'chunkById + closure failed: some galleries were skipped.');
    }

    /**
     * Team galleries owned by the downgraded user should also be cleaned up.
     * The user_id on the gallery points at the original creator (the team
     * owner), so $user->galleries() includes them.
     */
    public function test_downgrading_team_owner_clears_team_gallery_studio_fields(): void
    {
        $this->mockCoolifyDomainManager();

        $owner = User::factory()->studio()->create();
        $team = \App\Models\Team::factory()->create(['owner_id' => $owner->id]);

        $logoPath = 'logos/team-logo-' . uniqid() . '.png';
        Storage::disk('public')->put($logoPath, 'fake-image-bytes');

        $teamGallery = Gallery::factory()->create([
            'user_id'          => $owner->id,
            'team_id'          => $team->id,
            'custom_domain'    => null,
            'custom_logo_path' => 'storage/' . $logoPath,
        ]);

        app(PlanDowngradeService::class)->downgradeToFree($owner, 'Test: team gallery cleanup');

        $teamGallery->refresh();
        $this->assertNull($teamGallery->getOriginal('custom_logo_path'));
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    /**
     * Create a Studio-tier user with one gallery that has ALL four
     * Studio-only fields populated. Returns [$user, $gallery].
     */
    private function createStudioUserWithGallery(string $label): array
    {
        $user = User::factory()->studio()->create();

        $logoPath     = "logos/{$label}-logo-" . uniqid() . '.png';
        $curtainPath  = "logos/{$label}-curtain-" . uniqid() . '.png';
        $audioPath    = "audio/{$label}-audio-" . uniqid() . '.mp3';

        Storage::disk('public')->put($logoPath, 'fake-logo-bytes');
        Storage::disk('public')->put($curtainPath, 'fake-curtain-bytes');
        Storage::disk('public')->put($audioPath, 'fake-audio-bytes');

        // custom_domain is set + verified so the full cleanup path runs.
        // The CoolifyDomainManager::removeDomain call is mocked.
        $gallery = Gallery::factory()->withCustomDomain("{$label}.example.com")->create([
            'user_id'           => $user->id,
            'custom_logo_path'  => 'storage/' . $logoPath,
            'curtain_logo_path' => 'storage/' . $curtainPath,
            'audio_path'        => 'storage/' . $audioPath,
        ]);

        return [$user, $gallery];
    }

    /**
     * Bind a mock CoolifyDomainManager in the container so
     * PlanDowngradeService can call removeDomain() without making real
     * HTTP requests during tests.
     */
    private function mockCoolifyDomainManager(): void
    {
        $mock = \Mockery::mock(\App\Services\CoolifyDomainManager::class);
        $mock->shouldReceive('removeDomain')
             ->andReturn(['success' => true, 'message' => 'mocked']);
        $mock->shouldReceive('addDomain')
             ->andReturn(['success' => true, 'message' => 'mocked']);

        $this->app->instance(\App\Services\CoolifyDomainManager::class, $mock);
    }

    protected function tearDown(): void
    {
        \Mockery::close();
        parent::tearDown();
    }
}
