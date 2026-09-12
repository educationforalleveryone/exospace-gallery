<?php

namespace Tests\Feature;

use App\Models\Gallery;
use App\Models\Invoice;
use App\Models\Team;
use App\Models\Transaction;
use App\Models\User;
use App\Services\InvoiceGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Tests\TestCase;

/**
 * ITERATION 15 — Media & private file authorization boundary tests.
 *
 * The storage/architecture facts this suite pins down (traced before any
 * change was made):
 *
 *   - Exospace uses Spatie Media Library ONLY on GalleryImage (artwork);
 *     the media + conversions live on the PUBLIC disk and are public BY
 *     DESIGN (public gallery presentation, 3D renderer texture loading).
 *     Section D pins that property so it can never be "fixed" into private.
 *
 *   - Laravel 12 registers GET/PUT /storage/{path} routes for local disks
 *     with serve=true; the vendor ServeFile/ReceiveFile handlers require a
 *     VALID RELATIVE SIGNATURE for private disks (default visibility), so
 *     private-disk files are not retrievable by guessing URLs.
 *
 *   - The ONE private-file defect found: invoice PDFs were written to the
 *     PUBLIC disk at sequential, guessable paths
 *     (invoices/{year}/INV-2026-00001.pdf …), so anyone could scrape every
 *     customer's financial documents directly at /storage/invoices/… while
 *     the owner-only BillingController::downloadInvoice endpoint was
 *     bypassed entirely. Sections A + B pin the fix: invoice files are
 *     written to the PRIVATE disk and served exclusively through the
 *     owner-authorized endpoint; a safe copy→verify→delete command retires
 *     the legacy public copies.
 *
 *   - Gallery media uploads (artwork, audio, branding logos) must be
 *     authorized server-side BEFORE any file lands on disk or is replaced
 *     (Section C — the audio/branding endpoints were policy-gated but had
 *     no regression coverage).
 *
 * NOTE ON LIVEWIRE (brief Section 10): verified during the trace that NO
 * Livewire components exist in this codebase (no app/Livewire directory,
 * no WithFileUploads usage) — all media interactions are classic Blade
 * form uploads and AJAX POSTs, covered by the HTTP tests in this file.
 *
 * NOTE ON RunInSeparateProcess: same PERF-19 precedent as iterations
 * 12-14 — tests that reach Team::memberRole() (memoized in a PHP static)
 * run in their own process to avoid cross-test memo poisoning.
 */
class MediaAuthorizationBoundaryTest extends TestCase
{
    use RefreshDatabase;

    // ── Fixture helpers ──────────────────────────────────────────────────

    private function invoiceFor(User $user, string $path): Invoice
    {
        return Invoice::factory()->create([
            'user_id' => $user->id,
            'pdf_path' => $path,
            'issued_at' => now(),
        ]);
    }

    // ═════════════════════════════════════════════════════════════════════
    // A. INVOICE FILE STORAGE BOUNDARY (M-1 fix)
    // ═════════════════════════════════════════════════════════════════════

    public function test_new_invoice_files_are_written_to_private_disk_not_public_disk(): void
    {
        Storage::fake('local');
        Storage::fake('public');

        $user = User::factory()->create();
        $transaction = Transaction::factory()->create(['user_id' => $user->id]);

        $invoice = app(InvoiceGenerator::class)->generateForTransaction($transaction, $user, ['tax_rate' => 0]);

        $this->assertNotNull($invoice);
        $this->assertNotNull($invoice->pdf_path);

        // The file exists on the PRIVATE disk and nowhere on the PUBLIC disk.
        Storage::disk('local')->assertExists($invoice->pdf_path);
        Storage::disk('public')->assertMissing($invoice->pdf_path);

        // Same for the year directory — no invoice artifacts at all.
        $yearDir = 'invoices/'.$invoice->issued_at->year;
        $this->assertCount(
            0,
            collect(Storage::disk('public')->allFiles($yearDir)),
            'No invoice file may be served from the public disk.',
        );
    }

    public function test_owner_can_download_invoice_from_private_disk(): void
    {
        Storage::fake('local');
        Storage::fake('public');

        $owner = User::factory()->create();
        $invoice = $this->invoiceFor($owner, 'invoices/2026/INV-2026-00001.pdf');
        Storage::disk('local')->put($invoice->pdf_path, '%PDF-1.4 private-invoice-bytes');

        $response = $this->actingAs($owner)->get("/billing/invoice/{$invoice->id}");

        $response->assertOk();
        $this->assertStringContainsString('private-invoice-bytes', $response->getContent());
        $response->assertHeader('Content-Type', 'application/pdf');
        // Symfony normalizes Cache-Control directive order — assert the
        // no-store semantics, not the exact string ordering.
        $cacheControl = (string) $response->headers->get('Cache-Control');
        $this->assertStringContainsString('no-store', $cacheControl);
        $this->assertStringContainsString('private', $cacheControl);
    }

    public function test_owner_can_still_download_legacy_invoice_on_public_disk(): void
    {
        // Backward compatibility: invoices generated BEFORE the iteration-15
        // fix sit on the public disk. The authorized endpoint serves them
        // until the operator runs the documented migration command.
        Storage::fake('local');
        Storage::fake('public');

        $owner = User::factory()->create();
        $invoice = $this->invoiceFor($owner, 'invoices/2025/INV-2025-00007.pdf');
        Storage::disk('public')->put($invoice->pdf_path, '%PDF-1.4 legacy-public-bytes');

        $response = $this->actingAs($owner)->get("/billing/invoice/{$invoice->id}");

        $response->assertOk();
        $this->assertStringContainsString('legacy-public-bytes', $response->getContent());
    }

    public function test_another_user_cannot_download_invoice_by_id(): void
    {
        // Possession of an invoice identifier is NOT authorization.
        Storage::fake('local');
        Storage::fake('public');

        $owner = User::factory()->create();
        $attacker = User::factory()->create();
        $invoice = $this->invoiceFor($owner, 'invoices/2026/INV-2026-00002.pdf');
        Storage::disk('local')->put($invoice->pdf_path, '%PDF-1.4 secret');

        $response = $this->actingAs($attacker)->get("/billing/invoice/{$invoice->id}");

        $response->assertForbidden();
        $this->assertStringNotContainsString('secret', $response->getContent());
    }

    public function test_guest_cannot_download_invoice(): void
    {
        $owner = User::factory()->create();
        $invoice = $this->invoiceFor($owner, 'invoices/2026/INV-2026-00003.pdf');

        $this->get("/billing/invoice/{$invoice->id}")->assertRedirect();
    }

    public function test_invoice_with_no_file_on_any_disk_returns_404(): void
    {
        Storage::fake('local');
        Storage::fake('public');

        $owner = User::factory()->create();
        $invoice = $this->invoiceFor($owner, 'invoices/2026/INV-2026-00004.pdf');

        $this->actingAs($owner)->get("/billing/invoice/{$invoice->id}")->assertNotFound();
    }

    public function test_direct_public_url_does_not_serve_new_invoice_files(): void
    {
        Storage::fake('local');
        Storage::fake('public');

        $user = User::factory()->create();
        $transaction = Transaction::factory()->create(['user_id' => $user->id]);
        $invoice = app(InvoiceGenerator::class)->generateForTransaction($transaction, $user, ['tax_rate' => 0]);

        // The direct-URL shape an attacker would scrape (sequential numbers).
        $response = $this->get('/storage/'.$invoice->pdf_path);

        // In the test environment the unsigned request is rejected by the
        // framework's private-file handler (403); behind the production
        // nginx the file is simply absent (404). Both mean: not retrievable.
        $this->assertContains($response->status(), [403, 404], 'Direct public URL must not serve private invoice files.');
        $this->assertStringNotContainsString('%PDF', (string) $response->getContent());
        Storage::disk('public')->assertMissing($invoice->pdf_path);
    }

    // ═════════════════════════════════════════════════════════════════════
    // B. LEGACY MIGRATION COMMAND (exospace:migrate-invoices-to-private)
    // ═════════════════════════════════════════════════════════════════════

    public function test_migration_dry_run_reports_without_moving_files(): void
    {
        Storage::fake('local');
        Storage::fake('public');

        $owner = User::factory()->create();
        $invoice = $this->invoiceFor($owner, 'invoices/2025/INV-2025-00001.pdf');
        Storage::disk('public')->put($invoice->pdf_path, '%PDF-1.4 legacy');

        $this->artisan('exospace:migrate-invoices-to-private')->assertSuccessful();

        Storage::disk('public')->assertExists($invoice->pdf_path);
        Storage::disk('local')->assertMissing($invoice->pdf_path);
    }

    public function test_migration_force_moves_legacy_files_and_keeps_rows_untouched(): void
    {
        Storage::fake('local');
        Storage::fake('public');

        $owner = User::factory()->create();
        $invoice = $this->invoiceFor($owner, 'invoices/2025/INV-2025-00002.pdf');
        Storage::disk('public')->put($invoice->pdf_path, '%PDF-1.4 legacy-bytes');

        $this->artisan('exospace:migrate-invoices-to-private', ['--force' => true])->assertSuccessful();

        Storage::disk('local')->assertExists($invoice->pdf_path);
        Storage::disk('public')->assertMissing($invoice->pdf_path);

        // Bytes preserved verbatim + no DB rewrite.
        $this->assertSame('%PDF-1.4 legacy-bytes', Storage::disk('local')->get($invoice->pdf_path));
        $this->assertSame('invoices/2025/INV-2025-00002.pdf', $invoice->fresh()->pdf_path);
    }

    public function test_migration_is_idempotent(): void
    {
        Storage::fake('local');
        Storage::fake('public');

        $owner = User::factory()->create();
        $invoice = $this->invoiceFor($owner, 'invoices/2025/INV-2025-00003.pdf');
        Storage::disk('public')->put($invoice->pdf_path, '%PDF-1.4 once');

        $this->artisan('exospace:migrate-invoices-to-private', ['--force' => true])->assertSuccessful();
        $this->artisan('exospace:migrate-invoices-to-private', ['--force' => true])->assertSuccessful();

        Storage::disk('local')->assertExists($invoice->pdf_path);
        Storage::disk('public')->assertMissing($invoice->pdf_path);
        $this->assertSame('%PDF-1.4 once', Storage::disk('local')->get($invoice->pdf_path));
    }

    public function test_migration_never_touches_non_invoice_public_files(): void
    {
        // Gallery images / branding / venue assets are public BY DESIGN —
        // the migration must only ever see files referenced by pdf_path.
        Storage::fake('local');
        Storage::fake('public');

        Storage::disk('public')->put('galleries/7/artwork.jpg', 'artwork-bytes');
        Storage::disk('public')->put('branding/logo.png', 'logo-bytes');

        $owner = User::factory()->create();
        $invoice = $this->invoiceFor($owner, 'invoices/2025/INV-2025-00004.pdf');
        Storage::disk('public')->put($invoice->pdf_path, '%PDF-1.4 move-me');

        $this->artisan('exospace:migrate-invoices-to-private', ['--force' => true])->assertSuccessful();

        Storage::disk('public')->assertExists('galleries/7/artwork.jpg');
        Storage::disk('public')->assertExists('branding/logo.png');
        Storage::disk('local')->assertMissing('galleries/7/artwork.jpg');
        Storage::disk('public')->assertMissing($invoice->pdf_path);
    }

    public function test_migration_survives_missing_public_files(): void
    {
        Storage::fake('local');
        Storage::fake('public');

        $owner = User::factory()->create();
        $this->invoiceFor($owner, 'invoices/2024/INV-2024-00009.pdf'); // file never existed

        $this->artisan('exospace:migrate-invoices-to-private', ['--force' => true])->assertSuccessful();

        Storage::disk('local')->assertMissing('invoices/2024/INV-2024-00009.pdf');
    }

    public function test_migration_retires_duplicate_public_copies_of_migrated_files(): void
    {
        // Edge case: a file already on the private disk with a leftover
        // public duplicate (e.g. an earlier migration run interrupted before
        // its cleanup). The redundant public copy must be retired too —
        // otherwise /storage/… URLs stay live forever for that invoice.
        Storage::fake('local');
        Storage::fake('public');

        $owner = User::factory()->create();
        $invoice = $this->invoiceFor($owner, 'invoices/2025/INV-2025-00005.pdf');
        Storage::disk('local')->put($invoice->pdf_path, '%PDF-1.4 already-migrated');
        Storage::disk('public')->put($invoice->pdf_path, '%PDF-1.4 leftover-duplicate');

        $this->artisan('exospace:migrate-invoices-to-private', ['--force' => true])->assertSuccessful();

        Storage::disk('public')->assertMissing($invoice->pdf_path);
        Storage::disk('local')->assertExists($invoice->pdf_path);
        $this->assertSame('%PDF-1.4 already-migrated', Storage::disk('local')->get($invoice->pdf_path));
    }

    // ═════════════════════════════════════════════════════════════════════
    // C. GALLERY MEDIA UPLOAD / REPLACE BOUNDARY (audio + branding)
    // ═════════════════════════════════════════════════════════════════════

    public function test_pro_owner_can_upload_gallery_audio(): void
    {
        Storage::fake('public');

        $owner = User::factory()->create(['plan' => 'pro']);
        $gallery = Gallery::factory()->create(['user_id' => $owner->id]);

        $response = $this->actingAs($owner)->post(
            "/admin/galleries/{$gallery->id}/upload-audio",
            ['audio' => UploadedFile::fake()->create('ambience.mp3', 100, 'audio/mpeg')],
        );

        $response->assertOk()->assertJson(['success' => true]);
        $gallery->refresh();
        $this->assertNotNull($gallery->audio_path);
        Storage::disk('public')->assertExists($gallery->audio_path);
    }

    public function test_stranger_cannot_upload_audio_to_anothers_gallery(): void
    {
        Storage::fake('public');

        $owner = User::factory()->create(['plan' => 'pro']);
        $gallery = Gallery::factory()->create(['user_id' => $owner->id, 'audio_path' => 'audio/existing.mp3']);
        Storage::disk('public')->put('audio/existing.mp3', 'current-track');
        $stranger = User::factory()->create();

        $response = $this->actingAs($stranger)->post(
            "/admin/galleries/{$gallery->id}/upload-audio",
            ['audio' => UploadedFile::fake()->create('evil.mp3', 100, 'audio/mpeg')],
        );

        $response->assertForbidden();

        // No new file landed and the existing assignment is untouched.
        $gallery->refresh();
        $this->assertSame('audio/existing.mp3', $gallery->audio_path);
        Storage::disk('public')->assertExists('audio/existing.mp3');
        $this->assertCount(1, collect(Storage::disk('public')->allFiles('audio')));
    }

    public function test_studio_owner_replacing_logo_deletes_old_file(): void
    {
        Storage::fake('public');

        $owner = User::factory()->create(['plan' => 'studio']);
        $gallery = Gallery::factory()->create(['user_id' => $owner->id, 'custom_logo_path' => 'branding/old.png']);
        Storage::disk('public')->put('branding/old.png', 'old-logo');

        $response = $this->actingAs($owner)->post(
            "/admin/galleries/{$gallery->id}/upload-logo",
            ['custom_logo' => UploadedFile::fake()->image('new.png')],
        );

        $response->assertOk()->assertJson(['success' => true]);
        $gallery->refresh();
        $this->assertNotSame('branding/old.png', $gallery->custom_logo_path);
        Storage::disk('public')->assertExists($gallery->custom_logo_path);
        Storage::disk('public')->assertMissing('branding/old.png');
    }

    public function test_stranger_cannot_replace_anothers_branding_logo(): void
    {
        Storage::fake('public');

        $owner = User::factory()->create(['plan' => 'studio']);
        $gallery = Gallery::factory()->create(['user_id' => $owner->id, 'custom_logo_path' => 'branding/keep.png']);
        Storage::disk('public')->put('branding/keep.png', 'keeper');
        $stranger = User::factory()->create();

        $response = $this->actingAs($stranger)->post(
            "/admin/galleries/{$gallery->id}/upload-logo",
            ['custom_logo' => UploadedFile::fake()->image('evil.png')],
        );

        $response->assertForbidden();
        $gallery->refresh();
        $this->assertSame('branding/keep.png', $gallery->custom_logo_path);
        Storage::disk('public')->assertExists('branding/keep.png');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_team_viewer_cannot_upload_audio_to_team_gallery(): void
    {
        Storage::fake('public');

        $owner = User::factory()->create(['plan' => 'pro']);
        $team = Team::factory()->create(['owner_id' => $owner->id]);
        $viewer = User::factory()->create();
        $team->members()->attach($viewer->id, ['role' => 'viewer']);
        $gallery = Gallery::factory()->create(['user_id' => $owner->id, 'team_id' => $team->id]);

        $response = $this->actingAs($viewer)->post(
            "/admin/galleries/{$gallery->id}/upload-audio",
            ['audio' => UploadedFile::fake()->create('track.mp3', 100, 'audio/mpeg')],
        );

        $response->assertForbidden();
        $gallery->refresh();
        $this->assertNull($gallery->audio_path);
        $this->assertFalse(Storage::disk('public')->exists('audio/track.mp3'));
    }

    // ═════════════════════════════════════════════════════════════════════
    // D. PUBLIC MEDIA PRESERVATION (public-by-design must stay public)
    // ═════════════════════════════════════════════════════════════════════

    public function test_artwork_upload_remains_publicly_stored_and_renderable(): void
    {
        // Artwork images intentionally live on the PUBLIC disk — the 3D
        // renderer and public gallery pages load them without auth. This
        // test fails if anyone ever "privatizes" artwork storage by
        // accident (the inverse failure mode of M-1).
        Storage::fake('public');

        $owner = User::factory()->create();
        $gallery = Gallery::factory()->create(['user_id' => $owner->id]);

        $response = $this->actingAs($owner)->post(
            "/admin/galleries/{$gallery->id}/images",
            ['file' => UploadedFile::fake()->image('artwork.jpg', 800, 600)],
        );

        $response->assertOk()->assertJson(['success' => true]);
        $imageId = $response->json('id');
        $this->assertNotNull($imageId);

        $image = \App\Models\GalleryImage::findOrFail($imageId);
        $this->assertSame($gallery->id, $image->gallery_id);
        Storage::disk('public')->assertExists(\Illuminate\Support\Str::after($image->path, 'storage/'));
    }

    // ═════════════════════════════════════════════════════════════════════
    // E. MEDIA IDENTIFIER SURFACE (no direct Spatie-Media endpoints exist)
    // ═════════════════════════════════════════════════════════════════════

    public function test_there_is_no_direct_media_identifier_endpoint(): void
    {
        // Spatie Media records are never bound to routes — a leaked media
        // table ID alone grants nothing through the application layer.
        // (Artwork-row identifiers are gallery-policy-gated — see
        // ArtworkAuthorizationBoundaryTest, Iteration 14.)
        //
        // 404 = no route; 405 = a catch-all GET pattern matches the path
        // but no handler serves the requested method. Both prove there is
        // no media-by-identifier endpoint behind either verb.
        $this->get('/media/1/original.jpg')->assertNotFound();
        $this->assertContains(
            $this->delete('/media/1')->status(),
            [404, 405],
        );
    }
}
