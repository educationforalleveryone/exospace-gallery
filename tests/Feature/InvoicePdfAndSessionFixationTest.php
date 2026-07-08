<?php

declare(strict_types=1);

/**
 * Iteration-002 regression tests for audit 2CO-6 (PDF invoices via dompdf)
 * and the CR-4 deferred fix (session regeneration on registration).
 *
 * Run: php artisan test --filter=InvoicePdfAndSessionFixationTest
 */

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Transaction;
use App\Models\User;
use App\Services\InvoiceGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class InvoicePdfAndSessionFixationTest extends TestCase
{
    use RefreshDatabase;

    public function test_2co6_invoice_generator_produces_pdf_when_dompdf_installed(): void
    {
        Storage::fake('public');

        $transaction = Transaction::factory()->create([
            'amount'   => 29.00,
            'currency' => 'USD',
            'plan'     => 'pro',
        ]);
        $user = User::factory()->create();

        $generator = app(InvoiceGenerator::class);
        $invoice = $generator->generateForTransaction($transaction, $user);

        $this->assertNotNull($invoice);
        $this->assertNotNull($invoice->pdf_path);

        // 2CO-6 FIX: the path should end in .pdf (not .html) when dompdf is installed
        if (class_exists(\Dompdf\Dompdf::class)) {
            $this->assertStringEndsWith('.pdf', $invoice->pdf_path,
                '2CO-6: Invoice pdf_path must end in .pdf when dompdf is installed.');

            // Verify the file exists and is a valid PDF (starts with %PDF)
            $content = Storage::disk('public')->get($invoice->pdf_path);
            $this->assertStringStartsWith('%PDF', $content,
                '2CO-6: Invoice file must be a valid PDF (starts with %PDF).');
        } else {
            // dompdf not installed — falls back to .html (with a warning log)
            $this->assertStringEndsWith('.html', $invoice->pdf_path,
                '2CO-6: Invoice pdf_path should be .html when dompdf is not installed (fallback).');
        }
    }

    public function test_2co6_invoice_download_serves_correct_content_type(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();
        $invoice = Invoice::factory()->create([
            'user_id'   => $user->id,
            'pdf_path'  => 'invoices/2026/INV-2026-00001.pdf',
        ]);

        // Put a fake PDF file at the path
        Storage::disk('public')->put($invoice->pdf_path, '%PDF-1.4 fake content');

        $response = $this->actingAs($user)
            ->get(route('billing.invoice', ['invoice' => $invoice->id]));

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_2co6_invoice_download_html_fallback_serves_text_html(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();
        $invoice = Invoice::factory()->create([
            'user_id'   => $user->id,
            'pdf_path'  => 'invoices/2026/INV-2026-00002.html', // old HTML invoice
        ]);

        Storage::disk('public')->put($invoice->pdf_path, '<html>fake invoice</html>');

        $response = $this->actingAs($user)
            ->get(route('billing.invoice', ['invoice' => $invoice->id]));

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'text/html');
    }

    public function test_2co6_invoice_download_blocked_for_non_owner(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $invoice = Invoice::factory()->create(['user_id' => $owner->id]);

        $response = $this->actingAs($other)
            ->get(route('billing.invoice', ['invoice' => $invoice->id]));

        $response->assertStatus(403);
    }

    public function test_2co6_regenerate_invoices_command_exists(): void
    {
        $this->assertContains('exospace:regenerate-invoices', \Illuminate\Support\Facades\Artisan::all(),
            '2CO-6: exospace:regenerate-invoices command must be registered.');
    }

    public function test_cr4_session_regenerated_on_normal_registration(): void
    {
        // CR-4 FIX (deferred from Iter-001): session ID must change on registration
        $this->startSession();
        $sessionBefore = Session::getId();

        $this->post(route('register'), [
            'name'                  => 'Test User',
            'email'                 => 'test-cr4@example.com',
            'password'              => 'TestPassword123!',
            'password_confirmation' => 'TestPassword123!',
        ]);

        $sessionAfter = Session::getId();

        $this->assertNotEquals($sessionBefore, $sessionAfter,
            'CR-4 (deferred): Session ID must be regenerated on normal registration to prevent session fixation.');
    }

    public function test_cr4_session_regenerated_on_invitation_acceptance(): void
    {
        // CR-4 FIX (deferred from Iter-001): session ID must change on invitation acceptance
        // This is the higher-risk path (invitation tokens are shared via email)
        $team = \App\Models\Team::factory()->create();
        $invitation = \App\Models\TeamInvitation::factory()->create([
            'team_id' => $team->id,
            'email'   => 'invited-cr4@example.com',
            'token'   => 'test-invitation-token-' . uniqid(),
        ]);

        $this->startSession();
        $sessionBefore = Session::getId();

        $this->post(route('register'), [
            'name'              => 'Invited User',
            'email'             => 'invited-cr4@example.com',
            'password'          => 'TestPassword123!',
            'password_confirmation' => 'TestPassword123!',
            'invitation_token'  => $invitation->token,
        ]);

        $sessionAfter = Session::getId();

        $this->assertNotEquals($sessionBefore, $sessionAfter,
            'CR-4 (deferred): Session ID must be regenerated on invitation acceptance to prevent session fixation.');
    }
}
