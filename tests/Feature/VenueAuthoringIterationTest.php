<?php

declare(strict_types=1);

/**
 * Iteration 5 "AUTHORING" regression tests (3D venue roadmap, P2.1).
 *
 * Pins the in-product authoring loop so venue iteration stays safe in
 * production:
 *
 *   - CLONE: draft copy with copied config + fresh assets; never
 *     auto-published; unique slug on collision; audit-logged.
 *   - SNAPSHOTS: every save captures the overwritten state; retention
 *     capped at 5; restore returns the exact prior content and snapshots
 *     the state it rolled back (reversible restore).
 *   - ARCHIVE (delete is gone): usage guard demands confirm_usage when
 *     galleries exist; archived venues leave every selection surface
 *     (picker / public pages / preview) but keep SERVING existing
 *     galleries; unarchive restores selection. No row is ever
 *     hard-deleted by the destroy route.
 *   - PUBLISH: explicit publish/unpublish workflow steps, audit-logged,
 *     unpublish instantly hides from customers (Iteration 0 contract).
 *   - AUDIT: every authoring action writes an AdminAuditLog row.
 *   - CACHE-BUST (§10.7 integration): saving a venue (or restoring a
 *     snapshot) changes the exporter cache key, so every gallery using
 *     the venue renders the new config on its next view — the "my fix
 *     isn't live" trap stays closed and snapshot rollback is visible
 *     immediately.
 *   - SEMANTIC VALIDATION (§9.3): inverted fog and ceiling-below-wall
 *     are rejected; structured saves preserve advanced visual_config
 *     keys (structure descriptors, gates).
 *   - PERMISSIONS: every authoring route is super-admin + MFA only, and
 *     the whole suite 404s behind FEATURE_FLAG_VENUE_AUTHORING=false.
 *
 * Run: php artisan test --filter=VenueAuthoringIterationTest
 */

namespace Tests\Feature;

use App\Models\AdminAuditLog;
use App\Models\Gallery;
use App\Models\GalleryImage;
use App\Models\User;
use App\Models\VenueTemplate;
use App\Models\VenueTemplateSnapshot;
use App\Services\VenueConfigExporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VenueAuthoringIterationTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): User
    {
        return User::factory()->withMfa()->create([
            'is_super_admin'     => true,
            'email_verified_at'  => now(),
        ]);
    }


    /** RequireMfa (Task H56/SEC-5) demands a verified MFA session for every
     *  super-admin action — actingAs alone is not enough. */
    private function mfaSession(): array
    {
        return ['mfa_verified' => true, 'mfa_verified_at' => now()->timestamp];
    }

    private function regularUser(): User
    {
        return User::factory()->create([
            'is_super_admin'    => false,
            'email_verified_at' => now(),
        ]);
    }

    private function venue(array $overrides = []): VenueTemplate
    {
        return VenueTemplate::factory()->create(array_merge([
            'name'            => 'Test Venue',
            'description'     => 'A venue born in tests.',
            'visual_config'   => ['wall_height' => 4, 'fog_near' => 1, 'fog_far' => 18],
            'material_config' => ['wall_roughness' => 0.9],
            'is_draft'        => false,
            'is_active'       => true,
        ], $overrides));
    }

    // ─────────────────────────────────────────────────────────────────────
    //  Clone
    // ─────────────────────────────────────────────────────────────────────

    public function test_clone_creates_draft_copy_with_copied_config_and_audit(): void
    {
        $admin = $this->superAdmin();
        $venue = $this->venue(['name' => 'Dark Museum']);

        $this->actingAs($admin)->withSession(['mfa_verified' => true, 'mfa_verified_at' => now()->timestamp])
            ->post(route('super.venues.clone', $venue))
            ->assertRedirect();

        $copy = VenueTemplate::query()->where('slug', 'dark-museum-copy')->firstOrFail();

        $this->assertNotSame($venue->id, $copy->id);
        $this->assertSame('Dark Museum (Copy)', $copy->name);
        $this->assertTrue($copy->is_draft, 'A clone must never go live next to its original.');
        $this->assertFalse($copy->is_featured);
        $this->assertSame(0, $copy->view_count);
        $this->assertNull($copy->published_at);
        $this->assertNull($copy->archived_at);

        // Config content copied 1:1.
        $this->assertSame($venue->visual_config, $copy->visual_config);
        $this->assertSame($venue->material_config, $copy->material_config);

        // The original is untouched (still published, still itself).
        $venue->refresh();
        $this->assertFalse($venue->is_draft);

        // Audit trail.
        $this->assertDatabaseHas('admin_audit_logs', [
            'action'      => 'venue_template.cloned',
            'target_type' => VenueTemplate::class,
            'target_id'   => $copy->id,
        ]);
    }

    public function test_clone_survives_slug_collision(): void
    {
        $admin = $this->superAdmin();
        $venue = $this->venue(['name' => 'Zen Garden', 'slug' => 'zen-garden']);

        $this->actingAs($admin)->withSession(['mfa_verified' => true, 'mfa_verified_at' => now()->timestamp])->post(route('super.venues.clone', $venue));
        $this->actingAs($admin)->withSession(['mfa_verified' => true, 'mfa_verified_at' => now()->timestamp])->post(route('super.venues.clone', $venue));

        $slugs = VenueTemplate::query()->where('slug', 'like', 'zen-garden-copy%')->pluck('slug')->all();
        $this->assertCount(2, $slugs, 'Both clones must exist.');
        $this->assertCount(2, array_unique($slugs), 'Clone slugs must be unique.');
    }

    // ─────────────────────────────────────────────────────────────────────
    //  Snapshots + restore
    // ─────────────────────────────────────────────────────────────────────

    public function test_update_captures_presave_snapshot_and_prunes_to_five(): void
    {
        $admin = $this->superAdmin();
        $venue = $this->venue();

        // Seven sequential saves → only the last five pre-save states kept.
        for ($i = 1; $i <= 7; $i++) {
            $this->actingAs($admin)->withSession(['mfa_verified' => true, 'mfa_verified_at' => now()->timestamp])
                ->put(route('super.venues.update', $venue), [
                    'name'        => "Test Venue v{$i}",
                    'description' => $venue->description,
                    'category'    => $venue->category,
                    'plan_required' => 'free',
                    'capacity_min' => 10,
                    'visual_config' => ['wall_height' => (string) (4 + $i)],
                ])
                ->assertRedirect();
        }

        $this->assertSame(
            5,
            VenueTemplateSnapshot::query()->where('venue_template_id', $venue->id)->count(),
            'Snapshot retention must cap at 5 per venue.'
        );

        // The OLDEST surviving snapshot must be the state before save #3
        // (saves #1 and #2 snapshots were pruned): the name at that moment
        // was "Test Venue v2".
        $oldest = VenueTemplateSnapshot::forVenue($venue->id)->get()->last();
        $this->assertSame('Test Venue v2', $oldest->config['name']);
        $this->assertSame('before save', $oldest->label);
        $this->assertNotNull($oldest->created_by);

        // Audit log captured the updates.
        $this->assertDatabaseHas('admin_audit_logs', [
            'action'      => 'venue_template.updated',
            'target_id'   => $venue->id,
        ]);
    }

    public function test_restore_returns_previous_config_and_is_itself_snapshotted(): void
    {
        $admin = $this->superAdmin();
        $venue = $this->venue(['visual_config' => ['wall_height' => 4, 'fog_color' => '0x0f0f0f']]);

        // Save #1: capture original, apply a destructive change.
        $this->actingAs($admin)->withSession(['mfa_verified' => true, 'mfa_verified_at' => now()->timestamp])
            ->put(route('super.venues.update', $venue), [
                'name' => $venue->name,
                'description' => $venue->description,
                'category' => $venue->category,
                'plan_required' => 'free',
                'capacity_min' => 10,
                'visual_config' => ['wall_height' => '9', 'fog_color' => '0xff00ff'],
            ])
            ->assertRedirect();

        $venue->refresh();
        $this->assertSame(9, (int) $venue->visual_config['wall_height']);

        // Roll back to the snapshot (the pre-save original).
        $snapshot = VenueTemplateSnapshot::forVenue($venue->id)->firstOrFail();
        $this->actingAs($admin)->withSession(['mfa_verified' => true, 'mfa_verified_at' => now()->timestamp])
            ->post(route('super.venues.snapshots.restore', [$venue, $snapshot]))
            ->assertRedirect();

        $venue->refresh();
        $this->assertSame(4, $venue->visual_config['wall_height']);
        $this->assertSame('0x0f0f0f', $venue->visual_config['fog_color']);

        // The restore first snapshotted the state it rolled back FROM
        // (posted values are strings — '9', not 9).
        $safety = VenueTemplateSnapshot::forVenue($venue->id)->firstOrFail();
        $this->assertSame('before restore', $safety->label);
        $this->assertSame('9', (string) $safety->config['visual_config']['wall_height']);

        // Restore is audit-logged with before/after.
        $this->assertDatabaseHas('admin_audit_logs', [
            'action'    => 'venue_template.snapshot_restored',
            'target_id' => $venue->id,
        ]);
    }

    public function test_restore_rejects_cross_venue_snapshot(): void
    {
        $admin = $this->superAdmin();
        $venueA = $this->venue(['name' => 'Venue A']);
        $venueB = $this->venue(['name' => 'Venue B']);

        $this->actingAs($admin)->withSession(['mfa_verified' => true, 'mfa_verified_at' => now()->timestamp])->put(route('super.venues.update', $venueA), [
            'name' => 'Venue A2', 'description' => $venueA->description, 'category' => $venueA->category,
            'plan_required' => 'free', 'capacity_min' => 10,
        ]);

        $snapshot = VenueTemplateSnapshot::forVenue($venueA->id)->firstOrFail();

        // Snapshot of A must not be restorable onto B.
        $this->actingAs($admin)->withSession(['mfa_verified' => true, 'mfa_verified_at' => now()->timestamp])
            ->post(route('super.venues.snapshots.restore', [$venueB, $snapshot]))
            ->assertNotFound();
    }

    // ─────────────────────────────────────────────────────────────────────
    //  Archive (delete is gone) + selection surfaces
    // ─────────────────────────────────────────────────────────────────────

    public function test_destroy_archives_instead_of_hard_delete_and_galleries_keep_rendering(): void
    {
        $admin = $this->superAdmin();
        $venue = $this->venue();
        $gallery = Gallery::factory()->create([
            'user_id'           => $admin->id,
            'venue_template_id' => $venue->id,
        ]);

        $this->actingAs($admin)->withSession(['mfa_verified' => true, 'mfa_verified_at' => now()->timestamp])
            ->delete(route('super.venues.destroy', $venue), ['confirm_usage' => '1'])
            ->assertRedirect();

        // The row survives — no hard delete, ever.
        $this->assertDatabaseHas('venue_templates', ['id' => $venue->id]);
        $venue->refresh();
        $this->assertTrue($venue->isArchived());

        // The gallery's relation still resolves and the exporter still
        // serves the venue config (archived ≠ broken live show).
        $gallery->refresh();
        $this->assertNotNull($gallery->venueTemplate);
        $this->assertNotNull(app(VenueConfigExporter::class)->forGallery($gallery));

        $this->assertDatabaseHas('admin_audit_logs', [
            'action'    => 'venue_template.archived',
            'target_id' => $venue->id,
        ]);
    }

    public function test_archive_requires_usage_confirmation_when_galleries_exist(): void
    {
        $admin = $this->superAdmin();
        $venue = $this->venue();
        Gallery::factory()->create(['user_id' => $admin->id, 'venue_template_id' => $venue->id]);

        // Without confirm_usage: refused, nothing changes.
        $this->actingAs($admin)->withSession(['mfa_verified' => true, 'mfa_verified_at' => now()->timestamp])
            ->delete(route('super.venues.destroy', $venue))
            ->assertRedirect();

        $venue->refresh();
        $this->assertFalse($venue->isArchived(), 'Archive must be refused without usage confirmation.');

        // With confirm_usage: archived.
        $this->actingAs($admin)->withSession(['mfa_verified' => true, 'mfa_verified_at' => now()->timestamp])
            ->delete(route('super.venues.destroy', $venue), ['confirm_usage' => '1'])
            ->assertRedirect();

        $venue->refresh();
        $this->assertTrue($venue->isArchived());
    }

    public function test_archived_venue_hidden_from_selection_but_public_venue_serving_galleries(): void
    {
        $admin = $this->superAdmin();
        $venue = $this->venue(['slug' => 'archived-test', 'is_active' => true]);

        // The public /venues catalog lists venues that carry a public
        // exhibition (publiclyViewable + has images) — give the venue one so
        // it is catalog-visible before archiving.
        Gallery::factory()->for($admin, 'user')
            ->create(['venue_template_id' => $venue->id, 'is_active' => true])
            ->images()->create(GalleryImage::factory()->make()->toArray());

        // Visible on every selection surface before archiving.
        $this->get(route('venues.index'))->assertOk()->assertSee('archived-test');
        $this->get(route('venues.preview', 'archived-test'))->assertOk();

        $this->actingAs($admin)->withSession(['mfa_verified' => true, 'mfa_verified_at' => now()->timestamp])
            ->delete(route('super.venues.destroy', $venue), ['confirm_usage' => '1'])
            ->assertRedirect();

        // Selection surfaces: gone.
        $this->get(route('venues.index'))->assertOk()->assertDontSee('archived-test');
        $this->get(route('venues.show', 'archived-test'))->assertNotFound();
        $this->get(route('venues.preview', 'archived-test'))->assertNotFound();

        // Picker scope: gone.
        $this->assertSame(
            0,
            VenueTemplate::forUser($admin)->where('id', $venue->id)->count(),
            'Archived venues must leave the picker.'
        );
    }

    public function test_unarchive_restores_selection(): void
    {
        $admin = $this->superAdmin();
        $venue = $this->venue(['slug' => 'unarchive-me']);

        $this->actingAs($admin)->withSession(['mfa_verified' => true, 'mfa_verified_at' => now()->timestamp])->delete(route('super.venues.destroy', $venue), ['confirm_usage' => '1']);
        $this->get(route('venues.preview', 'unarchive-me'))->assertNotFound();

        $this->actingAs($admin)->withSession(['mfa_verified' => true, 'mfa_verified_at' => now()->timestamp])
            ->patch(route('super.venues.unarchive', $venue))
            ->assertRedirect();

        $venue->refresh();
        $this->assertFalse($venue->isArchived());
        $this->get(route('venues.preview', 'unarchive-me'))->assertOk();

        $this->assertDatabaseHas('admin_audit_logs', [
            'action'    => 'venue_template.unarchived',
            'target_id' => $venue->id,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    //  Publish / unpublish
    // ─────────────────────────────────────────────────────────────────────

    public function test_publish_clears_draft_and_stamps_timestamp(): void
    {
        $admin = $this->superAdmin();
        $venue = $this->venue(['is_draft' => true]);
        $this->assertNull($venue->published_at);

        $this->actingAs($admin)->withSession(['mfa_verified' => true, 'mfa_verified_at' => now()->timestamp])
            ->patch(route('super.venues.publish', $venue))
            ->assertRedirect();

        $venue->refresh();
        $this->assertFalse($venue->is_draft);
        $this->assertNotNull($venue->published_at);

        $this->assertDatabaseHas('admin_audit_logs', [
            'action'    => 'venue_template.published',
            'target_id' => $venue->id,
        ]);
    }

    public function test_unpublish_hides_venue_from_picker_and_public_pages(): void
    {
        $admin = $this->superAdmin();
        $venue = $this->venue(['slug' => 'unpublish-me', 'is_draft' => false]);

        $this->get(route('venues.preview', 'unpublish-me'))->assertOk();

        $this->actingAs($admin)->withSession(['mfa_verified' => true, 'mfa_verified_at' => now()->timestamp])->patch(route('super.venues.unpublish', $venue))->assertRedirect();

        $venue->refresh();
        $this->assertTrue($venue->is_draft);

        // Iteration 0 selection-integrity contract: drafts are invisible
        // to customers everywhere, instantly.
        $this->get(route('venues.preview', 'unpublish-me'))->assertNotFound();
        $this->assertSame(0, VenueTemplate::forUser($admin)->where('id', $venue->id)->count());

        $this->assertDatabaseHas('admin_audit_logs', [
            'action'    => 'venue_template.unpublished',
            'target_id' => $venue->id,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    //  Cache-bust integration (§10.7 — the fix that makes it all visible)
    // ─────────────────────────────────────────────────────────────────────

    public function test_saving_venue_immediately_changes_gallery_config(): void
    {
        $admin = $this->superAdmin();
        $venue = $this->venue();
        $gallery = Gallery::factory()->create([
            'user_id'           => $admin->id,
            'venue_template_id' => $venue->id,
        ]);

        $exporter = app(VenueConfigExporter::class);

        // Prime the cache.
        $before = $exporter->forGallery($gallery);
        $this->assertSame(4, $before['visual_config']['wall_height']);

        // Save a new wall height through the authoring flow.
        $this->actingAs($admin)->withSession(['mfa_verified' => true, 'mfa_verified_at' => now()->timestamp])
            ->put(route('super.venues.update', $venue), [
                'name' => $venue->name, 'description' => $venue->description,
                'category' => $venue->category, 'plan_required' => 'free', 'capacity_min' => 10,
                'visual_config' => ['wall_height' => '6'],
            ])
            ->assertRedirect();

        // The cache key includes the venue's updated_at → next render is fresh.
        $after = $exporter->forGallery($gallery);
        $this->assertSame(6, (int) $after['visual_config']['wall_height'], 'A venue save must be visible to galleries immediately (§10.7).');

        // Snapshot restore busts the same way (a restore IS a save).
        $snapshot = VenueTemplateSnapshot::forVenue($venue->id)->firstOrFail();
        $this->actingAs($admin)->withSession(['mfa_verified' => true, 'mfa_verified_at' => now()->timestamp])->post(route('super.venues.snapshots.restore', [$venue, $snapshot]));

        $rolled = $exporter->forGallery($gallery);
        $this->assertSame(4, $rolled['visual_config']['wall_height'], 'Snapshot rollback must be visible immediately.');
    }

    // ─────────────────────────────────────────────────────────────────────
    //  Structured form + semantic validation (§9.3)
    // ─────────────────────────────────────────────────────────────────────

    public function test_structured_form_preserves_unknown_visual_keys(): void
    {
        $admin = $this->superAdmin();
        // IT3 structure descriptors + gates live in visual_config — the
        // structured form must round-trip them untouched.
        $venue = $this->venue([
            'visual_config' => [
                'wall_height'    => 4,
                'structure'      => [['id' => 'bench', 'primitive' => 'box', 'at' => [1, 0.4, 1.5], 'size' => [1.5, 0.09, 0.42], 'material' => 'wood_warm']],
                'structure_pass' => 'rooms',
            ],
        ]);

        $this->actingAs($admin)->withSession(['mfa_verified' => true, 'mfa_verified_at' => now()->timestamp])
            ->put(route('super.venues.update', $venue), [
                'name' => $venue->name, 'description' => $venue->description,
                'category' => $venue->category, 'plan_required' => 'free', 'capacity_min' => 10,
                'visual_config' => ['wall_height' => '5'],  // structured input changes one key
                'visual_config_advanced' => json_encode([
                    'structure'      => [['id' => 'bench', 'primitive' => 'box', 'at' => [1, 0.4, 1.5], 'size' => [1.5, 0.09, 0.42], 'material' => 'wood_warm']],
                    'structure_pass' => 'rooms',
                ]),
            ])
            ->assertRedirect();

        $venue->refresh();
        $this->assertSame(5, (int) $venue->visual_config['wall_height']);
        $this->assertSame('rooms', $venue->visual_config['structure_pass']);
        $this->assertSame('bench', $venue->visual_config['structure'][0]['id']);
    }

    public function test_semantic_validation_rejects_inverted_fog_and_low_ceiling(): void
    {
        $admin = $this->superAdmin();
        $venue = $this->venue();

        // Fog folding inside itself.
        $this->actingAs($admin)->withSession(['mfa_verified' => true, 'mfa_verified_at' => now()->timestamp])
            ->put(route('super.venues.update', $venue), [
                'name' => $venue->name, 'description' => $venue->description,
                'category' => $venue->category, 'plan_required' => 'free', 'capacity_min' => 10,
                'visual_config' => ['fog_near' => '18', 'fog_far' => '1'],
            ])
            ->assertSessionHasErrors('visual_config.fog_far');

        // Ceiling below the walls.
        $this->actingAs($admin)->withSession(['mfa_verified' => true, 'mfa_verified_at' => now()->timestamp])
            ->put(route('super.venues.update', $venue), [
                'name' => $venue->name, 'description' => $venue->description,
                'category' => $venue->category, 'plan_required' => 'free', 'capacity_min' => 10,
                'visual_config' => ['wall_height' => '5', 'ceiling_height' => '3'],
            ])
            ->assertSessionHasErrors('visual_config.ceiling_height');

        // Nothing was persisted by the rejected saves.
        $venue->refresh();
        $this->assertSame(4, $venue->visual_config['wall_height']);
    }

    // ─────────────────────────────────────────────────────────────────────
    //  Permissions + feature flag
    // ─────────────────────────────────────────────────────────────────────

    public function test_authoring_actions_require_super_admin(): void
    {
        $user = $this->regularUser();
        $venue = $this->venue();

        $this->actingAs($user)->post(route('super.venues.clone', $venue))->assertForbidden();
        $this->actingAs($user)->patch(route('super.venues.publish', $venue))->assertForbidden();
        $this->actingAs($user)->patch(route('super.venues.unpublish', $venue))->assertForbidden();
        $this->actingAs($user)->patch(route('super.venues.unarchive', $venue))->assertForbidden();
        $this->actingAs($user)->delete(route('super.venues.destroy', $venue))->assertForbidden();

        $venue->refresh();
        $this->assertFalse($venue->isArchived());
        $this->assertFalse($venue->is_draft);
    }

    public function test_flag_off_disables_authoring_routes(): void
    {
        config()->set('feature_flags.flags.venue_authoring', false);

        $admin = $this->superAdmin();
        $venue = $this->venue();

        // All authoring routes vanish (404, indistinguishable from absent).
        $this->actingAs($admin)->withSession(['mfa_verified' => true, 'mfa_verified_at' => now()->timestamp])->post(route('super.venues.clone', $venue))->assertNotFound();
        $this->actingAs($admin)->withSession(['mfa_verified' => true, 'mfa_verified_at' => now()->timestamp])->patch(route('super.venues.publish', $venue))->assertNotFound();
        $this->actingAs($admin)->withSession(['mfa_verified' => true, 'mfa_verified_at' => now()->timestamp])->patch(route('super.venues.unpublish', $venue))->assertNotFound();
        $this->actingAs($admin)->withSession(['mfa_verified' => true, 'mfa_verified_at' => now()->timestamp])->patch(route('super.venues.unarchive', $venue))->assertNotFound();

        // Core CRUD keeps working with the flag off (a plain update must
        // still succeed — and simply not capture snapshots).
        $this->actingAs($admin)->withSession(['mfa_verified' => true, 'mfa_verified_at' => now()->timestamp])
            ->put(route('super.venues.update', $venue), [
                'name' => $venue->name, 'description' => $venue->description,
                'category' => $venue->category, 'plan_required' => 'free', 'capacity_min' => 10,
            ])
            ->assertRedirect();

        $this->assertSame(
            0,
            VenueTemplateSnapshot::query()->where('venue_template_id', $venue->id)->count(),
            'Flag off must pause snapshot capture.'
        );
    }
}
