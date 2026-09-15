<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\EventRsvp;
use App\Models\Gallery;
use App\Models\GalleryScheduleEvent;
use App\Models\NewsletterSignup;
use App\Models\PendingUpgrade;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ConcurrentWriteSafetyTest extends TestCase
{
    use RefreshDatabase;

    // ── Newsletter signup race ──────────────────────────────────────────

    public function test_duplicate_newsletter_signup_is_tolerated_not_erroring(): void
    {
        $gallery = Gallery::factory()->create(['is_active' => true]);

        NewsletterSignup::create([
            'gallery_id' => $gallery->id,
            'email' => 'collector@example.com',
            'name' => 'Earlier Winner',
            'signed_up_at' => now(),
        ]);

        $response = $this->postJson(route('gallery.newsletter', $gallery->slug), [
            'email' => 'collector@example.com',
            'name' => 'Racing Loser',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('is_new', false);

        $this->assertSame(1, NewsletterSignup::where('gallery_id', $gallery->id)
            ->where('email', 'collector@example.com')->count());
    }

    public function test_unique_index_backstops_concurrent_newsletter_signups(): void
    {
        $gallery = Gallery::factory()->create(['is_active' => true]);

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);

        NewsletterSignup::create([
            'gallery_id' => $gallery->id,
            'email' => 'race@example.com',
            'signed_up_at' => now(),
        ]);

        NewsletterSignup::create([
            'gallery_id' => $gallery->id,
            'email' => 'race@example.com',
            'signed_up_at' => now(),
        ]);
    }

    // ── RSVP race and capacity ──────────────────────────────────────────

    public function test_rsvp_capacity_rejects_when_seats_are_taken(): void
    {
        $gallery = Gallery::factory()->create(['is_active' => true]);
        $event = GalleryScheduleEvent::create([
            'gallery_id' => $gallery->id,
            'title' => 'Limited opening',
            'type' => 'opening',
            'starts_at' => now()->addDays(2),
            'capacity' => 1,
            'is_active' => true,
        ]);

        EventRsvp::create([
            'schedule_event_id' => $event->id,
            'name' => 'First Guest',
            'email' => 'first@example.com',
            'confirmed_at' => now(),
        ]);

        $response = $this->from('/elsewhere')->post(route('gallery.events.rsvp', [$gallery->slug, $event->id]), [
            'name' => 'Second Guest',
            'email' => 'second@example.com',
        ]);

        $response->assertRedirect('/elsewhere')
            ->assertSessionHas('error', 'This event has reached capacity.');
        $this->assertSame(1, EventRsvp::where('schedule_event_id', $event->id)->count());
    }

    public function test_unlimited_events_still_accept_rsvps_after_the_capacity_refactor(): void
    {
        $gallery = Gallery::factory()->create(['is_active' => true]);
        $event = GalleryScheduleEvent::create([
            'gallery_id' => $gallery->id,
            'title' => 'Open door night',
            'type' => 'event',
            'starts_at' => now()->addDays(2),
            'capacity' => null,
            'is_active' => true,
        ]);

        $response = $this->post(route('gallery.events.rsvp', [$gallery->slug, $event->id]), [
            'name' => 'Walk In',
            'email' => 'walkin@example.com',
        ]);

        $response->assertRedirect()
            ->assertSessionHas('status');
        $this->assertSame(1, EventRsvp::where('schedule_event_id', $event->id)->count());
    }

    // ── Manual upgrade atomicity ────────────────────────────────────────

    public function test_manual_upgrade_writes_plan_upgrade_and_ledger_row_together(): void
    {
        $admin = User::factory()->withMfa()->superAdmin()->create(['email_verified_at' => now()]);
        $user = User::factory()->create();
        $pending = PendingUpgrade::create([
            'user_id' => $user->id,
            'token' => hash('sha256', 'manual-upgrade-token'),
            'plan' => 'pro',
            'product_id' => '53996696',
            'status' => 'pending',
        ]);

        $this->actingAs($admin)
            ->withSession([
                'mfa_verified' => true,
                'mfa_verified_at' => now()->timestamp,
                'auth.password_confirmed_at' => now()->timestamp,
            ])
            ->post(route('super.pending-upgrades.manual-upgrade', $pending))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame('pro', $user->fresh()->plan);
        $this->assertSame('converted', $pending->fresh()->status);

        $ledgerRow = DB::table('transactions')
            ->where('invoice_id', 'like', 'MANUAL-'.$pending->id.'-%')
            ->first();
        $this->assertNotNull($ledgerRow, 'Manual upgrade must record a transaction row.');
        $this->assertSame('manual', $ledgerRow->status);
    }

    public function test_manual_upgrade_leaves_no_partial_state_when_ledger_write_fails(): void
    {
        $admin = User::factory()->withMfa()->superAdmin()->create(['email_verified_at' => now()]);
        $user = User::factory()->create();
        $pending = PendingUpgrade::create([
            'user_id' => $user->id,
            'token' => hash('sha256', 'manual-upgrade-fail-token'),
            'plan' => 'studio',
            'product_id' => '53996701',
            'status' => 'pending',
        ]);

        // The transactions table enforces a unique invoice id; occupying the
        // id the handler will generate forces the third write to fail.
        $invoiceId = 'MANUAL-'.$pending->id.'-'.now()->getTimestamp();
        DB::table('transactions')->insert([
            'user_id' => $admin->id,
            'invoice_id' => $invoiceId,
            'plan' => 'free',
            'amount' => 0,
            'currency' => 'USD',
            'customer_email' => $admin->email,
            'status' => 'manual',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($admin)
            ->withSession([
                'mfa_verified' => true,
                'mfa_verified_at' => now()->timestamp,
                'auth.password_confirmed_at' => now()->timestamp,
            ])
            ->post(route('super.pending-upgrades.manual-upgrade', $pending));

        $this->assertNotSame('studio', $user->fresh()->plan, 'Plan must roll back when the ledger write fails.');
        $this->assertSame('pending', $pending->fresh()->status, 'Upgrade marker must roll back when the ledger write fails.');
    }

    // ── Team membership ordering ────────────────────────────────────────

    public function test_leaving_a_team_clears_the_active_team_pointer_before_detaching(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $team = Team::create(['owner_id' => $owner->id, 'name' => 'Exit Row']);

        $team->members()->attach([$owner->id => ['role' => 'owner'], $member->id => ['role' => 'editor']]);
        $member->forceFill(['current_team_id' => $team->id])->save();

        $this->actingAs($member)
            ->delete(route('admin.teams.leave', $team))
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertNull($member->fresh()->current_team_id);
        $this->assertFalse($team->members()->where('team_user.user_id', $member->id)->exists());
    }

    public function test_removing_a_member_clears_their_active_team_pointer_before_detaching(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $team = Team::create(['owner_id' => $owner->id, 'name' => 'Rotation Roster']);

        $team->members()->attach([$owner->id => ['role' => 'owner'], $member->id => ['role' => 'viewer']]);
        $member->forceFill(['current_team_id' => $team->id])->save();

        $this->actingAs($owner)
            ->delete(route('admin.teams.remove-member', $team), ['user_id' => $member->id])
            ->assertRedirect();

        $this->assertNull($member->fresh()->current_team_id);
        $this->assertFalse($team->members()->where('team_user.user_id', $member->id)->exists());
    }

    // ── Data integrity audit command ────────────────────────────────────

    public function test_integrity_audit_passes_on_consistent_data(): void
    {
        $owner = User::factory()->create();
        $team = Team::create(['owner_id' => $owner->id, 'name' => 'Clean State']);
        $team->members()->attach($owner->id, ['role' => 'owner']);

        $this->artisan('exospace:verify-data-integrity')->assertExitCode(0);
    }

    public function test_integrity_audit_flags_a_stale_active_team_pointer(): void
    {
        $owner = User::factory()->create();
        $team = Team::create(['owner_id' => $owner->id, 'name' => 'Stale Pointer']);
        $team->members()->attach($owner->id, ['role' => 'owner']);

        $outsider = User::factory()->create();
        $outsider->forceFill(['current_team_id' => $team->id])->save();

        $this->artisan('exospace:verify-data-integrity')
            ->expectsOutputToContain('users.active_team_without_membership')
            ->assertExitCode(1);
    }

    public function test_integrity_audit_flags_a_team_without_an_owner_membership(): void
    {
        $owner = User::factory()->create();
        Team::create(['owner_id' => $owner->id, 'name' => 'Ownerless']);

        $this->artisan('exospace:verify-data-integrity')
            ->expectsOutputToContain('teams.owner_membership_missing')
            ->assertExitCode(1);
    }
}
