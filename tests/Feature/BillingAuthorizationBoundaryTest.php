<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\PendingUpgrade;
use App\Models\Team;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Tests\TestCase;

class BillingAuthorizationBoundaryTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET_WORD = 'iter16-secret-word';

    private const BUY_LINK_SECRET = 'iter16-buy-link-secret';

    private const PRODUCT_ID_PRO = 'ITER16-PRO-1001';

    private const PRODUCT_ID_STUDIO = 'ITER16-STUDIO-2001';

    private const VENDOR_ID = 'V16';

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('services.2checkout.secret_word', self::SECRET_WORD);
        Config::set('services.2checkout.account_number', 'ITER16-ACC');
        Config::set('services.2checkout.product_id_pro', self::PRODUCT_ID_PRO);
        Config::set('services.2checkout.product_id_studio', self::PRODUCT_ID_STUDIO);
        Config::set('services.2checkout.buy_link_secret_word', null);
        Config::set('services.2checkout.coupon_allowlist', '');
        Config::set('services.2checkout.affiliate_allowlist', '');
    }

    private function subscriber(string $subscriptionId, string $plan = 'pro'): User
    {
        return User::factory()->create([
            'plan' => $plan,
            'subscription_id' => $subscriptionId,
            'subscription_status' => 'active',
            'subscription_ends_at' => now()->addMonth(),
        ]);
    }

    private function invoiceFor(User $user, string $marker, ?string $pdfPath = null): Invoice
    {
        $transaction = Transaction::factory()->create([
            'user_id' => $user->id,
            'invoice_id' => $marker,
            'customer_email' => $user->email,
            'customer_name' => $user->name,
        ]);

        return Invoice::factory()->create([
            'user_id' => $user->id,
            'transaction_id' => $transaction->id,
            'invoice_number' => $marker,
            'customer_email' => $user->email,
            'pdf_path' => $pdfPath,
        ]);
    }

    private function md5For(string $saleId, string $invoiceId): string
    {
        // Official 2Checkout INS md5_hash formula:
        // UPPER(MD5(UPPER(MD5(SALE_ID)) . VENDOR_ID . INVOICE_ID . SECRET_WORD))
        $stringToHash = strtoupper(md5($saleId))
            . self::VENDOR_ID
            . $invoiceId
            . self::SECRET_WORD;

        return strtoupper(md5($stringToHash));
    }

    private function signPayloadHmac(array $payload): string
    {
        $fields = [
            'sale_id', 'vendor_id', 'invoice_id', 'message_type', 'message_id',
            'customer_email', 'customer_name', 'item_count',
            'item_id_1', 'item_name_1', 'item_usd_amount_1', 'item_list_amount_1',
            'item_cust_amount_1', 'item_type_1', 'list_currency', 'cust_currency',
        ];

        $hmacPayload = '';
        foreach ($fields as $field) {
            $value = (string) ($payload[$field] ?? '');
            $hmacPayload .= strlen($value).$value;
        }

        return hash_hmac('sha256', $hmacPayload, self::BUY_LINK_SECRET);
    }

    public function test_billing_portal_renders_only_the_session_users_transactions(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        $this->invoiceFor($alice, '2CO-ISOLATION-A-111');
        $this->invoiceFor($bob, '2CO-ISOLATION-B-222');

        $response = $this->actingAs($bob)->get('/billing');

        $response->assertOk();
        $response->assertSee('2CO-ISOLATION-B-222');
        $response->assertDontSee('2CO-ISOLATION-A-111');
    }

    public function test_billing_portal_renders_only_the_session_users_pending_upgrades(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        PendingUpgrade::factory()->create([
            'user_id' => $alice->id,
            'plan' => 'studio',
            'status' => 'pending',
        ]);

        $response = $this->actingAs($bob)->get('/billing');
        $response->assertOk();
        $response->assertDontSee('Pending Upgrades');

        $response = $this->actingAs($alice)->get('/billing');
        $response->assertOk();
        $response->assertSee('Pending Upgrades');
    }

    public function test_owner_invoice_download_succeeds_from_the_private_disk(): void
    {
        Storage::fake('local');
        Storage::fake('public');

        $alice = User::factory()->create();
        $invoice = $this->invoiceFor($alice, '2CO-OWNER-PDF-001', 'invoices/2026/2CO-OWNER-PDF-001.pdf');

        Storage::disk('local')->put($invoice->pdf_path, '%PDF-1.4 ALICE-ONLY-BYTES');

        $response = $this->actingAs($alice)->get("/billing/invoice/{$invoice->id}");

        $response->assertOk();
        $this->assertSame('%PDF-1.4 ALICE-ONLY-BYTES', $response->getContent());
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment;', (string) $response->headers->get('Content-Disposition'));
    }

    public function test_another_user_cannot_download_invoice_by_id(): void
    {
        Storage::fake('local');
        Storage::fake('public');

        $alice = User::factory()->create();
        $bob = User::factory()->create();

        $invoice = $this->invoiceFor($alice, '2CO-SECRET-PDF-002', 'invoices/2026/2CO-SECRET-PDF-002.pdf');
        Storage::disk('local')->put($invoice->pdf_path, '%PDF-1.4 ALICE-ONLY-BYTES');

        $response = $this->actingAs($bob)->get("/billing/invoice/{$invoice->id}");

        $response->assertForbidden();
        $this->assertStringNotContainsString('ALICE-ONLY-BYTES', (string) $response->getContent());
    }

    public function test_sequential_invoice_id_probing_fails_for_every_foreign_invoice(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        $ids = [];
        foreach (['2CO-PROBE-1', '2CO-PROBE-2', '2CO-PROBE-3'] as $i => $marker) {
            $ids[] = $this->invoiceFor($alice, $marker)->id;
        }

        foreach ($ids as $foreignId) {
            $response = $this->actingAs($bob)->get("/billing/invoice/{$foreignId}");
            $response->assertForbidden();
        }
    }

    public function test_guest_cannot_reach_any_billing_surface_and_mutates_nothing(): void
    {
        $alice = $this->subscriber('SUB-ALICE-LIVE');

        // Every billing surface is inside the auth+verified+mfa group.
        $this->get('/billing')->assertRedirect();
        $this->post('/billing/cancel-subscription')->assertRedirect();
        $this->post('/billing/reactivate-subscription')->assertRedirect();
        $this->post('/billing/downgrade', ['plan' => 'free'])->assertRedirect();
        $this->post('/billing/start-trial/pro')->assertRedirect();
        $this->get('/billing/upgrade/pro')->assertRedirect();

        // A guest's failed probes must not have touched Alice's state.
        $alice->refresh();
        $this->assertSame('active', $alice->subscription_status);
        $this->assertSame('pro', $alice->plan);
    }

    public function test_no_transaction_or_subscription_endpoint_exists_for_identifier_probing(): void
    {
        $bob = User::factory()->create();

        $this->actingAs($bob)->get('/billing/transactions/1')->assertNotFound();
        $this->actingAs($bob)->post('/billing/transactions/1')->assertStatus(405);
        $this->actingAs($bob)->get('/billing/invoices/1')->assertNotFound();
        $this->actingAs($bob)->get('/billing/subscription/SUB-ALICE')->assertNotFound();
    }

    public function test_cancel_subscription_targets_server_state_not_client_payload(): void
    {
        $alice = $this->subscriber('SUB-ALICE-VICTIM');
        $bob = User::factory()->create(['plan' => 'free']); // no subscription

        Http::fake();

        $response = $this->actingAs($bob)->post('/billing/cancel-subscription', [
            'subscription_id' => 'SUB-ALICE-VICTIM',
            'user_id' => $alice->id,
        ]);

        $response->assertRedirect(route('billing.index'));
        $response->assertSessionHas('error');

        Http::assertNothingSent(); // no 2Checkout call at all, let alone for Alice

        $alice->refresh();
        $this->assertSame('active', $alice->subscription_status);
        $this->assertSame('SUB-ALICE-VICTIM', $alice->subscription_id);
        $this->assertNull($alice->subscription_cancelled_at);
    }

    public function test_reactivate_targets_server_state_not_client_payload(): void
    {
        $alice = $this->subscriber('SUB-ALICE-VICTIM');

        $bob = User::factory()->create([
            'plan' => 'pro',
            'subscription_id' => 'SUB-BOB-OWN',
            'subscription_status' => 'cancelled',
            'subscription_ends_at' => now()->addWeeks(2),
        ]);

        Http::fake([
            '*/rest/6.0/subscriptions/SUB-BOB-OWN/reactivate' => Http::response(['success' => true], 200),
            '*/rest/6.0/subscriptions/SUB-ALICE-VICTIM/*' => Http::response(['success' => true], 200),
        ]);

        $response = $this->actingAs($bob)->post('/billing/reactivate-subscription', [
            'subscription_id' => 'SUB-ALICE-VICTIM',
            'user_id' => $alice->id,
        ]);

        $response->assertRedirect(route('billing.index'));
        $response->assertSessionHas('success');

        Http::assertSent(fn ($request) => $request->url() === 'https://api.2checkout.com/rest/6.0/subscriptions/SUB-BOB-OWN/reactivate');
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'SUB-ALICE-VICTIM'));

        $bob->refresh();
        $this->assertSame('active', $bob->subscription_status);

        $alice->refresh();
        $this->assertSame('active', $alice->subscription_status);
        $this->assertSame('SUB-ALICE-VICTIM', $alice->subscription_id);
    }

    public function test_downgrade_ignores_injected_user_and_subscription_ids(): void
    {
        $alice = $this->subscriber('SUB-ALICE-VICTIM');
        $bob = User::factory()->pro()->create(); // one-time Pro, no subscription

        Http::fake();

        $response = $this->actingAs($bob)->post('/billing/downgrade', [
            'plan' => 'free',
            'user_id' => $alice->id,          // who to downgrade instead
            'subscription_id' => 'SUB-ALICE-VICTIM',  // whose subscription to cancel
        ]);

        $response->assertRedirect(route('billing.index'));
        $response->assertSessionHas('success');

        // Bob downgraded HIMSELF.
        $bob->refresh();
        $this->assertSame('free', $bob->plan);

        // Alice untouched — and no provider call was made for her subscription.
        Http::assertNothingSent();
        $alice->refresh();
        $this->assertSame('pro', $alice->plan);
        $this->assertSame('active', $alice->subscription_status);
        $this->assertSame('SUB-ALICE-VICTIM', $alice->subscription_id);
    }

    public function test_downgrade_endpoint_cannot_escalate_privileges(): void
    {
        $bob = User::factory()->pro()->create();

        $response = $this->actingAs($bob)->post('/billing/downgrade', [
            'plan' => 'studio',
        ]);

        $response->assertRedirect();
        $response->assertSessionHasErrors(['plan']);

        $bob->refresh();
        $this->assertSame('pro', $bob->plan);
    }

    public function test_upgrade_binds_the_pending_upgrade_to_the_session_user_only(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create(); // free

        $response = $this->actingAs($bob)->get('/billing/upgrade/pro', [
            'user_id' => (string) $alice->id,
            'plan' => 'studio',
            'recurring' => '0',
        ]);

        $response->assertRedirect();
        $this->assertStringContainsString('2checkout.com', (string) $response->headers->get('Location'));

        $this->assertDatabaseHas('pending_upgrades', [
            'user_id' => $bob->id,
            'plan' => 'pro',
            'status' => 'pending',
        ]);
        $this->assertDatabaseMissing('pending_upgrades', [
            'user_id' => $alice->id,
        ]);
    }

    public function test_start_trial_ignores_client_user_and_plan_overrides(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create(); // free

        $response = $this->actingAs($bob)->post('/billing/start-trial/pro', [
            'user_id' => $alice->id, // start the trial on Alice's account?
            'plan' => 'studio',   // escalate the trial tier?
        ]);

        $response->assertRedirect();

        $bob->refresh();
        $this->assertSame('pro', $bob->plan);
        $this->assertNotNull($bob->trial_ends_at);
        $this->assertNotNull($bob->plan_expires_at);

        $alice->refresh();
        $this->assertSame('free', $alice->plan);
        $this->assertNull($alice->trial_ends_at);
        $this->assertNull($alice->plan_expires_at);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_team_member_cannot_view_or_probe_the_team_owners_billing(): void
    {
        $alice = $this->subscriber('SUB-ALICE-VICTIM'); // team owner
        $bob = User::factory()->create();             // team member (editor, even)

        $team = Team::factory()->create(['owner_id' => $alice->id]);
        $team->members()->attach($bob->id, ['role' => 'editor']);

        $invoice = $this->invoiceFor($alice, '2CO-TEAM-OWNER-INV');

        // Portal isolation.
        $portal = $this->actingAs($bob)->get('/billing');
        $portal->assertOk();
        $portal->assertDontSee('2CO-TEAM-OWNER-INV');

        // Invoice probing via team context.
        $this->actingAs($bob)->get("/billing/invoice/{$invoice->id}")->assertForbidden();

        // Mutation probing via team context.
        Http::fake();
        $this->actingAs($bob)->post('/billing/cancel-subscription', [
            'subscription_id' => 'SUB-ALICE-VICTIM',
            'user_id' => $alice->id,
            'team_id' => $team->id,
        ]);

        Http::assertNothingSent();
        $alice->refresh();
        $this->assertSame('active', $alice->subscription_status);
        $this->assertSame('pro', $alice->plan);
    }

    public function test_forged_webhook_without_md5_hash_cannot_bind_victim_billing(): void
    {
        $alice = User::factory()->create(['email' => 'alice@example.test']);

        $response = $this->postJson('/webhooks/2checkout', [
            'message_type' => 'ORDER_CREATED',
            'sale_id' => 'SALE-FORGED',
            'vendor_id' => self::VENDOR_ID,
            'invoice_id' => 'INV-FORGED-001',
            'customer_email' => 'alice@example.test',
            'customer_name' => 'Alice Victim',
            'item_id_1' => self::PRODUCT_ID_PRO,
            'item_list_amount_1' => '29.00',
            'list_currency' => 'USD',
            'merchant_item_id_1' => (string) $alice->id,
            'external_reference' => (string) $alice->id,
            // no md5_hash, no signature
        ]);

        $response->assertStatus(403);

        $alice->refresh();
        $this->assertSame('free', $alice->plan);
        $this->assertDatabaseCount('transactions', 0);
        $this->assertDatabaseCount('pending_upgrades', 0);
    }

    public function test_webhook_with_invalid_md5_hash_cannot_bind_victim_billing(): void
    {
        $alice = User::factory()->create(['email' => 'alice@example.test']);

        $response = $this->postJson('/webhooks/2checkout', [
            'message_type' => 'ORDER_CREATED',
            'sale_id' => 'SALE-FORGED-2',
            'vendor_id' => self::VENDOR_ID,
            'invoice_id' => 'INV-FORGED-002',
            'md5_hash' => str_repeat('F', 32), // forged
            'customer_email' => 'alice@example.test',
            'customer_name' => 'Alice Victim',
            'item_id_1' => self::PRODUCT_ID_PRO,
            'item_list_amount_1' => '29.00',
            'list_currency' => 'USD',
            'merchant_item_id_1' => (string) $alice->id,
            'external_reference' => (string) $alice->id,
        ]);

        $response->assertStatus(403);

        $alice->refresh();
        $this->assertSame('free', $alice->plan);
        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_md5_valid_webhook_is_accepted_even_when_hmac_secret_is_configured(): void
    {
        Config::set('services.2checkout.buy_link_secret_word', self::BUY_LINK_SECRET);

        $alice = User::factory()->create(['email' => 'alice@example.test']);

        $response = $this->postJson('/webhooks/2checkout', [
            'message_type' => 'ORDER_CREATED',
            'sale_id' => 'SALE-TAMPER',
            'vendor_id' => self::VENDOR_ID,
            'invoice_id' => 'INV-TAMPER-001',
            'md5_hash' => $this->md5For('SALE-TAMPER', 'INV-TAMPER-001'),
            'customer_email' => 'alice@example.test',
            'customer_name' => 'Alice Victim',
            'item_id_1' => self::PRODUCT_ID_PRO,
            'item_list_amount_1' => '29.00',
            'list_currency' => 'USD',
            'merchant_item_id_1' => (string) $alice->id,
            // no signature field — 2Checkout INS never sends one
        ]);

        $response->assertStatus(200);

        $alice->refresh();
        $this->assertSame('pro', $alice->plan);
        $this->assertDatabaseCount('transactions', 1);
    }

    public function test_valid_webhook_resolves_owner_via_the_server_minted_token(): void
    {
        Config::set('services.2checkout.buy_link_secret_word', self::BUY_LINK_SECRET);

        $alice = User::factory()->create(['email' => 'alice@example.test']);
        $bob = User::factory()->create(['email' => 'bob@example.test']);

        $pending = PendingUpgrade::createForUser($alice, 'pro', self::PRODUCT_ID_PRO);

        $payload = [
            'message_type' => 'ORDER_CREATED',
            'sale_id' => 'SALE-OK-001',
            'vendor_id' => self::VENDOR_ID,
            'invoice_id' => 'INV-OK-001',
            'md5_hash' => $this->md5For('SALE-OK-001', 'INV-OK-001'),
            'message_id' => 'MSG-OK-001',
            // Substitution attempts: Bob's email and Bob's user id ride along…
            'customer_email' => 'bob@example.test',
            'customer_name' => 'Bob Attacker',
            'item_id_1' => self::PRODUCT_ID_PRO,
            'item_count' => '1',
            'item_name_1' => 'Exospace Pro',
            'item_usd_amount_1' => '29.00',
            'item_list_amount_1' => '29.00',
            'item_cust_amount_1' => '29.00',
            'item_type_1' => 'PRODUCT',
            'list_currency' => 'USD',
            'cust_currency' => 'USD',
            'merchant_item_id_1' => (string) $bob->id,
            // …but the external-reference (server-minted token) wins.
            'external-reference' => $pending->plaintext_token,
            'signature' => '',
        ];
        $payload['signature'] = $this->signPayloadHmac($payload);

        $response = $this->postJson('/webhooks/2checkout', $payload);

        $response->assertOk();

        $alice->refresh();
        $this->assertSame('pro', $alice->plan);
        $this->assertNull($alice->plan_expires_at);

        $bob->refresh();
        $this->assertSame('free', $bob->plan);

        $this->assertDatabaseHas('transactions', [
            'user_id' => $alice->id,
            'invoice_id' => 'INV-OK-001',
        ]);

        $pending->refresh();
        $this->assertSame('converted', $pending->status);
    }

    public function test_webhook_with_unknown_references_upgrades_nobody(): void
    {
        Config::set('services.2checkout.buy_link_secret_word', self::BUY_LINK_SECRET);

        $alice = User::factory()->create(['email' => 'alice@example.test']);
        $bob = User::factory()->create();

        $payload = [
            'message_type' => 'ORDER_CREATED',
            'sale_id' => 'SALE-GHOST',
            'vendor_id' => self::VENDOR_ID,
            'invoice_id' => 'INV-GHOST-001',
            'md5_hash' => $this->md5For('SALE-GHOST', 'INV-GHOST-001'),
            'message_id' => 'MSG-GHOST-001',
            'customer_email' => 'ghost@nowhere.test',
            'customer_name' => 'Ghost Buyer',
            'item_id_1' => self::PRODUCT_ID_PRO,
            'item_count' => '1',
            'item_name_1' => 'Exospace Pro',
            'item_usd_amount_1' => '29.00',
            'item_list_amount_1' => '29.00',
            'item_cust_amount_1' => '29.00',
            'item_type_1' => 'PRODUCT',
            'list_currency' => 'USD',
            'cust_currency' => 'USD',
            'external-reference' => 'not-a-real-token-0000000000000000000000000000000000000000000000000000',
            'signature' => '',
        ];
        $payload['signature'] = $this->signPayloadHmac($payload);

        $response = $this->postJson('/webhooks/2checkout', $payload);

        $response->assertOk(); // 2Checkout expects OK even when no match — but...

        // ...nobody's billing state may move.
        $alice->refresh();
        $this->assertSame('free', $alice->plan);
        $bob->refresh();
        $this->assertSame('free', $bob->plan);
        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_regular_user_cannot_reach_the_super_admin_plan_change(): void
    {
        $alice = User::factory()->pro()->create();
        $bob = User::factory()->create();

        $this->actingAs($bob)
            ->post("/master-control/users/{$alice->id}/plan", ['plan' => 'free'])
            ->assertForbidden();

        $alice->refresh();
        $this->assertSame('pro', $alice->plan);
    }

    public function test_guest_cannot_reach_the_super_admin_plan_change(): void
    {
        $alice = User::factory()->pro()->create();

        $this->post("/master-control/users/{$alice->id}/plan", ['plan' => 'free'])
            ->assertRedirect();

        $alice->refresh();
        $this->assertSame('pro', $alice->plan);
    }
}
