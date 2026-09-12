<?php

declare(strict_types=1);

namespace Tests\Support;

trait AssertsSchedule
{
    protected function scheduledCommands(): array
    {
        $this->app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

        $schedule = $this->app->make(\Illuminate\Console\Scheduling\Schedule::class);

        return collect($schedule->events())
            ->map(fn ($event) => (string) ($event->command ?? ''))
            ->toArray();
    }

    protected function assertCommandScheduled(string $needle, string $message = ''): void
    {
        $commands = $this->scheduledCommands();
        $found = collect($commands)->contains(fn ($cmd) => str_contains($cmd, $needle));

        $this->assertTrue(
            $found,
            $message !== '' ? $message : "Expected a scheduled command containing [{$needle}]. Registered commands: " . implode(' | ', $commands)
        );
    }

    protected function assertClosureScheduled(string $description, string $message = ''): void
    {
        $this->app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

        $schedule = $this->app->make(\Illuminate\Console\Scheduling\Schedule::class);

        $descriptions = collect($schedule->events())
            ->map(fn ($event) => (string) ($event->description ?? ''))
            ->toArray();

        $this->assertTrue(
            in_array($description, $descriptions, true),
            $message !== '' ? $message : "Expected a scheduled closure named [{$description}]. Registered descriptions: " . implode(' | ', $descriptions)
        );
    }
}
