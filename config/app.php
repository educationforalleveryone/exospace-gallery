<?php

return [

    'name' => env('APP_NAME', 'Laravel'),

    'env' => env('APP_ENV', 'production'),

    'debug' => (bool) env('APP_DEBUG', false),

    'url' => env('APP_URL', 'http://localhost'),

    'metrics_token' => env('METRICS_TOKEN'),

    'business_address' => address_lines(env('EXOSPACE_BUSINESS_ADDRESS')),

    // Name shown on legal pages and the footer. Use the exact legal name of
    // whoever operates the service (a trading name such as "Exospace Gallery"
    // until a company is formed; add "LLC"/"Ltd." only once one exists).
    'legal_name' => env('EXOSPACE_LEGAL_NAME', 'Exospace Gallery'),

    // Optional public support phone. Hidden when empty.
    'support_phone' => env('EXOSPACE_SUPPORT_PHONE'),

    // Terms of Service §13. Update both when a US entity is formed.
    'governing_law' => env('EXOSPACE_GOVERNING_LAW', 'the laws of the Islamic Republic of Pakistan'),
    'governing_venue' => env('EXOSPACE_GOVERNING_VENUE', 'the courts of Islamabad, Pakistan'),

    'supplier_vat_number' => env('EXOSPACE_SUPPLIER_VAT_NUMBER'),
    'supplier_country' => env('EXOSPACE_SUPPLIER_COUNTRY', 'US'),

    'tax_default_country' => env('EXOSPACE_TAX_DEFAULT_COUNTRY', 'US'),

    'timezone' => 'UTC',

    'locale' => env('APP_LOCALE', 'en'),

    'fallback_locale' => env('APP_FALLBACK_LOCALE', 'en'),

    'faker_locale' => env('APP_FAKER_LOCALE', 'en_US'),

    'cipher' => 'AES-256-CBC',

    'key' => env('APP_KEY'),

    'previous_keys' => [
        ...array_filter(
            explode(',', (string) env('APP_PREVIOUS_KEYS', ''))
        ),
    ],

    'maintenance' => [
        'driver' => env('APP_MAINTENANCE_DRIVER', 'file'),
        'store' => env('APP_MAINTENANCE_STORE', 'database'),
    ],

];
