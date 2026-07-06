<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * M-11: VAT/TAX handling service.
 *
 * Determines the applicable tax rate based on the customer's location
 * (GeoIP from request IP) + the business's tax nexus. Uses a simple
 * static rate table — for production, integrate with a tax service
 * like TaxJar, Avalara, or Stripe Tax for accurate real-time rates.
 *
 * Tax rules (EU VAT as primary use case):
 *   - EU B2C: charge VAT based on customer's country (destination rule)
 *   - EU B2B: reverse charge (0% VAT) if valid VAT number provided
 *   - Non-EU: no VAT charged (0%)
 *
 * The InvoiceGenerator uses this service to set tax_amount + tax_rate
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
     * Determine the tax rate for a transaction.
     *
     * @param  Request     $request   The HTTP request (for GeoIP)
     * @param  string|null $countryCode  Override the country code (e.g. from billing address)
     * @param  string|null $vatNumber  EU VAT number for B2B reverse charge
     * @return array  { 'rate' => float, 'amount' => float, 'country' => string, 'is_eu' => bool, 'is_reverse_charge' => bool }
     */
    public function calculateTax(Request $request, float $amount, ?string $countryCode = null, ?string $vatNumber = null): array
    {
        // Determine customer's country
        $country = $countryCode ?? $this->detectCountry($request);
        $isEu = in_array($country, self::EU_COUNTRIES, true);

        // Non-EU: no VAT
        if (! $isEu) {
            return [
                'rate'              => 0.0,
                'amount'            => 0.00,
                'country'           => $country,
                'is_eu'             => false,
                'is_reverse_charge' => false,
            ];
        }

        // EU B2B with valid VAT number: reverse charge (0%)
        if ($vatNumber && $this->validateVatNumberFormat($vatNumber, $country)) {
            return [
                'rate'              => 0.0,
                'amount'            => 0.00,
                'country'           => $country,
                'is_eu'             => true,
                'is_reverse_charge' => true,
            ];
        }

        // EU B2C: charge VAT based on customer's country
        $rate = self::EU_VAT_RATES[$country] ?? 0.0;
        $taxAmount = round($amount * $rate / 100, 2);

        return [
            'rate'              => $rate,
            'amount'            => $taxAmount,
            'country'           => $country,
            'is_eu'             => true,
            'is_reverse_charge' => false,
        ];
    }

    /**
     * Detect the customer's country from their IP address.
     * Uses a simple GeoIP lookup. For production, use the `geoip` package
     * or MaxMind's GeoIP2 database.
     */
    private function detectCountry(Request $request): string
    {
        // Try Cloudflare's CF-IPCountry header (if behind Cloudflare)
        $cfCountry = $request->header('CF-IPCountry');
        if ($cfCountry && $cfCountry !== 'XX') {
            return strtoupper($cfCountry);
        }

        // Fallback: use the app's configured country (default: US)
        // A production deployment should install the `stevebauman/location`
        // or `torann/geoip` package for IP-based country detection.
        return config('app.tax_default_country', 'US');
    }

    /**
     * Basic VAT number format validation.
     * Full validation requires the EU VIES API — this is a format check only.
     */
    private function validateVatNumberFormat(string $vatNumber, string $countryCode): bool
    {
        $vatNumber = preg_replace('/[^A-Z0-9]/', '', strtoupper($vatNumber));
        $countryCode = strtoupper($countryCode);

        // VAT numbers start with the country code (2 letters) + 8-12 digits
        if (str_starts_with($vatNumber, $countryCode)) {
            $vatNumber = substr($vatNumber, 2);
        }

        return strlen($vatNumber) >= 8 && strlen($vatNumber) <= 12 && ctype_alnum($vatNumber);
    }

    /**
     * Get the list of EU countries + their VAT rates (for display).
     */
    public static function euVatRates(): array
    {
        return self::EU_VAT_RATES;
    }
}
