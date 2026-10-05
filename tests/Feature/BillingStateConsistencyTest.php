<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BillingStateConsistencyTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET_WORD = 'consistency-secret';

    private const PRODUCT_ID_PRO = 'CONS-PRO-1';

    private const PRODUCT_ID_STUDIO = 'CONS-STUDIO-1';

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('services.2checkout.secret_word', self::SECRET_WORD);
        Config::set('services.2checkout.account_number', 'CONS-ACC');
        Config::set('services.2checkout.product_id_pro', self::PRODUCT_ID_PRO);
        Config::set('services.2checkout.product_id_studio', self::PRODUCT_ID_STUDIO);
        Config::set('services.2checkout.buy_link_secret_word', null);
        Http::fake([
            'api.2checkout.com/*' => Http::response(['success' => true], 200),
        ]);
    }

    private function md5For(string $saleId, string $invoiceId, string $vendorId = 'V-CONS'): string
    {
        return strtoupper(md5(
            $saleId.$vendorId.$invoiceId.self::SECRET_WORD
        ));
    }

    private function postWebhook(array $payload)
    {
        return $this->postJson('/webhooks/2checkout', $payload);
    }

    // ── Forced expiry marks a stale subscription honestly ────────────────

    public function test_forced_expiry_marks_active_subscription_as_expired(): void
    {
        $user = User::factory()->pro()->create([
            'plan_expires_at' => now()->subDay(),
            'subscription_id' => 'SUB-EXP-1',
            'subscription_status' => 'active',
            'subscription_ends_at' => now()->addDays(3),
        ]);

        $this->actingAs($user)->get('/admin/dashboard');

        $user->refresh();
        $this->assertEquals('free', $user->plan);
        $this->assertEquals('expired', $user->subscription_status);
    }

    public function test_forced_expiry_marks_past_due_subscription_as_expired(): void
    {
        $user = User::factory()->pro()->create([
            'plan_expires_at' => now()->subDays((int) config('plans.dunning_grace_days') + 1),
            'subscription_id' => 'SUB-EXP-2',
            'subscription_status' => 'past_due',
        ]);

        $this->actingAs($user)->get('/admin/dashboard');

        $user->refresh();
        $this->assertEquals('free', $user->plan);
        $this->assertEquals('expired', $user->subscription_status);
    }

    public function test_past_due_subscription_keeps_access_during_dunning_grace(): void
    {
        $user = User::factory()->pro()->create([
            'plan_expires_at' => now()->subDay(),
            'subscription_id' => 'SUB-GRACE-1',
            'subscription_status' => 'past_due',
            'dunning_step' => 1,
        ]);

        $this->actingAs($user)->get('/admin/dashboard');

        $user->refresh();
        $this->assertEquals('pro', $user->plan, '2Checkout is still retrying; access must survive until the grace window closes.');
        $this->assertEquals('past_due', $user->subscription_status);
    }

    public function test_active_subscription_gets_no_grace_after_expiry(): void
    {
        $user = User::factory()->pro()->create([
            'plan_expires_at' => now()->subDay(),
            'subscription_id' => 'SUB-GRACE-2',
            'subscription_status' => 'active',
        ]);

        $this->actingAs($user)->get('/admin/dashboard');

        $this->assertEquals('free', $user->refresh()->plan);
    }

    public function test_cancelled_subscription_is_left_untouched_by_forced_expiry(): void
    {
        $user = User::factory()->pro()->create([
            'plan_expires_at' => now()->subDay(),
            'subscription_id' => 'SUB-EXP-3',
            'subscription_status' => 'cancelled',
            'subscription_ends_at' => now()->subDay(),
        ]);

        $this->actingAs($user)->get('/admin/dashboard');

        $user->refresh();
        $this->assertEquals('free', $user->plan);
        $this->assertEquals('cancelled', $user->subscription_status);
    }

    // ── Recurring events for a replaced subscription are ignored ─────────

    public function test_cancelled_event_for_old_subscription_does_not_cancel_new_one(): void
    {
        $user = User::factory()->pro()->create([
            'email' => 'renewal@example.com',
            'subscription_id' => 'SUB-NEW-1',
            'subscription_status' => 'active',
            'subscription_ends_at' => now()->addMonth(),
        ]);

        $saleId = 'SALE-OLD-1';
        $invoiceId = 'INV-OLD-CANCEL-1';

        $response = $this->postWebhook([
            'message_type' => 'RECURRING_ORDER_CANCELLED',
            'message_id' => 'MSG-OLD-CANCEL-1',
            'sale_id' => $saleId,
            'vendor_id' => 'V-CONS',
            'invoice_id' => $invoiceId,
            'md5_hash' => $this->md5For($saleId, $invoiceId),
            'recurring_order_id' => 'SUB-OLD-1',
            'customer_email' => $user->email,
        ]);

        $response->assertOk();
        $user->refresh();
        $this->assertEquals('active', $user->subscription_status);
        $this->assertNull($user->subscription_cancelled_at);
    }

    public function test_failed_event_for_old_subscription_does_not_mark_new_one_past_due(): void
    {
        \Illuminate\Support\Facades\Mail::fake();

        $user = User::factory()->pro()->create([
            'email' => 'renewal-fail@example.com',
            'subscription_id' => 'SUB-NEW-2',
            'subscription_status' => 'active',
            'subscription_ends_at' => now()->addMonth(),
        ]);

        $saleId = 'SALE-OLD-2';
        $invoiceId = 'INV-OLD-FAIL-1';

        $response = $this->postWebhook([
            'message_type' => 'RECURRING_INSTALLMENT_FAILED',
            'message_id' => 'MSG-OLD-FAIL-1',
            'sale_id' => $saleId,
            'vendor_id' => 'V-CONS',
            'invoice_id' => $invoiceId,
            'md5_hash' => $this->md5For($saleId, $invoiceId),
            'recurring_order_id' => 'SUB-OLD-2',
            'customer_email' => $user->email,
        ]);

        $response->assertOk();
        $user->refresh();
        $this->assertEquals('active', $user->subscription_status);
        $this->assertNull($user->dunning_step);
    }

    public function test_success_event_for_old_subscription_does_not_extend_new_one(): void
    {
        $user = User::factory()->pro()->create([
            'email' => 'renewal-success@example.com',
            'subscription_id' => 'SUB-NEW-3',
            'subscription_status' => 'active',
            'subscription_ends_at' => now()->addMonth(),
            'plan_expires_at' => now()->addMonth(),
        ]);

        $endsAtBefore = $user->subscription_ends_at->copy();

        $saleId = 'SALE-OLD-3';
        $invoiceId = 'INV-OLD-SUCCESS-1';

        $response = $this->postWebhook([
            'message_type' => 'RECURRING_INSTALLMENT_SUCCESS',
            'message_id' => 'MSG-OLD-SUCCESS-1',
            'sale_id' => $saleId,
            'vendor_id' => 'V-CONS',
            'invoice_id' => $invoiceId,
            'md5_hash' => $this->md5For($saleId, $invoiceId),
            'recurring_order_id' => 'SUB-OLD-3',
            'customer_email' => $user->email,
            'item_list_amount_1' => '29.00',
            'list_currency' => 'USD',
        ]);

        $response->assertOk();
        $user->refresh();
        $this->assertEquals(
            $endsAtBefore->format('YmdHis'),
            $user->subscription_ends_at->format('YmdHis'),
            'Old subscription renewal must not extend the replaced subscription period.'
        );
    }

    public function test_success_event_with_matching_subscription_extends_period(): void
    {
        $user = User::factory()->pro()->create([
            'email' => 'renewal-match@example.com',
            'subscription_id' => 'SUB-CURRENT-1',
            'subscription_status' => 'active',
            'subscription_ends_at' => now()->addDays(3),
            'plan_expires_at' => now()->addDays(3),
            'dunning_step' => 1,
        ]);

        $saleId = 'SALE-NEW-4';
        $invoiceId = 'INV-NEW-SUCCESS-1';

        $response = $this->postWebhook([
            'message_type' => 'RECURRING_INSTALLMENT_SUCCESS',
            'message_id' => 'MSG-NEW-SUCCESS-1',
            'sale_id' => $saleId,
            'vendor_id' => 'V-CONS',
            'invoice_id' => $invoiceId,
            'md5_hash' => $this->md5For($saleId, $invoiceId),
            'recurring_order_id' => 'SUB-CURRENT-1',
            'customer_email' => $user->email,
            'item_list_amount_1' => '29.00',
            'list_currency' => 'USD',
            'item_billing_cycle_next_date' => now()->addMonth()->toDateString(),
        ]);

        $response->assertOk();
        $user->refresh();
        $this->assertEquals('active', $user->subscription_status);
        $this->assertEquals(
            now()->addMonth()->endOfDay()->format('Ymd'),
            $user->subscription_ends_at->format('Ymd')
        );
        $this->assertNull($user->dunning_step);

        $this->assertDatabaseHas('transactions', [
            'user_id' => $user->id,
            'invoice_id' => $invoiceId,
        ]);
    }

    public function test_late_failure_for_downgraded_account_does_not_restart_dunning(): void
    {
        \Illuminate\Support\Facades\Mail::fake();

        $user = User::factory()->create([
            'email' => 'late-fail@example.com',
            'plan' => 'free',
            'subscription_id' => 'SUB-LATE-1',
            'subscription_status' => 'expired',
        ]);

        $saleId = 'SALE-LATE-1';
        $invoiceId = 'INV-LATE-FAIL-1';

        $this->postWebhook([
            'message_type' => 'RECURRING_INSTALLMENT_FAILED',
            'message_id' => 'MSG-LATE-FAIL-1',
            'sale_id' => $saleId,
            'vendor_id' => 'V-CONS',
            'invoice_id' => $invoiceId,
            'md5_hash' => $this->md5For($saleId, $invoiceId),
            'recurring_order_id' => 'SUB-LATE-1',
            'customer_email' => $user->email,
        ])->assertOk();

        $user->refresh();
        $this->assertEquals('expired', $user->subscription_status);
        $this->assertNull($user->dunning_step);
        \Illuminate\Support\Facades\Mail::assertNothingQueued();
        \Illuminate\Support\Facades\Mail::assertNothingSent();
    }

    public function test_failed_installment_on_live_subscription_marks_past_due_and_starts_dunning(): void
    {
        \Illuminate\Support\Facades\Mail::fake();

        $user = User::factory()->pro()->create([
            'email' => 'live-fail@example.com',
            'subscription_id' => 'SUB-LIVE-1',
            'subscription_status' => 'active',
        ]);

        $saleId = 'SALE-LIVE-1';
        $invoiceId = 'INV-LIVE-FAIL-1';

        $this->postWebhook([
            'message_type' => 'RECURRING_INSTALLMENT_FAILED',
            'message_id' => 'MSG-LIVE-FAIL-1',
            'sale_id' => $saleId,
            'vendor_id' => 'V-CONS',
            'invoice_id' => $invoiceId,
            'md5_hash' => $this->md5For($saleId, $invoiceId),
            'recurring_order_id' => 'SUB-LIVE-1',
            'customer_email' => $user->email,
        ])->assertOk();

        $user->refresh();
        $this->assertEquals('past_due', $user->subscription_status);
        $this->assertEquals(1, $user->dunning_step);
    }

    public function test_successful_retry_after_downgrade_restores_the_paid_plan(): void
    {
        $user = User::factory()->create([
            'email' => 'late-pay@example.com',
            'plan' => 'free',
            'max_galleries' => 1,
            'max_images' => 10,
            'subscription_id' => 'SUB-LATE-2',
            'subscription_status' => 'expired',
            'plan_expires_at' => now()->subDays(20),
            'dunning_step' => 3,
        ]);

        $saleId = 'SALE-LATE-2';
        $invoiceId = 'INV-LATE-OK-1';

        $this->postWebhook([
            'message_type' => 'RECURRING_INSTALLMENT_SUCCESS',
            'message_id' => 'MSG-LATE-OK-1',
            'sale_id' => $saleId,
            'vendor_id' => 'V-CONS',
            'invoice_id' => $invoiceId,
            'md5_hash' => $this->md5For($saleId, $invoiceId),
            'recurring_order_id' => 'SUB-LATE-2',
            'customer_email' => $user->email,
            'item_id_1' => self::PRODUCT_ID_STUDIO,
            'item_list_amount_1' => '99.00',
            'list_currency' => 'USD',
            'item_billing_cycle_next_date' => now()->addMonth()->toDateString(),
        ])->assertOk();

        $user->refresh();
        $this->assertEquals('studio', $user->plan, 'A customer who paid must not stay on Free.');
        $this->assertEquals(999, $user->max_galleries);
        $this->assertEquals(500, $user->max_images);
        $this->assertEquals('active', $user->subscription_status);
        $this->assertNull($user->dunning_step);
        $this->assertTrue($user->plan_expires_at->isFuture());
    }

    public function test_new_purchase_clears_stale_dunning_state(): void
    {
        $user = User::factory()->pro()->create([
            'email' => 'fresh-order@example.com',
            'subscription_id' => 'SUB-OLD-DUN',
            'subscription_status' => 'past_due',
            'dunning_step' => 2,
            'dunning_last_sent_at' => now()->subDays(4),
        ]);

        $saleId = 'SALE-FRESH-1';
        $invoiceId = 'INV-FRESH-1';

        $this->postWebhook([
            'message_type' => 'ORDER_CREATED',
            'message_id' => 'MSG-FRESH-1',
            'sale_id' => $saleId,
            'vendor_id' => 'V-CONS',
            'invoice_id' => $invoiceId,
            'md5_hash' => $this->md5For($saleId, $invoiceId),
            'customer_email' => $user->email,
            'item_id_1' => self::PRODUCT_ID_PRO,
            'item_list_amount_1' => '29.00',
            'list_currency' => 'USD',
            'recurring_order_id' => 'SUB-NEW-DUN',
        ])->assertOk();

        $user->refresh();
        $this->assertEquals('active', $user->subscription_status);
        $this->assertNull($user->dunning_step);
        $this->assertNull($user->dunning_last_sent_at);
    }

    public function test_legacy_email_matched_renewal_without_local_subscription_id_still_extends(): void
    {
        $user = User::factory()->pro()->create([
            'email' => 'legacy@example.com',
            'subscription_id' => null,
            'subscription_status' => null,
            'plan_expires_at' => now()->addDays(3),
        ]);

        $saleId = 'SALE-LEGACY-1';
        $invoiceId = 'INV-LEGACY-SUCCESS-1';

        $response = $this->postWebhook([
            'message_type' => 'RECURRING_INSTALLMENT_SUCCESS',
            'message_id' => 'MSG-LEGACY-SUCCESS-1',
            'sale_id' => $saleId,
            'vendor_id' => 'V-CONS',
            'invoice_id' => $invoiceId,
            'md5_hash' => $this->md5For($saleId, $invoiceId),
            'recurring_order_id' => 'SUB-LEGACY-1',
            'customer_email' => $user->email,
            'item_list_amount_1' => '29.00',
            'list_currency' => 'USD',
            'item_billing_cycle_next_date' => now()->addMonth()->toDateString(),
        ]);

        $response->assertOk();
        $user->refresh();
        $this->assertEquals('active', $user->subscription_status);
    }

    public function test_duplicate_recurring_success_is_idempotent(): void
    {
        $user = User::factory()->pro()->create([
            'email' => 'idem@example.com',
            'subscription_id' => 'SUB-IDEM-1',
            'subscription_status' => 'active',
        ]);

        $payload = [
            'message_type' => 'RECURRING_INSTALLMENT_SUCCESS',
            'message_id' => 'MSG-IDEM-1',
            'sale_id' => 'SALE-IDEM-1',
            'vendor_id' => 'V-CONS',
            'invoice_id' => 'INV-IDEM-1',
            'md5_hash' => $this->md5For('SALE-IDEM-1', 'INV-IDEM-1'),
            'recurring_order_id' => 'SUB-IDEM-1',
            'customer_email' => $user->email,
            'item_list_amount_1' => '29.00',
            'list_currency' => 'USD',
            'item_billing_cycle_next_date' => now()->addMonth()->toDateString(),
        ];

        $this->postWebhook($payload)->assertOk();
        $this->postWebhook($payload)->assertOk();

        $this->assertDatabaseCount('transactions', 1);
    }

    // ── Downgrade truthfulness for subscription users ────────────────────

    public function test_downgrade_with_active_subscription_cancels_but_does_not_change_plan(): void
    {
        $user = User::factory()->studio()->create([
            'subscription_id' => 'SUB-DG-1',
            'subscription_status' => 'active',
            'subscription_ends_at' => now()->addDays(12),
        ]);

        $response = $this->actingAs($user)
            ->post(route('billing.downgrade'), ['plan' => 'pro']);

        $response->assertRedirect(route('billing.index'));
        $response->assertSessionHas('success');

        $user->refresh();
        $this->assertEquals('studio', $user->plan);
        $this->assertEquals('cancelled', $user->subscription_status);
        $this->assertNotNull($user->subscription_cancelled_at);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/subscriptions/SUB-DG-1/cancel'));
    }

    public function test_downgrade_with_past_due_subscription_cancels_it_at_2checkout(): void
    {
        $user = User::factory()->studio()->create([
            'subscription_id' => 'SUB-DG-PD',
            'subscription_status' => 'past_due',
            'subscription_ends_at' => now()->addDays(3),
        ]);

        $this->actingAs($user)
            ->post(route('billing.downgrade'), ['plan' => 'free'])
            ->assertRedirect(route('billing.index'))
            ->assertSessionHas('success');

        Http::assertSent(fn ($request) => str_contains($request->url(), '/subscriptions/SUB-DG-PD/cancel'));

        $user->refresh();
        $this->assertSame('cancelled', $user->subscription_status, 'A retrying subscription must not be left live after a downgrade.');
        $this->assertSame('studio', $user->plan, 'Access runs to the end of the paid period, as for an active subscription.');
    }

    public function test_downgrade_without_subscription_changes_plan_immediately(): void
    {
        $user = User::factory()->studio()->create([
            'subscription_id' => null,
            'subscription_status' => null,
            'plan_expires_at' => null,
        ]);

        $response = $this->actingAs($user)
            ->post(route('billing.downgrade'), ['plan' => 'pro']);

        $response->assertRedirect(route('billing.index'));
        $response->assertSessionHas('success');

        $user->refresh();
        $this->assertEquals('pro', $user->plan);
        $this->assertEquals(5, $user->max_galleries);
        $this->assertEquals(100, $user->max_images);
    }

    public function test_downgrade_rejects_higher_tier_targets(): void
    {
        $user = User::factory()->pro()->create();

        // 'studio' is not a legal downgrade target — validation rejects it
        // before any state can change.
        $response = $this->actingAs($user)
            ->post(route('billing.downgrade'), ['plan' => 'studio']);

        $response->assertSessionHasErrors('plan');

        $user->refresh();
        $this->assertEquals('pro', $user->plan);
    }

    public function test_downgrade_ignores_unknown_plan_values(): void
    {
        $user = User::factory()->pro()->create();

        $response = $this->actingAs($user)
            ->post(route('billing.downgrade'), ['plan' => 'ultra']);

        $response->assertSessionHasErrors('plan');
        $user->refresh();
        $this->assertEquals('pro', $user->plan);
    }

    // ── Full refund marks stale subscription truthfully ──────────────────

    public function test_full_refund_of_subscription_marks_local_state_cancelled(): void
    {
        $user = User::factory()->pro()->create([
            'email' => 'refund-sub@example.com',
            'subscription_id' => 'SUB-REF-1',
            'subscription_status' => 'active',
            'subscription_ends_at' => now()->addDays(20),
        ]);

        Transaction::factory()->create([
            'user_id' => $user->id,
            'invoice_id' => 'INV-REF-SUB-1',
            'plan' => 'pro',
            'amount' => 29.00,
            'status' => 'completed',
        ]);

        $saleId = 'SALE-REF-1';
        $invoiceId = 'INV-REF-SUB-1';

        $response = $this->postWebhook([
            'message_type' => 'REFUND_ISSUED',
            'message_id' => 'MSG-REF-SUB-1',
            'sale_id' => $saleId,
            'vendor_id' => 'V-CONS',
            'invoice_id' => $invoiceId,
            'md5_hash' => $this->md5For($saleId, $invoiceId),
            'item_list_amount_1' => '29.00',
        ]);

        $response->assertOk();
        $user->refresh();
        $this->assertEquals('free', $user->plan);
        $this->assertEquals('expired', $user->subscription_status);
    }
}
