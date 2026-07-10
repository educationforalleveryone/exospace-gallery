<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Application Name
    |--------------------------------------------------------------------------
    |
    | This value is the name of your application, which will be used when the
    | framework needs to place the application's name in a notification or
    | other UI elements where an application name needs to be displayed.
    |
    */

    'name' => env('APP_NAME', 'Laravel'),

    /*
    |--------------------------------------------------------------------------
    | Application Environment
    |--------------------------------------------------------------------------
    |
    | This value determines the "environment" your application is currently
    | running in. This may determine how you prefer to configure various
    | services the application utilizes. Set this in your ".env" file.
    |
    */

    'env' => env('APP_ENV', 'production'),

    /*
    |--------------------------------------------------------------------------
    | Application Debug Mode
    |--------------------------------------------------------------------------
    |
    | When your application is in debug mode, detailed error messages with
    | stack traces will be shown on every error that occurs within your
    | application. If disabled, a simple generic error page is shown.
    |
    */

    'debug' => (bool) env('APP_DEBUG', false),

    /*
    |--------------------------------------------------------------------------
    | Application URL
    |--------------------------------------------------------------------------
    |
    | This URL is used by the console to properly generate URLs when using
    | the Artisan command line tool. You should set this to the root of
    | the application so that it's available within Artisan commands.
    |
    */

    'url' => env('APP_URL', 'http://localhost'),

    /*
    |--------------------------------------------------------------------------
    | Business Address (CAN-SPAM §316.2 compliance)
    |--------------------------------------------------------------------------
    |
    | CAN-SPAM requires a valid physical postal address in every commercial
    | email. This value is rendered in the footer of abandoned-cart,
    | inactive-nudge, and plan-expiring emails. Set EXOSPACE_BUSINESS_ADDRESS
    | in .env to your business postal address (multi-line with \n).
    |
    */

    'business_address' => env('EXOSPACE_BUSINESS_ADDRESS'),

    /*
    |--------------------------------------------------------------------------
    | Supplier VAT Number + Country (Iter-008 / audit 2CO-7 + O-10)
    |--------------------------------------------------------------------------
    |
    | The supplier's own VAT/GST/Tax ID. Rendered on every invoice PDF.
    | Used by TaxService to determine reverse-charge applicability:
    | if the supplier is in the EU and the customer is in another EU
    | country (B2B), reverse charge applies. If the supplier is outside
    | the EU (e.g. US), reverse charge never applies for EU customers.
    |
    | Set EXOSPACE_SUPPLIER_VAT_NUMBER to your registered VAT/GST ID.
    | Set EXOSPACE_SUPPLIER_COUNTRY to your 2-letter ISO country code
    | (default 'US'). For a UK supplier: GB + GB VAT number. For an
    | EU supplier: e.g. DE + DE VAT number.
    |
    | Leave both empty if you have no VAT registration — invoices will
    | be issued with 0% tax and no supplier VAT number.
    |
    */

    'supplier_vat_number' => env('EXOSPACE_SUPPLIER_VAT_NUMBER'),
    'supplier_country'    => env('EXOSPACE_SUPPLIER_COUNTRY', 'US'),

    /*
    |--------------------------------------------------------------------------
    | Tax Default Country (Iter-008 / audit 2CO-7)
    |--------------------------------------------------------------------------
    |
    | Fallback country code when GeoIP cannot determine the customer's
    | country. Defaults to 'US' (no VAT charged on US sales for non-US
    | suppliers). Override with a real GeoIP package (stevebauman/location
    | or torann/geoip) for production accuracy.
    |
    */

    'tax_default_country' => env('EXOSPACE_TAX_DEFAULT_COUNTRY', 'US'),

    /*
    |--------------------------------------------------------------------------
    | Application Timezone
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default timezone for your application, which
    | will be used by the PHP date and date-time functions. The timezone
    | is set to "UTC" by default as it is suitable for most use cases.
    |
    */

    'timezone' => 'UTC',

    /*
    |--------------------------------------------------------------------------
    | Application Locale Configuration
    |--------------------------------------------------------------------------
    |
    | The application locale determines the default locale that will be used
    | by Laravel's translation / localization methods. This option can be
    | set to any locale for which you plan to have translation strings.
    |
    */

    'locale' => env('APP_LOCALE', 'en'),

    'fallback_locale' => env('APP_FALLBACK_LOCALE', 'en'),

    'faker_locale' => env('APP_FAKER_LOCALE', 'en_US'),

    /*
    |--------------------------------------------------------------------------
    | Encryption Key
    |--------------------------------------------------------------------------
    |
    | This key is utilized by Laravel's encryption services and should be set
    | to a random, 32 character string to ensure that all encrypted values
    | are secure. You should do this prior to deploying the application.
    |
    */

    'cipher' => 'AES-256-CBC',

    'key' => env('APP_KEY'),

    'previous_keys' => [
        ...array_filter(
            explode(',', (string) env('APP_PREVIOUS_KEYS', ''))
        ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Maintenance Mode Driver
    |--------------------------------------------------------------------------
    |
    | These configuration options determine the driver used to determine and
    | manage Laravel's "maintenance mode" status. The "cache" driver will
    | allow maintenance mode to be controlled across multiple machines.
    |
    | Supported drivers: "file", "cache"
    |
    */

    'maintenance' => [
        'driver' => env('APP_MAINTENANCE_DRIVER', 'file'),
        'store' => env('APP_MAINTENANCE_STORE', 'database'),
    ],

];
