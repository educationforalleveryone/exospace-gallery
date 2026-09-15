<?php

declare(strict_types=1);

namespace App\Services;

class BackupArtifactReport
{
    /**
     * @param  list<string>  $errors
     * @param  list<string>  $warnings
     */
    public function __construct(
        public readonly string $disk,
        public readonly ?string $file = null,
        public readonly array $errors = [],
        public readonly array $warnings = [],
        public readonly ?int $sizeBytes = null,
        public readonly ?int $lastModified = null,
        public readonly int $dbDumpEntries = 0,
        public readonly int $mediaEntries = 0,
        public readonly bool $encrypted = false,
    ) {}

    public function passes(): bool
    {
        return $this->errors === [];
    }

    public function problemSummary(): string
    {
        return $this->errors === []
            ? ''
            : implode(' | ', $this->errors);
    }
}
