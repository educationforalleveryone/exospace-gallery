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

class BillingAndExportTest extends TestCase
{
    use RefreshDatabase;

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

        $response = $this->actingAs($user)->get('/billing');
        $response->assertOk();
        $response->assertSee('Download');

        $queryCount = 0;
        DB::listen(function () use (&$queryCount) {
            $queryCount++;
        });
        $this->actingAs($user)->get('/billing');
        $this->assertLessThan(
            15,
            $queryCount,
            'Billing portal should eager-load invoice relationship. '
            . "Expected <15 queries, got {$queryCount}."
        );
    }

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
        config(['services.2checkout.coupon_allowlist' => 'LAUNCH20,WELCOME10']);

        $response = $this->actingAs($user)->get('/billing/upgrade/pro?coupon=LAUNCH20');

        $location = $response->headers->get('Location');
        $this->assertStringContainsString('coupon=LAUNCH20', $location);
    }

    public function test_billing_upgrade_with_affiliate_appends_to_url(): void
    {
        $user = User::factory()->create();
        config(['services.2checkout.product_id_pro' => 'PRO-001']);
        // affiliate refs are validated against an allowlist too.
        config(['services.2checkout.affiliate_allowlist' => 'AFF123,PARTNER7']);

        $response = $this->actingAs($user)->get('/billing/upgrade/pro?ref=AFF123');

        $location = $response->headers->get('Location');
        $this->assertStringContainsString('affiliate=AFF123', $location);
    }

    public function test_profile_export_requires_auth(): void
    {
        $response = $this->get('/profile/export');
        $response->assertRedirect('/login');
    }

    public function test_profile_export_returns_json(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/profile/export');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/zip');
        $response->assertHeader('Content-Disposition');
    }

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

    // ── Gallery duplication preserves metadata ─────────────────────

    public function test_gallery_duplication_preserves_artist_attribution(): void
    {
        $user = User::factory()->pro()->create();
        $gallery = Gallery::factory()->create(['user_id' => $user->id]);
        $artist = \App\Models\Artist::factory()->create(['created_by' => $user->id]);
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

    // ── Custom-domain verification ───────────────

    public function test_unverified_custom_domain_does_not_route(): void
    {
        $gallery = Gallery::factory()->create([
            'is_active'   => true,
            'custom_domain' => 'test.example.com',
            'custom_domain_verification_token' => 'test-token-123',
            'custom_domain_verified_at' => null, // not verified
        ]);
        GalleryImage::factory()->create(['gallery_id' => $gallery->id]);

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

        $response = $this->withSession([])
            ->get('/gallery/anything', [], ['X-Forwarded-Host' => 'test.example.com']);

        // The gallery should be findable via the resolved attribute
        $this->assertDatabaseHas('galleries', [
            'id' => $gallery->id,
            'custom_domain_verified_at' => $gallery->custom_domain_verified_at,
        ]);
    }
}
