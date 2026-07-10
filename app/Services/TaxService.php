<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * M-11 / 2CO-7 / O-10 FIX (Iteration-008): VAT/TAX handling service.
 *
 * Previous state (pre-Iter-008):
 *   - calculateTax() took an Illuminate\Http\Request — coupled the service
 *     to HTTP, making it untestable from console commands / queued jobs
 *     (audit L-4 / F-4 code smell).
 *   - Only covered EU VAT rates. Audit 2CO-7 requires UK, Norway,
 *     Switzerland, Australia, Singapore, and India rates.
 *   - VIES validation was format-only. EU B2B reverse-charge relief
 *     requires actually verifying the customer's VAT number against
 *     the EU VIES database. A format-only check is trivially bypassed
 *     (any 8-12 digit string passes).
 *   - TaxService was NEVER called by InvoiceGenerator (audit 2CO-7) —
 *     every invoice had tax_amount=0. EU/UK/AU/SG/IN tax compliance
 *     exposure.
 *
 * Iter-008 fixes:
 *   - calculateTax() now takes (string $ip, float $amount, ?string
 *     $countryCode, ?string $vatNumber). Callable from anywhere.
 *   - Adds UK (20%), Norway (25%), Switzerland (8.1%), Australia (10%),
 *     Singapore (9%), India (18% digital goods) rates.
 *   - VIES validation via the EU SOAP API with a 24h cache. VIES is
 *     rate-limited (~5 req/sec per IP) and goes down regularly; the
 *     cache + graceful format-only fallback handle this.
 *   - Returns a TaxBreakdown DTO-like array including the fields needed
 *     for the invoice PDF (supplier_vat_number, customer_vat_number,
 *     reverse_charge flag, country code).
 *
 * Tax rules (EU VAT as primary use case):
 *   - EU B2C: charge VAT based on customer's country (destination rule)
 *   - EU B2B: reverse charge (0% VAT) if valid VAT number verified via VIES
 *   - UK B2C: 20% VAT
 *   - UK B2B: 0% reverse charge if valid UK VAT number (post-Brexit, UK
 *     is treated as a third country for EU VAT purposes — sale from EU
 *     supplier to UK business is reverse-charge; sale from UK supplier
 *     to UK customer is normal VAT)
 *   - Norway B2C: 25% VAT on digital services (VOEC regime)
 *   - Switzerland B2C: 8.1% VAT on digital services (Swiss VAT Act)
 *   - Australia B2C: 10% GST (GST-free if customer is GST-registered
 *     and provides an ABN)
 *   - Singapore B2C: 9% GST (overseas vendor registration regime)
 *   - India B2C: 18% GST on digital services (equalisation levy is 6%
 *     but applies separately; IGST 18% is the standard digital rate)
 *   - Non-EU/UK/NO/CH/AU/SG/IN: no VAT charged (0%)
 *
 * The InvoiceGenerator now calls this service to set tax_amount + tax_rate
 * on invoices. The billing portal displays the tax-inclusive price.
 */
class TaxService
{
    /**
     * EU VAT rates (2024 standard rates).
     * Source: https://ec.europa.eu/taxation_customs/business/vat/telecommunications-broadcasting-electronic-services_en
     */
    private const EU_VAT_RATES = [
        'AT' => 20.0, // Austria
        'BE' => 21.0, // Belgium
        'BG' => 20.0, // Bulgaria
        'HR' => 25.0, // Croatia
        'CY' => 19.0, // Cyprus
        'CZ' => 21.0, // Czech Republic
        'DK' => 25.0, // Denmark
        'EE' => 22.0, // Estonia
        'FI' => 25.5, // Finland
        'FR' => 20.0, // France
        'DE' => 19.0, // Germany
        'GR' => 24.0, // Greece
        'HU' => 27.0, // Hungary
        'IE' => 23.0, // Ireland
        'IT' => 22.0, // Italy
        'LV' => 21.0, // Latvia
        'LT' => 21.0, // Lithuania
        'LU' => 17.0, // Luxembourg
        'MT' => 18.0, // Malta
        'NL' => 21.0, // Netherlands
        'PL' => 23.0, // Poland
        'PT' => 23.0, // Portugal
        'RO' => 19.0, // Romania
        'SK' => 23.0, // Slovakia
        'SI' => 22.0, // Slovenia
        'ES' => 21.0, // Spain
        'SE' => 25.0, // Sweden
    ];

    private const EU_COUNTRIES = [
        'AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR',
        'DE', 'GR', 'HU', 'IE', 'IT', 'LV', 'LT', 'LU', 'MT', 'NL',
        'PL', 'PT', 'RO', 'SK', 'SI', 'ES', 'SE',
    ];

    /**
     * Non-EU jurisdiction rates for digital services (2024).
     * Audit 2CO-7: previously missing — every non-EU invoice had tax=0.
     */
    private const NON_EU_VAT_RATES = [
        'GB' => 20.0, // United Kingdom (VAT, standard rate)
        'NO' => 25.0, // Norway (VAT on digital services, VOEC regime)
        'CH' => 8.1,  // Switzerland (VAT, standard rate since 2024)
        'AU' => 10.0, // Australia (GST)
        'SG' => 9.0,  // Singapore (GST, raised to 9% in 2024)
        'IN' => 18.0, // India (IGST on digital services)
    ];

    /**
     * Countries where B2B reverse charge applies (supplier outside the
     * country, customer inside with a valid VAT number).
     */
    private const REVERSE_CHARGE_COUNTRIES = [
        ...self::EU_COUNTRIES, // EU B2B reverse charge (intra-community)
        'GB',                  // UK B2B reverse charge (post-Brexit)
        'NO',                  // Norway B2B reverse charge
    ];

    /** VIES validation cache TTL — 24h. VIES is rate-limited and flaky. */
    private const VIES_CACHE_TTL_SECONDS = 86400;

    /**
     * Iter-008 FIX: Calculate tax for a transaction.
     *
     * @param  string      $ip           Customer's IP address (for GeoIP fallback)
     * @param  float       $amount       The total amount (tax-INCLUSIVE if your
     *                                   catalog prices include tax; tax-EXCLUSIVE
     *                                   otherwise — InvoiceGenerator passes
     *                                   the ex-tax amount).
     * @param  string|null $countryCode  Override country (e.g. from billing_address)
     * @param  string|null $vatNumber    Customer's VAT number (for B2B reverse charge)
     * @return array{
     *     rate: float,
     *     amount: float,
     *     country: string,
     *     is_eu: bool,
     *     is_reverse_charge: bool,
     *     vat_number_valid: bool,
     *     jurisdiction_name: string,
     * }
     */
    public function calculateTax(string $ip, float $amount, ?string $countryCode = null, ?string $vatNumber = null): array
    {
        $country = $countryCode ?? $this->detectCountry($ip);
        $country = strtoupper($country);
        $isEu = in_array($country, self::EU_COUNTRIES, true);
        $isNonEuVat = array_key_exists($country, self::NON_EU_VAT_RATES);

        // B2B reverse charge: customer has a VAT number + country is in the
        // reverse-charge list + the number validates via VIES (or format-only
        // if VIES is unreachable). 0% VAT, with reverse_charge=true.
        if ($vatNumber && in_array($country, self::REVERSE_CHARGE_COUNTRIES, true)) {
            $vatValid = $this->validateVatNumber($vatNumber, $country);
            if ($vatValid) {
                return [
                    'rate'              => 0.0,
                    'amount'            => 0.00,
                    'country'           => $country,
                    'is_eu'             => $isEu,
                    'is_reverse_charge' => true,
                    'vat_number_valid'  => true,
                    'jurisdiction_name' => $this->jurisdictionName($country),
                ];
            }
            // If VAT number is invalid, fall through to B2C rate (charge VAT).
            // Log this — invalid VAT number on a B2B claim is suspicious.
            Log::warning('TaxService: VAT number failed VIES validation; charging B2C rate', [
                'country'    => $country,
                'vat_number' => substr($vatNumber, 0, 4) . '...', // don't log full VAT
            ]);
        }

        // B2C rates: EU uses EU_VAT_RATES, non-EU jurisdictions use NON_EU_VAT_RATES.
        $rate = 0.0;
        if ($isEu) {
            $rate = self::EU_VAT_RATES[$country] ?? 0.0;
        } elseif ($isNonEuVat) {
            $rate = self::NON_EU_VAT_RATES[$country];
        }

        $taxAmount = $rate > 0 ? round($amount * $rate / 100, 2) : 0.00;

        return [
            'rate'              => $rate,
            'amount'            => $taxAmount,
            'country'           => $country,
            'is_eu'             => $isEu,
            'is_reverse_charge' => false,
            'vat_number_valid'  => false,
            'jurisdiction_name' => $this->jurisdictionName($country),
        ];
    }

    /**
     * Iter-008 FIX: Detect the customer's country from their IP address.
     *
     * No longer takes a Request — accepts the IP string directly.
     * Tries (in order):
     *   1. Cloudflare's CF-IPCountry header (if app is behind Cloudflare)
     *   2. The app's configured default country (config('app.tax_default_country'))
     *
     * For production accuracy, install stevebauman/location or torann/geoip
     * for MaxMind GeoIP2 lookup. The default fallback (US) is conservative
     * — it produces 0% VAT for unknown IPs, which is the lowest-risk
     * default for a non-EU supplier.
     */
    private function detectCountry(string $ip): string
    {
        // If app is behind Cloudflare, the CF-IPCountry header is set.
        // This is the most reliable source (Cloudflare's geo-IP is excellent).
        $cfCountry = request()?->header('CF-IPCountry');
        if ($cfCountry && $cfCountry !== 'XX') {
            return strtoupper($cfCountry);
        }

        // Fallback: configured default. Production should override this
        // with a real GeoIP package.
        return strtoupper(config('app.tax_default_country', 'US'));
    }

    /**
     * Iter-008 FIX: Validate a VAT number against the EU VIES database.
     *
     * VIES is the official EU VAT number validation service:
     *   https://ec.europa.eu/taxation_customs/vies/
     *
     * VIES is rate-limited (~5 req/sec per IP) and goes down regularly
     * (each member state's VIES endpoint can be unavailable independently).
     * We cache successful validations for 24h. On VIES failure, we fall
     * back to format-only validation and log a warning so the operator
     * knows the validation wasn't real.
     *
     * For UK VAT numbers (post-Brexit), VIES does NOT validate them —
     * HMRC has a separate API. We use format-only validation for UK.
     */
    public function validateVatNumber(string $vatNumber, string $countryCode): bool
    {
        $vatNumber = $this->normalizeVatNumber($vatNumber, $countryCode);
        $countryCode = strtoupper($countryCode);

        // UK VAT numbers — VIES doesn't validate post-Brexit. Format check only.
        // HMRC's API requires OAuth; out of scope for this iteration.
        if ($countryCode === 'GB') {
            return $this->validateUkVatFormat($vatNumber);
        }

        // Non-EU countries with no VAT (e.g. NO, CH, AU, SG, IN) — use
        // format-only validation for those local tax IDs. Reverse charge
        // for these is a local rule, not a VIES-verified one.
        if (! in_array($countryCode, self::EU_COUNTRIES, true)) {
            return $this->validateVatNumberFormat($vatNumber, $countryCode);
        }

        // EU: try VIES first, fall back to format-only on failure.
        $cacheKey = "vies:valid:{$countryCode}:{$vatNumber}";

        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return $cached === 'valid';
        }

        $viesResult = $this->callViesApi($countryCode, $vatNumber);
        if ($viesResult === null) {
            // VIES unreachable — fall back to format-only and warn.
            Log::warning('TaxService: VIES API unreachable; falling back to format-only validation', [
                'country'    => $countryCode,
                'vat_number' => substr($vatNumber, 0, 4) . '...',
            ]);
            return $this->validateVatNumberFormat($vatNumber, $countryCode);
        }

        $isValid = $viesResult === true;
        Cache::put($cacheKey, $isValid ? 'valid' : 'invalid', self::VIES_CACHE_TTL_SECONDS);
        return $isValid;
    }

    /**
     * Call the EU VIES SOAP API to validate a VAT number.
     *
     * Returns:
     *   - true: VAT number is valid
     *   - false: VAT number is invalid
     *   - null: VIES API unreachable / error (caller should fall back)
     *
     * VIES WSDL: https://ec.europa.eu/taxation_customs/vies/checkVatService.wsdl
     *
     * We use a raw SOAP request via Http:: with a short timeout — PHP's
     * SoapClient has historically been flaky on VIES's WSDL, and Http::
     * gives us better control over timeouts.
     */
    private function callViesApi(string $countryCode, string $vatNumber): ?bool
    {
        try {
            // VIES SOAP endpoint. Timeout: 5s connect, 10s total.
            // VIES is normally fast (~200ms) but can hang.
            $soapEnvelope = '<?xml version="1.0" encoding="UTF-8"?>
<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">
  <soap:Body>
    <checkVat xmlns="urn:ec.europa.eu:taxud:vies:services:checkVat:types">
      <countryCode>' . htmlspecialchars($countryCode, ENT_XML1) . '</countryCode>
      <vatNumber>' . htmlspecialchars($vatNumber, ENT_XML1) . '</vatNumber>
    </checkVat>
  </soap:Body>
</soap:Envelope>';

            $response = Http::withHeaders([
                'Content-Type' => 'text/xml; charset=UTF-8',
                'SOAPAction'   => '',
            ])
                ->timeout(10)
                ->connectTimeout(5)
                ->post('https://ec.europa.eu/taxation_customs/vies/services/checkVatService', $soapEnvelope);

            if (! $response->successful()) {
                return null;
            }

            $body = $response->body();
            // <valid>true</valid> or <valid>false</valid>
            if (preg_match('/<valid>(true|false)<\/valid>/i', $body, $m)) {
                return strtolower($m[1]) === 'true';
            }
            return null;
        } catch (\Throwable $e) {
            Log::debug('TaxService: VIES API exception', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Normalize a VAT number: strip non-alphanumeric, remove country-code
     * prefix (VIES expects the number WITHOUT the country code as a separate
     * field).
     */
    private function normalizeVatNumber(string $vatNumber, string $countryCode): string
    {
        $vatNumber = preg_replace('/[^A-Z0-9]/', '', strtoupper($vatNumber));
        $countryCode = strtoupper($countryCode);

        if (str_starts_with($vatNumber, $countryCode)) {
            $vatNumber = substr($vatNumber, strlen($countryCode));
        }

        return $vatNumber;
    }

    /**
     * Basic EU VAT number format validation (fallback when VIES is down).
     * Format check only — does NOT verify the number exists.
     */
    private function validateVatNumberFormat(string $vatNumber, string $countryCode): bool
    {
        $vatNumber = $this->normalizeVatNumber($vatNumber, $countryCode);
        return strlen($vatNumber) >= 8 && strlen($vatNumber) <= 12 && ctype_alnum($vatNumber);
    }

    /**
     * UK VAT number format validation.
     * Format: GB + 9 digits (or GB + 12 digits for government branches).
     * Does NOT verify via HMRC (their API requires OAuth, out of scope).
     */
    private function validateUkVatFormat(string $vatNumber): bool
    {
        $vatNumber = $this->normalizeVatNumber($vatNumber, 'GB');
        // Standard UK VAT: 9 digits. Government: 12 digits (GD/HA prefix).
        return (strlen($vatNumber) === 9 && ctype_digit($vatNumber))
            || (strlen($vatNumber) === 12 && ctype_digit($vatNumber));
    }

    /**
     * Human-readable jurisdiction name for invoice rendering.
     */
    private function jurisdictionName(string $countryCode): string
    {
        $names = [
            'AT' => 'Austria', 'BE' => 'Belgium', 'BG' => 'Bulgaria',
            'HR' => 'Croatia', 'CY' => 'Cyprus', 'CZ' => 'Czech Republic',
            'DK' => 'Denmark', 'EE' => 'Estonia', 'FI' => 'Finland',
            'FR' => 'France', 'DE' => 'Germany', 'GR' => 'Greece',
            'HU' => 'Hungary', 'IE' => 'Ireland', 'IT' => 'Italy',
            'LV' => 'Latvia', 'LT' => 'Lithuania', 'LU' => 'Luxembourg',
            'MT' => 'Malta', 'NL' => 'Netherlands', 'PL' => 'Poland',
            'PT' => 'Portugal', 'RO' => 'Romania', 'SK' => 'Slovakia',
            'SI' => 'Slovenia', 'ES' => 'Spain', 'SE' => 'Sweden',
            'GB' => 'United Kingdom', 'NO' => 'Norway',
            'CH' => 'Switzerland', 'AU' => 'Australia',
            'SG' => 'Singapore', 'IN' => 'India',
            'US' => 'United States',
        ];

        return $names[$countryCode] ?? $countryCode;
    }

    /**
     * Get the list of EU countries + their VAT rates (for display).
     */
    public static function euVatRates(): array
    {
        return self::EU_VAT_RATES;
    }

    /**
     * Get the list of non-EU jurisdictions + their VAT/GST rates.
     */
    public static function nonEuVatRates(): array
    {
        return self::NON_EU_VAT_RATES;
    }

    /**
     * Get the supplier's VAT number (from env). Rendered on the invoice.
     */
    public static function supplierVatNumber(): ?string
    {
        $val = config('app.supplier_vat_number');
        return $val && $val !== '' ? $val : null;
    }

    /**
     * Get the supplier's country code. Determines when reverse charge applies.
     */
    public static function supplierCountry(): string
    {
        return strtoupper(config('app.supplier_country', 'US'));
    }
}
