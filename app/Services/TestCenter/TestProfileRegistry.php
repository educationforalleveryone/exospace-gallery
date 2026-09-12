<?php

declare(strict_types=1);

namespace App\Services\TestCenter;

use InvalidArgumentException;

class TestProfileRegistry
{
    private array $config;

    private string $basePath;

    public function __construct(
        ?array $config = null,
        ?string $basePath = null,
    ) {
        $this->config = $config ?: config('test-profiles', []);
        $this->basePath = rtrim($basePath ?: base_path(), '/');
    }

    private function root(): string
    {
        return $this->basePath;
    }

    public function profiles(): array
    {
        return $this->config['profiles'] ?? [];
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->profiles());
    }

    public function profile(string $key): array
    {
        if (! $this->has($key)) {
            throw new InvalidArgumentException("Unknown test profile [{$key}]. Add it to config/test-profiles.php.");
        }

        return $this->profiles()[$key];
    }

    public function groups(): array
    {
        return $this->config['groups'] ?? [];
    }

    public function resolvePaths(string $profileKey): array
    {
        $profile   = $this->profile($profileKey);
        $groupRefs = $profile['groups'] ?? [];

        $selectedGroups = $groupRefs === '*'
            ? array_keys($this->groups())
            : (array) $groupRefs;

        $patterns = [];

        foreach ($selectedGroups as $groupKey) {
            $group = $this->groups()[$groupKey] ?? null;
            if ($group === null) {
                throw new InvalidArgumentException("Unknown taxonomy group [{$groupKey}] referenced by profile [{$profileKey}].");
            }
            foreach ((array) ($group['paths'] ?? []) as $pattern) {
                $patterns[] = $pattern;
            }
        }

        foreach ((array) ($profile['paths'] ?? []) as $pattern) {
            $patterns[] = $pattern;
        }

        $excluded = array_map(
            fn (string $p): string => $this->root().'/'.$p,
            (array) ($profile['exclude_paths'] ?? []),
        );

        $resolved = [];

        foreach (array_unique($patterns) as $pattern) {
            $candidates = str_contains($pattern, '*')
                ? glob($this->root().'/'.$pattern) ?: []
                : [$this->root().'/'.$pattern];

            foreach ($candidates as $candidate) {
                if (! file_exists($candidate)) {
                    continue; // pattern references something absent in this checkout
                }
                if (in_array($candidate, $excluded, true)) {
                    continue;
                }
                $resolved[] = $candidate;
            }
        }

        return array_values(array_unique($resolved));
    }

    public function summarizeForList(): array
    {
        $out = [];

        foreach ($this->profiles() as $key => $profile) {
            $out[$key] = [
                'label'             => $profile['label'] ?? $key,
                'icon'              => $profile['icon'] ?? '🧪',
                'color'             => $profile['color'] ?? 'slate',
                'safety'            => $profile['safety'] ?? 'test-only',
                'strategy'          => $profile['strategy'] ?? 'phpunit',
                'database'          => $profile['database'] ?? null,
                'description'       => $profile['description'] ?? '',
                'estimated_minutes' => $profile['estimated_minutes'] ?? null,
                'groups'            => (array) ($profile['groups'] ?? []),
            ];
        }

        return $out;
    }
}
