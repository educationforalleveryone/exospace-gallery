<?php

namespace App\Console\Commands;

use App\Models\Gallery;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Pre-flight check for production Exospace deployments.
 *
 * Run after every deploy to catch configuration issues before users do:
 *
 *     php artisan exospace:preflight
 *
 * Exits with code 1 if any CRITICAL check fails (so it can be wired into
 * a CI/CD pipeline or a post-deploy Coolify health check).
 *
 * NOTE: do NOT add a {--verbose} option to the signature — Laravel's
 * base Command class (via Symfony Console) already registers -v|--verbose
 * globally, and re-declaring it throws "An option named 'verbose' already
 * exists" at command registration time. The built-in -v / -vv / -vvv
 * flags work automatically if you ever need them.
 */
class PreflightCheck extends Command
{
    protected $signature = 'exospace:preflight';
    protected $description = 'Verify production configuration, extensions, and integrations.';

    private int $failures = 0;
    private int $warnings = 0;

    public function handle(): int
    {
        $this->info('Exospace pre-flight check');
        $this->info('=========================');
        $this->newLine();

        $this->checkEnvConfig();
        $this->checkPhpExtensions();
        $this->checkDatabase();
        $this->checkFilesystem();
        $this->checkCache();
        $this->checkMail();
        $this->checkPayments();
        $this->checkCoolifyIntegration();
        $this->checkVenueTemplates();
        $this->checkStorageLink();
        $this->checkRobotsAndSitemap();

        $this->newLine();
        $this->info('=========================');
        $this->info("Summary: {$this->failures} critical, {$this->warnings} warnings");

        if ($this->failures > 0) {
            $this->error('❌  Preflight FAILED — fix critical issues before going live.');
            return 1;
        }

        if ($this->warnings > 0) {
            $this->warn('⚠️  Preflight passed with warnings — review above.');
        } else {
            $this->info('✅  All clear — ready for production traffic.');
        }
        return 0;
    }

    // ─────────────────────────────────────────────────────────────────────
    //  Individual checks
    // ─────────────────────────────────────────────────────────────────────

    private function checkEnvConfig(): void
    {
        $this->section('Environment configuration');

        $appEnv = config('app.env');
        if ($appEnv === 'production') {
            $this->ok("APP_ENV=production");
        } else {
            $this->critical("APP_ENV is '{$appEnv}' — should be 'production' for live deployments.");
        }

        $appDebug = config('app.debug');
        if (!$appDebug) {
            $this->ok("APP_DEBUG=false");
        } else {
            $this->critical("APP_DEBUG=true in production — leaks stack traces to visitors.");
        }

        $appUrl = config('app.url');
        if (Str::startsWith($appUrl, 'https://')) {
            $this->ok("APP_URL uses HTTPS ({$appUrl})");
        } else {
            $this->critical("APP_URL is '{$appUrl}' — must start with https:// in production.");
        }

        $appKey = config('app.key');
        if (!empty($appKey) && Str::startsWith($appKey, 'base64:')) {
            $this->ok("APP_KEY is set (base64-encoded)");
        } else {
            $this->critical("APP_KEY is missing or invalid — run `php artisan key:generate`.");
        }

        $trustedProxies = env('TRUSTED_PROXIES', '*');
        if ($trustedProxies === '*' || !empty($trustedProxies)) {
            if ($trustedProxies === '*') {
                $this->advisory("TRUSTED_PROXIES=* — works but is overly permissive. For production, restrict to Coolify's Traefik subnet (task C17). Find it via: docker network inspect coolify-network | grep Subnet");
            } else {
                $this->ok("TRUSTED_PROXIES is set ({$trustedProxies}) — Coolify reverse proxy trusted.");
            }
        } else {
            $this->critical("TRUSTED_PROXIES is empty — Laravel will reject X-Forwarded-* headers from Coolify's Traefik. Custom domains and HTTPS detection will break.");
        }
    }

    private function checkPhpExtensions(): void
    {
        $this->section('PHP extensions');

        $required = ['pdo', 'pdo_mysql', 'mbstring', 'ctype', 'json', 'xml', 'tokenizer', 'curl', 'fileinfo', 'bcmath', 'gd', 'exif'];
        $optional = ['redis', 'imagick', 'zip', 'intl'];

        foreach ($required as $ext) {
            if (extension_loaded($ext)) {
                $this->ok("ext-{$ext} loaded");
            } else {
                $this->critical("ext-{$ext} MISSING — required. For Nixpacks, add: NIXPACKS_PHP_EXTENSIONS=" . implode(',', $required));
            }
        }

        foreach ($optional as $ext) {
            if (extension_loaded($ext)) {
                $this->ok("ext-{$ext} loaded (optional)");
            } else {
                $this->advisory("{$ext} not loaded (optional, but recommended for performance/features).");
            }
        }

        $uploadMax = ini_get('upload_max_filesize');
        $postMax = ini_get('post_max_size');
        $this->info("  upload_max_filesize = {$uploadMax}");
        $this->info("  post_max_size = {$postMax}");
        if ($this->toBytes($uploadMax) < 50 * 1024 * 1024) {
            $this->advisory("upload_max_filesize ({$uploadMax}) < 50M — large gallery images will be rejected.");
        }
    }

    private function checkDatabase(): void
    {
        $this->section('Database');

        try {
            DB::select('SELECT 1');
            $this->ok('DB connection works');
        } catch (\Throwable $e) {
            $this->critical('DB connection failed: ' . $e->getMessage());
            return;
        }

        try {
            $pending = DB::table('migrations')->count();
            $this->ok("Migrations table accessible ({$pending} migrations recorded)");
        } catch (\Throwable $e) {
            $this->critical('Cannot read migrations table: ' . $e->getMessage());
        }

        foreach (['users', 'galleries', 'venue_templates'] as $table) {
            try {
                $count = DB::table($table)->count();
                $this->info("  table `{$table}`: {$count} rows");
            } catch (\Throwable $e) {
                $this->advisory("Cannot read table `{$table}` — migration may be missing.");
            }
        }

        try {
            $hasColumn = DB::getSchemaBuilder()->hasColumn('galleries', 'custom_domain');
            if ($hasColumn) {
                $this->ok('galleries.custom_domain column exists (Round 2/3 migration applied)');
            } else {
                $this->critical('galleries.custom_domain column missing — run `php artisan migrate`.');
            }
        } catch (\Throwable $e) {
            $this->advisory('Could not introspect galleries schema: ' . $e->getMessage());
        }

        try {
            $hasVisualConfig = DB::getSchemaBuilder()->hasColumn('venue_templates', 'visual_config');
            if ($hasVisualConfig) {
                $this->ok('venue_templates.visual_config column exists (Round 1 migration applied)');
            } else {
                $this->critical('venue_templates.visual_config column missing — run the Round 1 migration.');
            }
        } catch (\Throwable $e) {
            $this->advisory('Could not introspect venue_templates schema: ' . $e->getMessage());
        }
    }

    private function checkFilesystem(): void
    {
        $this->section('Filesystem');

        $disk = config('filesystems.default');
        $this->info("  Default disk: {$disk}");

        $publicDisk = config('filesystems.disks.public');
        if (isset($publicDisk['root'])) {
            $root = $publicDisk['root'];
            if (is_writable($root)) {
                $this->ok("public disk root is writable ({$root})");
            } else {
                $this->critical("public disk root ({$root}) is not writable — uploads will fail.");
            }
        }

        $publicStorage = public_path('storage');
        if (is_link($publicStorage) || is_dir($publicStorage)) {
            $this->ok('public/storage symlink exists');
        } else {
            $this->critical('public/storage symlink missing — run `php artisan storage:link`.');
        }

        foreach (['audio', 'branding', 'gallery-images', 'venue-thumbnails', 'venue-models', 'venue-hdri', 'venue-audio'] as $sub) {
            $path = storage_path('app/public/' . $sub);
            if (is_dir($path)) {
                $this->ok("storage/app/public/{$sub}/ exists");
            } else {
                try {
                    File::makeDirectory($path, 0775, true);
                    $this->ok("storage/app/public/{$sub}/ created");
                } catch (\Throwable $e) {
                    $this->advisory("Could not create storage/app/public/{$sub}/ — first upload to this folder may fail.");
                }
            }
        }
    }

    private function checkCache(): void
    {
        $this->section('Cache');

        $store = config('cache.default');
        $this->info("  CACHE_STORE = {$store}");

        try {
            Cache::put('preflight:test', 'ok', 10);
            $read = Cache::get('preflight:test');
            Cache::forget('preflight:test');
            if ($read === 'ok') {
                $this->ok("Cache store ({$store}) is functional");
            } else {
                $this->critical("Cache store ({$store}) write succeeded but read failed — cache is unreliable.");
            }
        } catch (\Throwable $e) {
            $this->critical("Cache store ({$store}) failed: " . $e->getMessage());
        }

        if ($store === 'file') {
            $this->advisory('CACHE_STORE=file is per-container — if Coolify scales to multiple containers, each container has its own cache. Switch to Redis for horizontal scalability.');
        }

        if ($store === 'redis') {
            $this->ok('Using Redis cache — horizontal-scale safe.');
        }
    }

    private function checkMail(): void
    {
        $this->section('Mail');

        $mailer = config('mail.default');
        $this->info("  MAIL_MAILER = {$mailer}");

        if ($mailer === 'log') {
            $this->critical('MAIL_MAILER=log — emails are written to laravel.log instead of being sent. Production must use resend (or smtp).');
            return;
        }

        if ($mailer === 'resend') {
            $key = config('services.resend.key');
            if (!empty($key) && Str::startsWith($key, 're_')) {
                $this->ok('Resend API key is configured (looks valid).');
            } else {
                $this->critical('MAIL_MAILER=resend but RESEND_API_KEY is missing or does not start with "re_".');
            }

            $from = config('mail.from.address');
            if (!empty($from) && !Str::contains($from, 'example.com')) {
                $this->ok("MAIL_FROM_ADDRESS = {$from}");
            } else {
                $this->critical("MAIL_FROM_ADDRESS is '{$from}' — set to a real address on your verified domain.");
            }
        }
    }

    private function checkPayments(): void
    {
        $this->section('Payments (2Checkout)');

        $acct = config('services.2checkout.account_number');
        $secret = config('services.2checkout.secret_word');
        $pro = config('services.2checkout.product_id_pro');
        $studio = config('services.2checkout.product_id_studio');

        if (empty($acct) || $acct === 'your_account_number') {
            $this->critical('TWOCHECKOUT_ACCOUNT_NUMBER is placeholder — Pro/Studio plan purchases will fail.');
        } else {
            $this->ok('TWOCHECKOUT_ACCOUNT_NUMBER is set');
        }

        if (empty($secret) || $secret === 'your_secret_word_from_2checkout') {
            $this->critical('TWOCHECKOUT_SECRET_WORD is placeholder — webhook signature verification will fail, and ALL payments will be rejected as fraudulent.');
        } else {
            $this->ok('TWOCHECKOUT_SECRET_WORD is set');
        }

        if (empty($pro)) {
            $this->critical('TWOCHECKOUT_PRODUCT_ID_PRO is empty — Pro plan checkout link is broken.');
        } else {
            $this->ok("TWOCHECKOUT_PRODUCT_ID_PRO = {$pro}");
        }

        if (empty($studio)) {
            $this->critical('TWOCHECKOUT_PRODUCT_ID_STUDIO is empty — Studio plan checkout link is broken. Custom domain feature requires Studio plan, so it cannot be purchased.');
        } else {
            $this->ok("TWOCHECKOUT_PRODUCT_ID_STUDIO = {$studio}");
        }
    }

    private function checkCoolifyIntegration(): void
    {
        $this->section('Coolify integration (custom domain automation)');

        // Read the three env vars that CoolifyDomainManager actually uses.
        // NOTE: previous version of this check looked for COOLIFY_PROJECT_UUID
        // and COOLIFY_ENVIRONMENT_UUID, which don't exist anywhere else in the
        // codebase — false-positive warnings. Fixed in Round 3 patch.
        $token   = config('services.coolify.api_token');
        $baseUrl = config('services.coolify.api_base_url');
        $appUuid = config('services.coolify.application_uuid');

        if (empty($token)) {
            $this->advisory('COOLIFY_API_TOKEN not set — custom domain requests will require manual Coolify UI configuration. Set this to enable automatic domain + SSL provisioning.');
        } else {
            $this->ok('COOLIFY_API_TOKEN is set');
        }

        if (empty($baseUrl)) {
            $this->advisory('COOLIFY_API_BASE_URL not set — should be your Coolify instance URL (e.g. https://coolify.yourdomain.com).');
        } else {
            $this->ok("COOLIFY_API_BASE_URL = {$baseUrl}");
        }

        if (empty($appUuid)) {
            $this->advisory('COOLIFY_APPLICATION_UUID not set — the CoolifyDomainManager service cannot auto-add custom domains without this. Find it in the Coolify URL when viewing your Exospace application (last path segment after "application/").');
        } else {
            $this->ok("COOLIFY_APPLICATION_UUID = {$appUuid}");
        }

        // Live API ping — catches the common failure mode where env vars are
        // set but the values are wrong (expired token, typo in UUID, wrong
        // base URL). Only run if all three are non-empty.
        if (!empty($token) && !empty($baseUrl) && !empty($appUuid)) {
            $this->info('  Pinging Coolify API to verify credentials…');
            $ping = $this->pingCoolifyApi($token, $baseUrl, $appUuid);
            if ($ping['ok']) {
                $domainCount = $ping['domain_count'] ?? 0;
                $this->ok("Coolify API reachable — application found, {$domainCount} domain(s) currently routed.");
            } else {
                $this->critical("Coolify API verification failed: {$ping['error']}");
            }
        }

        try {
            $count = Gallery::whereNotNull('custom_domain')->count();
            $this->info("  Galleries with custom_domain set: {$count}");
        } catch (\Throwable $e) {
            $this->advisory('Could not query galleries for custom_domain count.');
        }
    }

    /**
     * Live-ping the Coolify API to verify the credentials actually work.
     * Returns ['ok' => bool, 'error' => string|null, 'domain_count' => int|null].
     */
    private function pingCoolifyApi(string $token, string $baseUrl, string $appUuid): array
    {
        try {
            $url = rtrim($baseUrl, '/') . "/api/v1/applications/{$appUuid}";
            $resp = \Illuminate\Support\Facades\Http::withToken($token)
                ->timeout(10)
                ->get($url);

            if ($resp->status() === 401) {
                return ['ok' => false, 'error' => 'HTTP 401 Unauthorized — COOLIFY_API_TOKEN is invalid or revoked.'];
            }
            if ($resp->status() === 404) {
                return ['ok' => false, 'error' => 'HTTP 404 — COOLIFY_APPLICATION_UUID does not match any application in Coolify.'];
            }
            if (!$resp->successful()) {
                return ['ok' => false, 'error' => "HTTP {$resp->status()} — {$resp->body()}"];
            }

            $data = $resp->json();
            $domains = $data['domains'] ?? '';
            $list = array_filter(array_map('trim', explode(',', $domains)));
            return [
                'ok'           => true,
                'error'        => null,
                'domain_count' => count($list),
            ];
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            return ['ok' => false, 'error' => 'Connection error — COOLIFY_API_BASE_URL may be wrong or Coolify is unreachable. ' . $e->getMessage()];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    private function checkVenueTemplates(): void
    {
        $this->section('Venue templates');

        try {
            $total = DB::table('venue_templates')->count();
            $active = DB::table('venue_templates')->where('is_active', true)->count();
            $withVisualConfig = DB::table('venue_templates')
                ->whereNotNull('visual_config')
                ->count();

            $this->info("  Total venue templates: {$total}");
            $this->info("  Active: {$active}");
            $this->info("  With visual_config JSON: {$withVisualConfig}");

            if ($total === 0) {
                $this->critical('No venue templates — run `php artisan db:seed --class=VenueTemplateSeeder`.');
            } else {
                $this->ok("Venue templates seeded ({$total} total)");
            }

            if ($withVisualConfig === 0) {
                $this->advisory('No venue templates have visual_config JSON — re-run the VenueTemplateSeeder to populate data-driven venue configs.');
            }
        } catch (\Throwable $e) {
            $this->critical('Could not query venue_templates: ' . $e->getMessage());
        }
    }

    private function checkStorageLink(): void
    {
        // Already checked in checkFilesystem
    }

    private function checkRobotsAndSitemap(): void
    {
        $this->section('SEO endpoints');

        $robotsPath = public_path('robots.txt');
        if (file_exists($robotsPath)) {
            $this->ok('public/robots.txt exists');
            $contents = file_get_contents($robotsPath);
            if (Str::contains($contents, 'Sitemap:')) {
                $this->ok('robots.txt references a sitemap');
            } else {
                $this->advisory('robots.txt does not reference a sitemap — add "Sitemap: https://exospace.gallery/sitemap.xml" for SEO.');
            }
        } else {
            $this->advisory('public/robots.txt missing — see COOLIFY_SETUP.md for the recommended contents.');
        }

        try {
            $url = route('sitemap');
            $this->ok("Sitemap route registered: {$url}");
        } catch (\Throwable $e) {
            $this->critical('Sitemap route not registered — check routes/web.php');
        }

        try {
            $url = route('feed');
            $this->ok("RSS feed route registered: {$url}");
        } catch (\Throwable $e) {
            $this->critical('RSS feed route not registered.');
        }

        try {
            $url = route('discover');
            $this->ok("Discover route registered: {$url}");
        } catch (\Throwable $e) {
            $this->critical('Discover route not registered.');
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    //  Output helpers
    //
    //  Named ok/critical/advisory instead of pass/fail/warn to avoid
    //  colliding with Illuminate\Console\Command's public methods:
    //    - warn()  — defined on InteractsWithIO trait (the actual blocker)
    //    - fail()  — Symfony Console Command base class
    //    - error() — InteractsWithIO trait (would also collide if used)
    //  PHP requires child class methods to have equal or wider visibility
    //  than the parent — declaring these as private here is a fatal error
    //  at parse time, which is why `php artisan make:middleware` (which
    //  bootstraps all commands) also failed.
    // ─────────────────────────────────────────────────────────────────────

    private function section(string $title): void
    {
        $this->newLine();
        $this->line("  <fg=cyan;options=bold>{$title}</>");
    }

    private function ok(string $msg): void
    {
        $this->line("    <fg=green>✓</> {$msg}");
    }

    private function critical(string $msg): void
    {
        $this->line("    <fg=red;options=bold>✗</> {$msg}");
        $this->failures++;
    }

    private function advisory(string $msg): void
    {
        $this->line("    <fg=yellow>⚠</>  {$msg}");
        $this->warnings++;
    }

    private function toBytes(string $val): int
    {
        $val = trim($val);
        $last = strtolower($val[strlen($val) - 1]);
        $num = (int) $val;
        switch ($last) {
            case 'g': $num *= 1024;
            case 'm': $num *= 1024;
            case 'k': $num *= 1024;
        }
        return $num;
    }
}
