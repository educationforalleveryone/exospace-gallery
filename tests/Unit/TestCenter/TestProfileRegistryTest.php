<?php

declare(strict_types=1);

namespace Tests\Unit\TestCenter;

use App\Services\TestCenter\TestProfileRegistry;
use PHPUnit\Framework\TestCase;

class TestProfileRegistryTest extends TestCase
{
    private const BASE = __DIR__.'/../../..';   // → project root without framework boot

    private function registry(): TestProfileRegistry
    {
        return new TestProfileRegistry(
            require self::BASE.'/config/test-profiles.php',
            realpath(self::BASE),
        );
    }

    public function test_ships_all_nine_operationally_documented_profiles(): void
    {
        $registry = $this->registry();

        foreach ([
            'quick_check', 'pre_release', 'full_regression', 'security',
            'billing', 'seo', 'database', 'smoke', 'production_health',
        ] as $required) {
            $this->assertTrue($registry->has($required), "Missing profile [{$required}]");
        }
    }

    public function test_safety_classes_match_spec_matrix(): void
    {
        $profiles = $this->registry()->profiles();

        $this->assertSame('prod-safe-read', $profiles['smoke']['safety']);
        $this->assertSame('prod-safe-read', $profiles['production_health']['safety']);
        $this->assertNotSame('prod-safe-read', $profiles['pre_release']['safety'],
            'Suite profiles must never be prod-safe-read.');
    }

    public function test_unknown_profile_throws_actionable_error(): void
    {
        $registry = new TestProfileRegistry(['profiles' => [], 'groups' => []], self::BASE);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('test-profiles.php');
        $registry->profile('media'); // documented future extension
    }

    public function test_star_group_expansion_resolves_real_files_and_excludes_browser(): void
    {
        $paths = $this->registry()->resolvePaths('pre_release');

        $this->assertNotEmpty($paths, '* expansion must produce concrete paths');

        $seenFeature = false;
        foreach ($paths as $path) {
            $this->assertTrue(file_exists($path), "Resolved path does not exist: {$path}");
            $normalized = str_replace('\\', '/', $path);
            $this->assertStringNotContainsString('tests/Browser', $normalized,
                'Browser/Dusk must stay excluded from pre_release by config.');
            if (str_contains($normalized, 'tests/Feature')) {
                $seenFeature = true;
            }
        }
        $this->assertTrue($seenFeature, 'pre_release must include the Feature suites.');
    }

    public function test_database_profile_pins_mysql_fidelity_requirement(): void
    {
        $profiles = $this->registry()->profiles();

        $this->assertSame('mysql-required', $profiles['database']['database'],
            '`database` profile documents that SQLite hides engine behaviour.');
    }

    public function test_conflict_keys_reference_existing_profiles(): void
    {
        $registry = $this->registry();

        foreach ($registry->profiles() as $key => $profile) {
            foreach ((array) ($profile['conflicts_with'] ?? []) as $conflictKey) {
                $this->assertTrue($registry->has($conflictKey),
                    "Profile [{$key}] declares conflict with unknown profile [{$conflictKey}].");
            }
        }
    }
}
