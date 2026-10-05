<?php

declare(strict_types=1);

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
        Storage::fake('local');

        $transaction = Transaction::factory()->create([
            'amount' => 29.00,
            'currency' => 'USD',
            'plan' => 'pro',
        ]);
        $user = User::factory()->create();

        $generator = app(InvoiceGenerator::class);
        $invoice = $generator->generateForTransaction($transaction, $user);

        $this->assertNotNull($invoice);
        $this->assertNotNull($invoice->pdf_path);

        // the path should end in .pdf (not .html) when dompdf is installed
        if (class_exists(\Dompdf\Dompdf::class)) {
            $this->assertStringEndsWith('.pdf', $invoice->pdf_path,
                'Invoice pdf_path must end in .pdf when dompdf is installed.');

            // Verify the file exists and is a valid PDF (starts with %PDF)
            $content = Storage::disk('local')->get($invoice->pdf_path);
            $this->assertStringStartsWith('%PDF', $content,
                'Invoice file must be a valid PDF (starts with %PDF).');
        } else {
            // dompdf not installed — falls back to .html (with a warning log)
            $this->assertStringEndsWith('.html', $invoice->pdf_path,
                'Invoice pdf_path should be .html when dompdf is not installed (fallback).');
        }
    }

    public function test_2co6_invoice_download_serves_correct_content_type(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();
        $invoice = Invoice::factory()->create([
            'user_id' => $user->id,
            'pdf_path' => 'invoices/2026/INV-2026-00001.pdf',
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
            'user_id' => $user->id,
            'pdf_path' => 'invoices/2026/INV-2026-00002.html', // old HTML invoice
        ]);

        Storage::disk('public')->put($invoice->pdf_path, '<html>fake invoice</html>');

        $response = $this->actingAs($user)
            ->get(route('billing.invoice', ['invoice' => $invoice->id]));

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'text/html; charset=utf-8');
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

    public function test_generating_twice_for_one_transaction_returns_the_same_invoice(): void
    {
        Storage::fake('local');

        $transaction = Transaction::factory()->create(['amount' => 29.00, 'currency' => 'USD', 'plan' => 'pro']);
        $user = User::factory()->create();
        $generator = app(InvoiceGenerator::class);

        $first = $generator->generateForTransaction($transaction, $user);
        $second = $generator->generateForTransaction($transaction, $user);

        $this->assertNotNull($first);
        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Invoice::where('transaction_id', $transaction->id)->count());
    }

    public function test_retry_completes_an_invoice_whose_pdf_was_never_written(): void
    {
        Storage::fake('local');

        $transaction = Transaction::factory()->create(['amount' => 29.00, 'currency' => 'USD', 'plan' => 'pro']);
        $user = User::factory()->create();
        $invoice = Invoice::factory()->create([
            'user_id' => $user->id,
            'transaction_id' => $transaction->id,
            'pdf_path' => null,
        ]);

        $result = app(InvoiceGenerator::class)->generateForTransaction($transaction, $user);

        $this->assertSame($invoice->id, $result->id);
        $this->assertNotEmpty($result->pdf_path);
        Storage::disk('local')->assertExists($result->pdf_path);
    }

    public function test_sequential_invoices_get_distinct_increasing_numbers(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $generator = app(InvoiceGenerator::class);

        $numbers = [];
        foreach (range(1, 3) as $ignored) {
            $transaction = Transaction::factory()->create(['amount' => 29.00, 'currency' => 'USD', 'plan' => 'pro']);
            $numbers[] = $generator->generateForTransaction($transaction, $user)->invoice_number;
        }

        $this->assertCount(3, array_unique($numbers));
        $sorted = $numbers;
        sort($sorted);
        $this->assertSame($sorted, $numbers);
    }

    public function test_regeneration_retires_the_replaced_public_file(): void
    {
        Storage::fake('local');
        Storage::fake('public');

        $user = User::factory()->create();
        $invoice = Invoice::factory()->create([
            'user_id' => $user->id,
            'pdf_path' => 'invoices/2026/INV-2026-00077.html',
        ]);
        Storage::disk('public')->put('invoices/2026/INV-2026-00077.html', '<html>old</html>');

        $newPath = app(InvoiceGenerator::class)->regeneratePdf($invoice);

        $this->assertNotNull($newPath);
        Storage::disk('local')->assertExists($newPath);
        Storage::disk('public')->assertMissing('invoices/2026/INV-2026-00077.html');
        $this->assertSame($newPath, $invoice->refresh()->pdf_path);
    }

    public function test_regeneration_keeps_the_file_when_the_path_is_unchanged(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $invoice = Invoice::factory()->create([
            'user_id' => $user->id,
            'invoice_number' => 'INV-'.now()->year.'-00088',
            'issued_at' => now(),
            'pdf_path' => 'invoices/'.now()->year.'/INV-'.now()->year.'-00088.pdf',
        ]);

        $newPath = app(InvoiceGenerator::class)->regeneratePdf($invoice);

        $this->assertSame($invoice->pdf_path, $newPath);
        Storage::disk('local')->assertExists($newPath);
    }

    public function test_download_resolves_legacy_storage_prefixed_paths(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $invoice = Invoice::factory()->create([
            'user_id' => $user->id,
            'pdf_path' => 'storage/invoices/2026/INV-2026-00099.pdf',
        ]);
        Storage::disk('local')->put('invoices/2026/INV-2026-00099.pdf', '%PDF-1.4 fake content');

        $this->actingAs($user)
            ->get(route('billing.invoice', ['invoice' => $invoice->id]))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_regenerate_command_honours_the_limit_option(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        Invoice::factory()->count(3)->create(['user_id' => $user->id, 'pdf_path' => null]);

        $this->artisan('exospace:regenerate-invoices', ['--limit' => 2])->assertExitCode(0);

        $this->assertSame(2, Invoice::whereNotNull('pdf_path')->count());
        $this->assertSame(1, Invoice::whereNull('pdf_path')->count());
    }

    public function test_2co6_regenerate_invoices_command_exists(): void
    {
        $this->assertArrayHasKey('exospace:regenerate-invoices', \Illuminate\Support\Facades\Artisan::all(),
            'exospace:regenerate-invoices command must be registered.');
    }

    public function test_session_regenerated_on_normal_registration(): void
    {
        // Session ID must change on registration
        $this->startSession();
        $sessionBefore = Session::getId();

        $this->post(route('register'), [
            'name' => 'Test User',
            'email' => 'test-cr4@example.com',
            'password' => 'TestPassword123!',
            'password_confirmation' => 'TestPassword123!',
        ]);

        $sessionAfter = Session::getId();

        $this->assertNotEquals($sessionBefore, $sessionAfter,
            'Session ID must be regenerated on normal registration to prevent session fixation.');
    }

    public function test_session_regenerated_on_invitation_acceptance(): void
    {
        $team = \App\Models\Team::factory()->create();
        $invitation = \App\Models\TeamInvitation::factory()->create([
            'team_id' => $team->id,
            'email' => 'invited-cr4@example.com',
            'token' => 'test-invitation-token-'.uniqid(),
        ]);

        $this->startSession();
        $sessionBefore = Session::getId();

        $this->post(route('register'), [
            'name' => 'Invited User',
            'email' => 'invited-cr4@example.com',
            'password' => 'TestPassword123!',
            'password_confirmation' => 'TestPassword123!',
            'invitation_token' => $invitation->token,
        ]);

        $sessionAfter = Session::getId();

        $this->assertNotEquals($sessionBefore, $sessionAfter,
            'Session ID must be regenerated on invitation acceptance to prevent session fixation.');
    }
}
