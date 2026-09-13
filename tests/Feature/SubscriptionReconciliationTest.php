<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AdminAuditLog;
use App\Models\Invoice;
use App\Models\Transaction;
use App\Models\User;
use App\Services\OperationalAlertService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SubscriptionReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private const API_BASE = 'https://api.2checkout.com/rest/6.0/subscriptions/';

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('services.2checkout.account_number', 'ACC-001');
        Config::set('services.2checkout.secret_word', 'test-secret-word');
    }

    private function subscribedUser(array $attrs = []): User
    {
        return User::factory()->create(array_merge([
            'plan'            => 'pro',
            'subscription_id' => 'SUB-' . uniqid(),
            'plan_expires_at' => now()->subDay(), // paid period already over
        ], $attrs));
    }

    public function test_dead_subscription_with_expired_local_period_downgrades(): void
    {
        $user = $this->subscribedUser();

        Http::fake([$this->apiUrl($user) => Http::response(['SubscriptionEnabled' => false])]);

        $this->artisan('exospace:reconcile-subscriptions')->assertExitCode(0);

        $this->assertSame('free', $user->refresh()->plan, 'Missed cancellation webhook → entitlement revoked.');
        $this->assertDatabaseHas('admin_audit_logs', [
            'action' => 'subscription.reconciled_downgrade',
        ]);
    }

    public function test_dead_subscription_inside_paid_period_is_not_downgraded(): void
    {
        $user = $this->subscribedUser(['plan_expires_at' => now()->addDays(10)]);

        Http::fake([$this->apiUrl($user) => Http::response(['SubscriptionEnabled' => false])]);

        $this->artisan('exospace:reconcile-subscriptions')->assertExitCode(0);

        $this->assertSame('pro', $user->refresh()->plan, 'Paid period must run out naturally — never truncated by reconciliation.');
    }

    public function test_api_failure_never_downgrades(): void
    {
        $user = $this->subscribedUser();

        Http::fake([$this->apiUrl($user) => Http::response([], 500)]);

        $this->artisan('exospace:reconcile-subscriptions')->assertExitCode(0);

        $this->assertSame('pro', $user->refresh()->plan, 'A failed lookup is not evidence of a dead subscription.');
    }

    public function test_unparseable_payload_never_downgrades(): void
    {
        $user = $this->subscribedUser();

        Http::fake([$this->apiUrl($user) => Http::response('not-json', 200)]);

        $this->artisan('exospace:reconcile-subscriptions')->assertExitCode(0);

        $this->assertSame('pro', $user->refresh()->plan);
    }

    public function test_live_subscription_leaves_user_untouched(): void
    {
        $user = $this->subscribedUser(['plan_expires_at' => now()->addDays(10)]);

        Http::fake([$this->apiUrl($user) => Http::response([
            'SubscriptionEnabled' => true,
            'NextChargedDate'     => now()->addDays(10)->toDateString(),
        ])]);

        $this->artisan('exospace:reconcile-subscriptions')->assertExitCode(0);

        $this->assertSame('pro', $user->refresh()->plan);
    }

    public function test_expired_subscription_date_is_treated_as_dead(): void
    {
        $user = $this->subscribedUser();

        Http::fake([$this->apiUrl($user) => Http::response([
            'SubscriptionEnabled' => true,
            'ExpirationDate'      => now()->subDays(3)->toDateString(),
        ])]);

        $this->artisan('exospace:reconcile-subscriptions')->assertExitCode(0);

        $this->assertSame('free', $user->refresh()->plan);
    }

    public function test_dry_run_reports_without_downgrading(): void
    {
        $user = $this->subscribedUser();

        Http::fake([$this->apiUrl($user) => Http::response(['SubscriptionEnabled' => false])]);

        $this->artisan('exospace:reconcile-subscriptions', ['--dry-run' => true])->assertExitCode(0);

        $this->assertSame('pro', $user->refresh()->plan, '--dry-run must never mutate state.');
        $this->assertDatabaseMissing('admin_audit_logs', ['action' => 'subscription.reconciled_downgrade']);
    }

    public function test_free_user_with_live_reference_is_alert_only(): void
    {
        User::factory()->create([
            'plan'            => 'free',
            'subscription_id' => 'SUB-FREE-REF',
        ]);

        Http::fake(['*' => Http::response([])]);

        $alertSpy = \Mockery::mock(OperationalAlertService::class);
        $alertSpy->shouldReceive('alert')->andReturnNull();
        $this->instance(OperationalAlertService::class, $alertSpy);

        $this->artisan('exospace:reconcile-subscriptions')->assertExitCode(0);

        $this->assertDatabaseMissing('admin_audit_logs', ['action' => 'subscription.reconciled_downgrade']);
        $this->assertSame(1, User::where('plan', 'free')->whereNotNull('subscription_id')->count());
    }

    public function test_unconfigured_api_is_a_clean_noop(): void
    {
        Config::set('services.2checkout.account_number', null);
        Config::set('services.2checkout.secret_word', null);
        $user = $this->subscribedUser();

        $this->artisan('exospace:reconcile-subscriptions')->assertExitCode(0);

        $this->assertSame('pro', $user->refresh()->plan);
    }

    public function test_command_is_scheduled_daily(): void
    {
        $commands = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
            ->map(fn ($event) => trim((string) $event->command));

        $this->assertTrue(
            $commands->contains(fn ($cmd) => str_contains($cmd, 'exospace:reconcile-subscriptions')),
            'exospace:reconcile-subscriptions must be scheduled (daily 04:10).',
        );
    }

    private function apiUrl(User $user): string
    {
        return self::API_BASE . $user->subscription_id;
    }

    // ── Part 2: chargeback reversal restore semantics ───────────────────

    private function postWebhook(array $payload)
    {
        $saleId = $payload['sale_id'] ?? 'SALE-' . uniqid();
        $invoiceId = $payload['invoice_id'] ?? 'INV-' . uniqid();
        $vendorId = $payload['vendor_id'] ?? 'V12345';
        $secretWord = 'test-secret-word';

        $stringToHash = strtoupper(md5($saleId))
                      . $vendorId
                      . $invoiceId
                      . $secretWord;

        $payload = array_merge([
            'md5_hash'       => strtoupper(md5($stringToHash)),
            'vendor_id'      => $vendorId,
            'sale_id'        => $saleId,
            'customer_email' => 'buyer@example.com',
        ], $payload);

        return $this->postJson('/webhooks/2checkout', $payload);
    }

    public function test_chargeback_reversal_on_subscription_restores_finite_period(): void
    {
        $user = User::factory()->create([
            'plan'            => 'free',
            'subscription_id' => 'SUB-CB-1',
        ]);
        $transaction = Transaction::factory()->create([
            'user_id'   => $user->id,
            'invoice_id'=> 'INV-CB-SUB-1',
            'plan'      => 'pro',
            'status'    => 'chargeback',
        ]);
        Invoice::create([
            'user_id'        => $user->id,
            'transaction_id' => $transaction->id,
            'invoice_number' => 'INV-2026-00001',
            'amount'         => 29.00,
            'currency'       => 'USD',
            'plan'           => 'pro',
            'customer_name'  => 'Test Buyer',
            'customer_email' => 'buyer@example.com',
            'issued_at'      => now(),
            'billing_type'   => 'subscription',
        ]);

        $this->postWebhook([
            'message_type' => 'CHARGEBACK_REVERSED',
            'invoice_id'   => 'INV-CB-SUB-1',
        ])->assertOk();

        $user->refresh();
        $this->assertSame('pro', $user->plan, 'Plan is restored.');
        $this->assertNotNull($user->plan_expires_at, 'A subscription restore must be FINITE — never an infinite grant.');
        $this->assertTrue($user->plan_expires_at->isFuture(), 'The restored period must cover the coming cycle.');
        $this->assertSame('active', $user->subscription_status, 'Subscription bookkeeping is restored.');
    }

    public function test_chargeback_reversal_on_one_time_purchase_stays_lifetime(): void
    {
        $user = User::factory()->create([
            'plan'            => 'free',
            'subscription_id' => null,
        ]);
        $transaction = Transaction::factory()->create([
            'user_id'   => $user->id,
            'invoice_id'=> 'INV-CB-OT-1',
            'plan'      => 'pro',
            'status'    => 'chargeback',
        ]);
        Invoice::create([
            'user_id'        => $user->id,
            'transaction_id' => $transaction->id,
            'invoice_number' => 'INV-2026-00002',
            'amount'         => 299.00,
            'currency'       => 'USD',
            'plan'           => 'pro',
            'customer_name'  => 'Test Buyer',
            'customer_email' => 'buyer@example.com',
            'issued_at'      => now(),
            'billing_type'   => 'one_time',
        ]);

        $this->postWebhook([
            'message_type' => 'CHARGEBACK_REVERSED',
            'invoice_id'   => 'INV-CB-OT-1',
        ])->assertOk();

        $user->refresh();
        $this->assertSame('pro', $user->plan);
        $this->assertNull($user->plan_expires_at, 'One-time purchases keep the lifetime restore.');
    }
}
