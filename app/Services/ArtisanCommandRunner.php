<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Artisan;

class ArtisanCommandRunner
{
    /**
 * @var string The stdout captured by the most recent call.
 */
    private string $lastOutput = '';

    public function __invoke(string $command, array $parameters = []): int
    {
        $outputBuffer = [];
        $exit = Artisan::call($command, $parameters, $outputBuffer);
        $this->lastOutput = is_array($outputBuffer)
            ? implode("\n", $outputBuffer)
            : (string) $outputBuffer;
        return (int) $exit;
    }

    public function lastOutput(): string
    {
        return $this->lastOutput;
    }
}
