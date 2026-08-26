<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * ITERATION-1 (test-safety-net): schedule assertions for Laravel 12.
 *
 * The existing tests called `Schedule::assertScheduled(...)`, a method that
 * only exists on ScheduleFake — a class that does not exist in this framework
 * version (no schedule testing feature shipped with Laravel 12.63). Every
 * schedule assertion in the suite errored with
 * "Method Schedule::assertScheduled does not exist".
 *
 * This trait asserts against the REAL schedule: it boots the console kernel
 * (which loads routes/console.php and registers the events), then inspects
 * the registered Event objects' command strings.
 */
trait AssertsSchedule
{
    /**
     * Return the command strings of all registered schedule events.
     *
     * @return array<int, string>
     */
    protected function scheduledCommands(): array
    {
        // Boot the console kernel so routes/console.php is loaded and the
        // Schedule singleton has its events registered.
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

    /**
     * The schedule registers closures (e.g. operational-alerts) as
     * "Closure command" — match those by the event's description/name.
     */
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
