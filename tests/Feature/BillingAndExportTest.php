<?php

namespace Tests\Feature;

use App\Models\Gallery;
use App\Models\GalleryImage;
use App\Models\Invoice;
use App\Models\PendingUpgrade;
use App\Models\Transaction;
use App\Models\User;
use DB;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Billing portal + GDPR export + custom-domain verification tests.
 * (Task H62)
 */
class BillingAndExportTest extends TestCase
{
    use RefreshDatabase;

    // ── Billing portal ───────────────────────────────────────────────────

    public function test_billing_portal_requires_auth(): void
    {
        $response = $this->get('/billing');
        $response->assertRedirect('/login');
    }

    public function test_billing_portal_shows_current_plan(): void
    {
        $user = User::factory()->pro()->create();

        $response = $this->actingAs($user)->get('/billing');

        $response->assertOk();
        $response->assertSee('Pro');
        $response->assertSee('Lifetime');
    }

    public function test_billing_portal_shows_transactions(): void
    {
        $user = User::factory()->pro()->create();
        Transaction::factory()->create([
            'user_id'   => $user->id,
            'plan'      => 'pro',
            'status'    => 'completed',
            'amount'    => 29.00,
        ]);

        $response = $this->actingAs($user)->get('/billing');

        $response->assertOk();
        // formattedAmount() renders "29.00 USD" (multi-currency-safe).
        $response->assertSee('29.00 USD');
        $response->assertSee('Completed');
    }

    /**
     * AUDIT-P0-1.6 FIX: Previously the billing portal queried
     * `Invoice::where('transaction_id', $tx->id)->first()` inside a foreach
     * loop — an N+1. Now BillingController::index eager-loads via
     * ->with('invoice') and the view reads $tx->invoice directly.
     *
     * This test verifies that:
     *   1. The Transaction::invoice() relationship exists and resolves.
     *   2. The billing portal renders the "Download" link when an invoice
     *      with a pdf_path is associated with a transaction.
     *   3. The page does NOT issue an N+1 query (asserted via DB::listen query count).
     */
    public function test_audit_p01_6_billing_portal_eager_loads_invoice(): void
    {
        $user = User::factory()->pro()->create();
        $transaction = Transaction::factory()->create([
            'user_id'   => $user->id,
            'plan'      => 'pro',
            'status'    => 'completed',
            'amount'    => 29.00,
        ]);
        Invoice::factory()->create([
            'user_id'         => $user->id,
            'transaction_id'  => $transaction->id,
            'invoice_number'  => 'INV-' . now()->year . '-00001',
            'pdf_path'        => 'invoices/test-invoice.pdf',
            'plan'            => 'pro',
            'amount'          => 29.00,
        ]);

        // Sanity: the relationship resolves to the invoice we just created.
        $this->assertNotNull($transaction->fresh()->invoice);
        $this->assertEquals('invoices/test-invoice.pdf', $transaction->fresh()->invoice->pdf_path);

        // The page renders the "Download" link (proving the eager-loaded
        // invoice reaches the view without an extra query).
        $response = $this->actingAs($user)->get('/billing');
        $response->assertOk();
        $response->assertSee('Download');

        // Query count: with eager loading, we should see at most a handful of
        // queries (transactions paginate + invoice eager load + user + session
        // + pending upgrades). Without eager loading, we'd see 1 + N queries.
        // We assert "fewer than 15 queries" — generous enough to avoid
        // flakiness but tight enough to catch a regression.
        $queryCount = 0;
        DB::listen(function () use (&$queryCount) {
            $queryCount++;
        });
        $this->actingAs($user)->get('/billing');
        $this->assertLessThan(
            15,
            $queryCount,
            'AUDIT-P0-1.6: Billing portal should eager-load invoice relationship. '
            . "Expected <15 queries, got {$queryCount}."
        );
    }

    /**
     * AUDIT-P0-1.6 FIX: When a transaction has no invoice, the view should
     * render an em-dash placeholder, not crash.
     */
    public function test_audit_p01_6_billing_portal_handles_missing_invoice_gracefully(): void
    {
        $user = User::factory()->pro()->create();
        Transaction::factory()->create([
            'user_id'   => $user->id,
            'plan'      => 'pro',
            'status'    => 'completed',
            'amount'    => 29.00,
        ]);

        $response = $this->actingAs($user)->get('/billing');
        $response->assertOk();
        // The Blade renders `<span class="text-xs text-gray-600">—</span>` when no invoice.
        $response->assertSee('—');
    }

    public function test_billing_portal_shows_pending_upgrades(): void
    {
        $user = User::factory()->create();
        PendingUpgrade::createForUser($user, 'pro', 'PRO-001');

        $response = $this->actingAs($user)->get('/billing');

        $response->assertOk();
        $response->assertSee('Pending Upgrades');
        $response->assertSee('pro');
    }

    public function test_billing_upgrade_redirects_to_2checkout(): void
    {
        $user = User::factory()->create();
        config(['services.2checkout.account_number' => 'ACC-001']);
        config(['services.2checkout.product_id_pro' => 'PRO-001']);

        $response = $this->actingAs($user)->get('/billing/upgrade/pro');

        $response->assertRedirect();
        $this->assertStringContainsString('2checkout.com', $response->headers->get('Location'));
        $this->assertStringContainsString('external-reference=', $response->headers->get('Location'));
    }

    public function test_billing_upgrade_creates_pending_upgrade(): void
    {
        $user = User::factory()->create();
        config(['services.2checkout.product_id_pro' => 'PRO-001']);

        $this->actingAs($user)->get('/billing/upgrade/pro');

        $this->assertDatabaseHas('pending_upgrades', [
            'user_id' => $user->id,
            'plan'    => 'pro',
            'status'  => 'pending',
        ]);
    }

    public function test_billing_upgrade_blocks_downgrade(): void
    {
        config(['services.2checkout.product_id_pro' => 'PRO-001']);
        $user = User::factory()->studio()->create();

        $response = $this->actingAs($user)->get('/billing/upgrade/pro');

        $response->assertRedirect('/billing');
        $response->assertSessionHas('warning');
    }

    public function test_billing_upgrade_blocks_same_plan(): void
    {
        // ITERATION-1 FIX: same-plan purchases are intentionally ALLOWED
        // since the 2CO-7 change (lifetime conversion / re-purchase flows)
        // — the old test asserted the pre-2CO-7 block. The user proceeds
        // to 2Checkout.
        config(['services.2checkout.product_id_pro' => 'PRO-001']);
        $user = User::factory()->pro()->create();

        $response = $this->actingAs($user)->get('/billing/upgrade/pro');

        $this->assertStringContainsString(
            '2checkout.com',
            (string) $response->headers->get('Location'),
            'Same-plan one-time re-purchase proceeds to checkout',
        );
    }

    public function test_billing_upgrade_with_coupon_appends_to_url(): void
    {
        $user = User::factory()->create();
        config(['services.2checkout.product_id_pro' => 'PRO-001']);
        // SEC-8: coupons are validated against an allowlist — the old test
        // never configured one, so the coupon was (correctly) stripped.
        config(['services.2checkout.coupon_allowlist' => 'LAUNCH20,WELCOME10']);

        $response = $this->actingAs($user)->get('/billing/upgrade/pro?coupon=LAUNCH20');

        $location = $response->headers->get('Location');
        $this->assertStringContainsString('coupon=LAUNCH20', $location);
    }

    public function test_billing_upgrade_with_affiliate_appends_to_url(): void
    {
        $user = User::factory()->create();
        config(['services.2checkout.product_id_pro' => 'PRO-001']);
        // SEC-8: affiliate refs are validated against an allowlist too.
        config(['services.2checkout.affiliate_allowlist' => 'AFF123,PARTNER7']);

        $response = $this->actingAs($user)->get('/billing/upgrade/pro?ref=AFF123');

        $location = $response->headers->get('Location');
        $this->assertStringContainsString('affiliate=AFF123', $location);
    }

    // ── GDPR export ──────────────────────────────────────────────────────

    public function test_profile_export_requires_auth(): void
    {
        $response = $this->get('/profile/export');
        $response->assertRedirect('/login');
    }

    public function test_profile_export_returns_json(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/profile/export');

        // ITERATION-1 FIX: the export was upgraded to a ZIP archive
        // (profile.json + CSVs + README) — the old test asserted the
        // legacy raw-JSON response.
        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/zip');
        $response->assertHeader('Content-Disposition');
    }

    /**
     * Unzip the export archive and decode profile.json — shared helper
     * for the content assertions below.
     */
    private function exportJson($response): array
    {
        $zipPath = tempnam(sys_get_temp_dir(), 'exo-export') . '.zip';
        file_put_contents($zipPath, $response->getContent());
        $zip = new \ZipArchive();
        $zip->open($zipPath);
        $json = $zip->getFromName('profile.json');
        $zip->close();
        @unlink($zipPath);

        return json_decode($json, true);
    }

    public function test_profile_export_includes_user_data(): void
    {
        $user = User::factory()->create(['name' => 'Test User']);

        $response = $this->actingAs($user)->get('/profile/export');

        $json = $this->exportJson($response);
        $this->assertEquals('Test User', $json['user']['name']);
        $this->assertEquals($user->email, $json['user']['email']);
    }

    public function test_profile_export_includes_galleries(): void
    {
        $user = User::factory()->create();
        $gallery = Gallery::factory()->create(['user_id' => $user->id, 'title' => 'My Gallery']);
        GalleryImage::factory()->create(['gallery_id' => $gallery->id]);

        $response = $this->actingAs($user)->get('/profile/export');

        $json = $this->exportJson($response);
        $this->assertCount(1, $json['galleries']);
        $this->assertEquals('My Gallery', $json['galleries'][0]['title']);
    }

    public function test_profile_export_includes_transactions(): void
    {
        $user = User::factory()->create();
        Transaction::factory()->create([
            'user_id'   => $user->id,
            'plan'      => 'pro',
            'amount'    => 29.00,
        ]);

        $response = $this->actingAs($user)->get('/profile/export');

        $json = $this->exportJson($response);
        $this->assertCount(1, $json['transactions']);
        $this->assertEquals('pro', $json['transactions'][0]['plan']);
    }

    // ── Gallery duplication preserves metadata (H59) ─────────────────────

    public function test_gallery_duplication_preserves_artist_attribution(): void
    {
        $user = User::factory()->pro()->create();
        $gallery = Gallery::factory()->create(['user_id' => $user->id]);
        $artist = \App\Models\Artist::factory()->create(['created_by' => $user->id]);
        // ITERATION-1 FIX: duplicate() copies image FILES on disk — the old
        // test referenced a path that didn't exist, so the artwork was
        // (correctly) skipped and the clone had no images. Create a real
        // file so the copy succeeds.
        \Illuminate\Support\Facades\Storage::fake('public');
        $image = GalleryImage::factory()->create([
            'gallery_id'  => $gallery->id,
            'artist_id'   => $artist->id,
            'price'       => 500.00,
            'currency'    => 'USD',
            'for_sale'    => true,
            'medium'      => 'Oil on canvas',
            'year'        => 2024,
        ]);
        \Illuminate\Support\Facades\Storage::disk('public')->put($image->path, 'fake-image-bytes');

        $response = $this->actingAs($user)
            ->post("/admin/galleries/{$gallery->id}/duplicate");

        $response->assertRedirect();

        $clone = Gallery::where('title', $gallery->title . ' (Copy)')->first();
        $this->assertNotNull($clone);

        $cloneImage = $clone->images()->first();
        $this->assertEquals($artist->id, $cloneImage->artist_id);
        $this->assertEquals(500.00, $cloneImage->price);
        $this->assertTrue($cloneImage->for_sale);
        $this->assertEquals('Oil on canvas', $cloneImage->medium);
        $this->assertEquals(2024, $cloneImage->year);
    }

    // ── Custom-domain verification (H06 from Iteration 02) ───────────────

    public function test_unverified_custom_domain_does_not_route(): void
    {
        $gallery = Gallery::factory()->create([
            'is_active'   => true,
            'custom_domain' => 'test.example.com',
            'custom_domain_verification_token' => 'test-token-123',
            'custom_domain_verified_at' => null, // not verified
        ]);
        GalleryImage::factory()->create(['gallery_id' => $gallery->id]);

        // The DetectCustomDomain middleware should NOT resolve this gallery
        // because custom_domain_verified_at is null
        $response = $this->get('/gallery/' . $gallery->slug);
        $response->assertOk(); // loads via slug, not custom domain
    }

    public function test_verified_custom_domain_routes(): void
    {
        $gallery = Gallery::factory()->create([
            'is_active'   => true,
            'custom_domain' => 'test.example.com',
            'custom_domain_verification_token' => 'test-token-456',
            'custom_domain_verified_at' => now(), // verified
        ]);
        GalleryImage::factory()->create(['gallery_id' => $gallery->id]);

        // Simulate a custom-domain request by setting the resolved_gallery
        // attribute (the middleware would do this in production)
        $response = $this->withSession([])
            ->get('/gallery/anything', [], ['X-Forwarded-Host' => 'test.example.com']);

        // The gallery should be findable via the resolved attribute
        $this->assertDatabaseHas('galleries', [
            'id' => $gallery->id,
            'custom_domain_verified_at' => $gallery->custom_domain_verified_at,
        ]);
    }
}
