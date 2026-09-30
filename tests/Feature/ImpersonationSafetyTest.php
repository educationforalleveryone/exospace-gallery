<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AdminAuditLog;
use App\Models\User;
use App\Services\ImpersonationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImpersonationSafetyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['feature_flags.flags.admin_impersonation' => true]);
    }

    private function superAdmin(): User
    {
        return User::factory()->superAdmin()->create([
            'email_verified_at' => now(),
            'google2fa_secret' => encrypt('ABCDEFGHIJKLMNOP'),
            'mfa_enabled_at' => now(),
        ]);
    }

    private function impersonate(User $admin, User $target): void
    {
        $this->actingAs($admin)
            ->withSession([
                'mfa_verified' => true,
                'mfa_verified_at' => now()->timestamp,
                'mfa_verified_user_id' => $admin->id,
                'auth.password_confirmed_at' => now()->timestamp,
            ])
            ->post(route('super.impersonate', $target))
            ->assertRedirect();

        $this->assertAuthenticatedAs($target, 'web');
    }

    private function target(): User
    {
        return User::factory()->create(['email_verified_at' => now()]);
    }

    public function test_start_is_audited_against_the_admin_not_the_impersonated_account(): void
    {
        $admin = $this->superAdmin();
        $target = $this->target();

        $this->impersonate($admin, $target);

        $this->assertDatabaseHas('admin_audit_logs', [
            'action' => 'impersonation_started',
            'actor_id' => $admin->id,
            'target_id' => $target->id,
        ]);
    }

    public function test_service_refuses_a_caller_who_is_not_a_super_admin(): void
    {
        $regular = $this->target();
        $target = $this->target();

        $this->assertFalse(app(ImpersonationService::class)->start($regular, $target));
        $this->assertFalse(app(ImpersonationService::class)->isImpersonating());
        $this->assertDatabaseMissing('admin_audit_logs', ['action' => 'impersonation_started']);
    }

    public function test_regular_user_cannot_reach_the_impersonate_endpoint(): void
    {
        $regular = $this->target();

        $this->actingAs($regular)
            ->withSession(['auth.password_confirmed_at' => now()->timestamp])
            ->post(route('super.impersonate', $this->target()))
            ->assertForbidden();

        $this->assertAuthenticatedAs($regular, 'web');
    }

    public function test_super_admin_targets_and_chained_impersonation_are_refused(): void
    {
        $admin = $this->superAdmin();
        $otherAdmin = $this->superAdmin();

        $this->actingAs($admin)
            ->withSession([
                'mfa_verified' => true,
                'mfa_verified_at' => now()->timestamp,
                'mfa_verified_user_id' => $admin->id,
                'auth.password_confirmed_at' => now()->timestamp,
            ])
            ->post(route('super.impersonate', $otherAdmin))
            ->assertRedirect(route('super.index'))
            ->assertSessionHas('error');

        $this->assertAuthenticatedAs($admin, 'web');

        $first = $this->target();
        $this->impersonate($admin, $first);

        $this->assertFalse(app(ImpersonationService::class)->start($admin, $this->target()));
    }

    public function test_disabled_feature_flag_hides_impersonation(): void
    {
        config(['feature_flags.flags.admin_impersonation' => false]);
        $admin = $this->superAdmin();

        $this->actingAs($admin)
            ->withSession([
                'mfa_verified' => true,
                'mfa_verified_at' => now()->timestamp,
                'mfa_verified_user_id' => $admin->id,
                'auth.password_confirmed_at' => now()->timestamp,
            ])
            ->post(route('super.impersonate', $this->target()))
            ->assertNotFound();
    }

    public function test_billing_is_unreachable_while_impersonating(): void
    {
        $this->impersonate($this->superAdmin(), $this->target());

        $this->get('/billing')->assertForbidden();
        $this->post('/billing/cancel-subscription')->assertForbidden();
        $this->post('/billing/downgrade')->assertForbidden();
        $this->post('/billing/start-trial/pro')->assertForbidden();
        $this->post('/billing/upgrade/pro')->assertForbidden();
    }

    public function test_credentials_and_sign_in_methods_cannot_be_changed_while_impersonating(): void
    {
        $target = $this->target();
        $originalEmail = $target->email;
        $originalHash = $target->password;

        $this->impersonate($this->superAdmin(), $target);

        $this->patch('/profile', ['name' => 'Changed', 'email' => 'hijack@example.com'])->assertForbidden();
        $this->delete('/profile', ['password' => 'password'])->assertForbidden();
        $this->put('/password', [
            'current_password' => 'password',
            'password' => 'HijackPass123!',
            'password_confirmation' => 'HijackPass123!',
        ])->assertForbidden();
        $this->post('/mfa/setup', ['code' => '123456'])->assertForbidden();
        $this->post('/mfa/disable', ['password' => 'password'])->assertForbidden();
        $this->post('/auth/github/unlink')->assertForbidden();
        $this->get('/auth/github/redirect?action=link')->assertForbidden();

        $target->refresh();
        $this->assertSame($originalEmail, $target->email);
        $this->assertSame($originalHash, $target->password);
    }

    public function test_read_only_pages_and_stopping_still_work_while_impersonating(): void
    {
        $admin = $this->superAdmin();
        $this->impersonate($admin, $this->target());

        $this->get('/profile')->assertOk();

        $this->post(route('super.stop-impersonating'))->assertRedirect(route('super.index'));
        $this->assertAuthenticatedAs($admin, 'web');
    }

    public function test_mutating_requests_are_audited_to_the_admin_and_reads_are_not(): void
    {
        $admin = $this->superAdmin();
        $target = $this->target();
        $this->impersonate($admin, $target);

        $this->get('/profile')->assertOk();
        $this->assertDatabaseMissing('admin_audit_logs', ['action' => 'impersonation_request']);

        $this->post('/admin/teams', ['name' => 'Acting As Target']);

        $row = AdminAuditLog::where('action', 'impersonation_request')->first();
        $this->assertNotNull($row);
        $this->assertSame($admin->id, $row->actor_id);
        $this->assertSame($target->id, (int) $row->target_id);
        $this->assertSame($admin->id, $row->payload['admin_id']);
        $this->assertSame('POST', $row->payload['method']);
    }

    public function test_restrictions_do_not_apply_outside_impersonation(): void
    {
        $user = $this->target();

        $this->actingAs($user)->get('/billing')->assertOk();
        $this->assertDatabaseMissing('admin_audit_logs', ['action' => 'impersonation_request']);
    }

    public function test_billing_and_credentials_return_after_the_admin_stops(): void
    {
        $admin = $this->superAdmin();
        $this->impersonate($admin, $this->target());
        $this->post(route('super.stop-impersonating'))->assertRedirect();

        $this->assertFalse(app(ImpersonationService::class)->isImpersonating());
        $this->withSession([
            'mfa_verified' => true,
            'mfa_verified_at' => now()->timestamp,
            'mfa_verified_user_id' => $admin->id,
        ])->get('/billing')->assertOk();
    }
}
