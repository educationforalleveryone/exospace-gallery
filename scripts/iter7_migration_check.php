<?php
/**
 * ITERATION 7 — migration up/down/up + data round-trip on SQLite.
 * Mirrors the Iteration-6 iter6_migration_check.php pattern.
 */
require __DIR__ . '/../vendor/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Force sqlite for this script (sandbox .env points at mysql).
config(['database.default' => 'sqlite']);
\DB::purge();
\DB::reconnect();

// Fresh in-memory db
\Artisan::call('migrate:fresh', ['--force' => true]);

$tables = collect(DB::select("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name"))
    ->pluck('name')->all();

if (! in_array('billing_digest_recipients', $tables, true)) {
    fwrite(STDERR, "FAIL: billing_digest_recipients table not created\n");
    fwrite(STDERR, "Tables: " . implode(',', $tables) . "\n");
    exit(1);
}
echo "OK: billing_digest_recipients table present after up.\n";

// Insert + read back
$now = now();
DB::table('billing_digest_recipients')->insert([
    'email'     => 'finance@example.com',
    'added_by'  => null,
    'created_at'=> $now,
    'updated_at'=> $now,
]);
$count = DB::table('billing_digest_recipients')->count();
if ($count !== 1) { fwrite(STDERR, "FAIL: expected 1 row, got {$count}\n"); exit(2); }
echo "OK: inserted 1 row, count={$count}.\n";

// Unique constraint
$ok = false;
try {
    DB::table('billing_digest_recipients')->insert([
        'email'     => 'finance@example.com',
        'added_by'  => null,
        'created_at'=> $now,
        'updated_at'=> $now,
    ]);
} catch (\Throwable $e) {
    $ok = true;
}
if (! $ok) { fwrite(STDERR, "FAIL: unique email constraint not enforced\n"); exit(3); }
echo "OK: unique email constraint enforced.\n";

// Rollback
\Artisan::call('migrate:rollback', ['--step' => 1, '--force' => true]);
$tablesAfter = collect(DB::select("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name"))
    ->pluck('name')->all();
if (in_array('billing_digest_recipients', $tablesAfter, true)) {
    fwrite(STDERR, "FAIL: billing_digest_recipients table still present after rollback\n");
    exit(4);
}
echo "OK: table dropped on rollback.\n";

// Re-migrate (idempotency)
\Artisan::call('migrate', ['--force' => true]);
$tablesFinal = collect(DB::select("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name"))
    ->pluck('name')->all();
if (! in_array('billing_digest_recipients', $tablesFinal, true)) {
    fwrite(STDERR, "FAIL: billing_digest_recipients table not re-created on re-migrate\n");
    exit(5);
}
echo "OK: table re-created on re-migrate (up/down/up idempotent).\n";

echo "\nAll migration checks passed.\n";
exit(0);
