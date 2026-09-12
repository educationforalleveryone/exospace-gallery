<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Transaction;
use App\Models\User;
use App\Services\InvoiceGenerator;
use App\Services\TaxService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TaxComplianceTest extends TestCase
{
    use RefreshDatabase;

    private TaxService $tax;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tax = app(TaxService::class);
        Cache::flush();
        Http::preventStrayRequests();
    }

    public function tax_service_accepts_ip_string_instead_of_request(): void
    {
        // L-4 / F-4 fix: TaxService must not depend on Illuminate\Http\Request.
        $reflection = new \ReflectionMethod(TaxService::class, 'calculateTax');
        $params = $reflection->getParameters();

        $this->assertSame('ip', $params[0]->getName());
        $this->assertSame('string', (string) $params[0]->getType());

        // No parameter should be typed as Request.
        foreach ($params as $p) {
            $type = (string) $p->getType();
            $this->assertStringNotContainsString('Request', $type, "TaxService should not depend on Request (param: {$p->getName()})");
        }
    }

    public function eu_b2c_charges_vat_based_on_customer_country(): void
    {
        $result = $this->tax->calculateTax('1.2.3.4', 100.00, 'DE', null);

        $this->assertSame(19.0, $result['rate']);
        $this->assertSame(19.00, $result['amount']);
        $this->assertSame('DE', $result['country']);
        $this->assertTrue($result['is_eu']);
        $this->assertFalse($result['is_reverse_charge']);
    }

    public function eu_b2b_reverse_charge_when_vies_validates_vat_number(): void
    {
        // Mock VIES API response: valid.
        Http::fake([
            'ec.europa.eu/*' => Http::response(
                '<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Body><checkVatResponse xmlns="urn:ec.europa.eu:taxud:vies:services:checkVat:types"><valid>true</valid></checkVatResponse></soap:Body></soap:Envelope>',
                200,
                ['Content-Type' => 'text/xml']
            ),
        ]);

        $result = $this->tax->calculateTax('1.2.3.4', 100.00, 'DE', 'DE123456789');

        $this->assertSame(0.0, $result['rate']);
        $this->assertSame(0.00, $result['amount']);
        $this->assertTrue($result['is_reverse_charge']);
        $this->assertTrue($result['vat_number_valid']);
    }

    public function eu_b2b_charges_vat_when_vies_invalidates_vat_number(): void
    {
        Http::fake([
            'ec.europa.eu/*' => Http::response(
                '<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Body><checkVatResponse xmlns="urn:ec.europa.eu:taxud:vies:services:checkVat:types"><valid>false</valid></checkVatResponse></soap:Body></soap:Envelope>',
                200,
                ['Content-Type' => 'text/xml']
            ),
        ]);

        $result = $this->tax->calculateTax('1.2.3.4', 100.00, 'DE', 'DE000000000');

        $this->assertSame(19.0, $result['rate']); // falls back to B2C
        $this->assertFalse($result['is_reverse_charge']);
        $this->assertFalse($result['vat_number_valid']);
    }

    public function vies_results_are_cached_for_24_hours(): void
    {
        $callCount = 0;
        Http::fake([
            'ec.europa.eu/*' => function () use (&$callCount) {
                $callCount++;
                return Http::response(
                    '<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Body><checkVatResponse xmlns="urn:ec.europa.eu:taxud:vies:services:checkVat:types"><valid>true</valid></checkVatResponse></soap:Body></soap:Envelope>',
                    200,
                    ['Content-Type' => 'text/xml']
                );
            },
        ]);

        $this->tax->calculateTax('1.2.3.4', 100.00, 'FR', 'FR12345678901');
        $this->tax->calculateTax('1.2.3.4', 100.00, 'FR', 'FR12345678901');
        $this->tax->calculateTax('1.2.3.4', 100.00, 'FR', 'FR12345678901');

        $this->assertSame(1, $callCount, 'VIES should be hit only once — subsequent validations come from cache');
    }

    public function vies_unreachable_falls_back_to_format_only(): void
    {
        // VIES returns 500.
        Http::fake([
            'ec.europa.eu/*' => Http::response('Server Error', 500),
        ]);

        // A format-valid EU VAT number should pass (format-only fallback).
        $result = $this->tax->calculateTax('1.2.3.4', 100.00, 'DE', 'DE123456789');
        $this->assertTrue($result['is_reverse_charge'], 'Format-valid VAT should pass on VIES outage');

        // A format-invalid VAT should fall through to B2C rate.
        $result2 = $this->tax->calculateTax('1.2.3.4', 100.00, 'DE', 'DE1');
        $this->assertFalse($result2['is_reverse_charge'], 'Format-invalid VAT should not trigger reverse charge');
        $this->assertSame(19.0, $result2['rate']);
    }

    public function uk_b2c_charges_20_percent_vat(): void
    {
        $result = $this->tax->calculateTax('1.2.3.4', 100.00, 'GB', null);
        $this->assertSame(20.0, $result['rate']);
        $this->assertSame(20.00, $result['amount']);
        $this->assertFalse($result['is_eu']);
    }

    public function uk_b2b_reverse_charge_with_valid_uk_vat_format(): void
    {
        $result = $this->tax->calculateTax('1.2.3.4', 100.00, 'GB', 'GB123456789');

        $this->assertSame(0.0, $result['rate']);
        $this->assertTrue($result['is_reverse_charge']);
    }

    public function uk_b2b_charges_vat_when_vat_format_invalid(): void
    {
        // UK VAT must be 9 or 12 digits.
        $result = $this->tax->calculateTax('1.2.3.4', 100.00, 'GB', 'GB123');

        $this->assertSame(20.0, $result['rate']);
        $this->assertFalse($result['is_reverse_charge']);
    }

    public function australia_b2c_charges_10_percent_gst(): void
    {
        $result = $this->tax->calculateTax('1.2.3.4', 100.00, 'AU', null);
        $this->assertSame(10.0, $result['rate']);
        $this->assertSame(10.00, $result['amount']);
    }

    public function singapore_b2c_charges_9_percent_gst(): void
    {
        $result = $this->tax->calculateTax('1.2.3.4', 100.00, 'SG', null);
        $this->assertSame(9.0, $result['rate']);
    }

    public function india_b2c_charges_18_percent_igst(): void
    {
        $result = $this->tax->calculateTax('1.2.3.4', 100.00, 'IN', null);
        $this->assertSame(18.0, $result['rate']);
    }

    public function non_vat_country_charges_zero_tax(): void
    {
        $result = $this->tax->calculateTax('1.2.3.4', 100.00, 'US', null);
        $this->assertSame(0.0, $result['rate']);
        $this->assertSame(0.00, $result['amount']);
        $this->assertFalse($result['is_eu']);
        $this->assertFalse($result['is_reverse_charge']);
    }

    public function invoice_generator_now_uses_tax_service_for_eu_customers(): void
    {
        // Audit 2CO-7 critical fix: previously every invoice had tax_amount=0.
        Storage::fake('public');

        $user = User::factory()->create();
        $transaction = Transaction::factory()->create([
            'user_id'    => $user->id,
            'amount'     => 99.00,
            'currency'   => 'USD',
            'plan'       => 'studio',
        ]);

        $generator = app(InvoiceGenerator::class);
        $invoice = $generator->generateForTransaction($transaction, $user, [
            'customer_country' => 'DE', // 19% VAT
        ]);

        $this->assertNotNull($invoice);
        $this->assertSame(19.0, (float) $invoice->tax_rate, 'German customer should be charged 19% VAT');
        $this->assertSame(18.81, (float) $invoice->tax_amount, '99.00 * 0.19 = 18.81');
        $this->assertSame('DE', $invoice->tax_country_code);
        $this->assertFalse((bool) $invoice->reverse_charge);
    }

    public function invoice_generator_handles_eu_b2b_reverse_charge(): void
    {
        Storage::fake('public');

        Http::fake([
            'ec.europa.eu/*' => Http::response(
                '<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Body><checkVatResponse xmlns="urn:ec.europa.eu:taxud:vies:services:checkVat:types"><valid>true</valid></checkVatResponse></soap:Body></soap:Envelope>',
                200,
                ['Content-Type' => 'text/xml']
            ),
        ]);

        $user = User::factory()->create();
        $transaction = Transaction::factory()->create([
            'user_id'  => $user->id,
            'amount'   => 299.00,
            'currency' => 'USD',
            'plan'     => 'studio',
        ]);

        $generator = app(InvoiceGenerator::class);
        $invoice = $generator->generateForTransaction($transaction, $user, [
            'customer_country'      => 'FR',
            'customer_vat_number'   => 'FR12345678901',
        ]);

        $this->assertNotNull($invoice);
        $this->assertSame(0.0, (float) $invoice->tax_rate);
        $this->assertSame(0.00, (float) $invoice->tax_amount);
        $this->assertTrue((bool) $invoice->reverse_charge);
        $this->assertSame('FR12345678901', $invoice->customer_vat_number);
    }

    public function invoice_supplier_vat_number_snapshot_stored_when_configured(): void
    {
        config(['app.supplier_vat_number' => 'GB999999999']);
        config(['app.supplier_country' => 'GB']);

        Storage::fake('public');

        $user = User::factory()->create();
        $transaction = Transaction::factory()->create([
            'user_id' => $user->id,
            'amount'  => 50.00,
            'plan'    => 'pro',
        ]);

        $generator = app(InvoiceGenerator::class);
        $invoice = $generator->generateForTransaction($transaction, $user, [
            'customer_country' => 'US',
        ]);

        $this->assertSame('GB999999999', $invoice->supplier_vat_number);
    }

    public function invoice_pdf_renders_vat_fields_for_b2b_eu_reverse_charge(): void
    {
        Http::fake([
            'ec.europa.eu/*' => Http::response(
                '<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Body><checkVatResponse xmlns="urn:ec.europa.eu:taxud:vies:services:checkVat:types"><valid>true</valid></checkVatResponse></soap:Body></soap:Envelope>',
                200,
                ['Content-Type' => 'text/xml']
            ),
        ]);

        $invoice = Invoice::factory()->create([
            'customer_name'       => 'Acme GmbH',
            'customer_email'      => 'billing@acme.de',
            'customer_vat_number' => 'DE123456789',
            'supplier_vat_number' => 'GB999999999',
            'tax_country_code'    => 'DE',
            'tax_rate'            => 0.0,
            'tax_amount'          => 0.00,
            'reverse_charge'      => true,
            'amount'              => 99.00,
        ]);

        $rendered = view('invoices.pdf', ['invoice' => $invoice])->render();

        $this->assertStringContainsString('VAT/Tax ID: DE123456789', $rendered);
        $this->assertStringContainsString('Supplier VAT: GB999999999', $rendered);
        $this->assertStringContainsString('Reverse charge', $rendered);
        $this->assertStringContainsString('VAT accounted for by customer', $rendered);
        $this->assertStringContainsString('Article 194', $rendered); // EU directive reference
    }

    public function invoice_pdf_renders_tax_line_for_b2c_vat_charged(): void
    {
        $invoice = Invoice::factory()->create([
            'customer_name'    => 'Jane Doe',
            'customer_email'   => 'jane@example.de',
            'tax_country_code' => 'DE',
            'tax_rate'         => 19.0,
            'tax_amount'       => 18.81,
            'reverse_charge'   => false,
            'amount'           => 117.81,
        ]);

        $rendered = view('invoices.pdf', ['invoice' => $invoice])->render();

        $this->assertStringContainsString('Tax (19% · DE)', $rendered);
        $this->assertStringNotContainsString('Reverse charge', $rendered);
    }

    public function invoice_pdf_hides_tax_block_when_no_tax_and_no_reverse_charge(): void
    {
        $invoice = Invoice::factory()->create([
            'tax_country_code' => null,
            'tax_rate'         => 0.0,
            'tax_amount'       => 0.00,
            'reverse_charge'   => false,
            'amount'           => 99.00,
        ]);

        $rendered = view('invoices.pdf', ['invoice' => $invoice])->render();

        $this->assertStringNotContainsString('Tax (0%', $rendered, 'Should not show "Tax (0%)" line');
        $this->assertStringNotContainsString('Reverse charge', $rendered);
    }

    public function invoices_table_has_vat_columns(): void
    {
        $this->assertTrue(\Schema::hasColumn('invoices', 'customer_vat_number'));
        $this->assertTrue(\Schema::hasColumn('invoices', 'supplier_vat_number'));
        $this->assertTrue(\Schema::hasColumn('invoices', 'tax_country_code'));
        $this->assertTrue(\Schema::hasColumn('invoices', 'reverse_charge'));
    }
}
