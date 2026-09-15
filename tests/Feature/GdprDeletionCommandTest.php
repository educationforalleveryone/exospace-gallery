<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\GdprDeletionRequest;
use App\Models\User;
use App\Services\UserDeletionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AssertsSchedule;
use Tests\TestCase;

class GdprDeletionCommandTest extends TestCase
{
    use RefreshDatabase;
    use AssertsSchedule;

    private function makeDueRequest(User $user, array $overrides = []): GdprDeletionRequest
    {
        return GdprDeletionRequest::createForUser($user, 'test deletion', '127.0.0.1')->refresh();
    }

    // ── execution semantics ──────────────────────────────────────────────

    public function test_due_requests_are_executed_and_marked_completed(): void
    {
        $user = User::factory()->create();
        $this->makeDueRequest($user);

        // scheduled_deletion_at defaults to a future grace period — force it due.
        GdprDeletionRequest::query()->update(['scheduled_deletion_at' => now()->subHour()]);

        $this->artisan('exospace:process-gdpr-deletions')->assertSuccessful();

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
        // The FK is onDelete('set null') — the completed row is kept and
        // anonymized (user_id nulled, email preserved for audit).
        $this->assertDatabaseHas('gdpr_deletion_requests', [
            'email'  => $user->email,
            'status' => 'completed',
        ]);
    }

    public function test_future_requests_are_not_touched(): void
    {
        $user = User::factory()->create();
        $request = $this->makeDueRequest($user); // still inside the grace period

        $this->artisan('exospace:process-gdpr-deletions')->assertSuccessful();

        $this->assertDatabaseHas('users', ['id' => $user->id]);
        $this->assertDatabaseHas('gdpr_deletion_requests', [
            'id'     => $request->id,
            'status' => 'pending',
        ]);
    }

    public function test_completed_requests_are_not_reprocessed(): void
    {
        $user = User::factory()->create();
        $request = $this->makeDueRequest($user);

        $request->forceFill(['status' => 'completed', 'completed_at' => now()])->save();

        $this->artisan('exospace:process-gdpr-deletions')->assertSuccessful();

        $this->assertDatabaseHas('users', ['id' => $user->id]);
    }

    // ── failure isolation: one bad request must not block the rest ───────

    public function test_a_failing_deletion_does_not_block_remaining_requests(): void
    {
        $blockingUser = User::factory()->create();
        $otherUser = User::factory()->create();

        $blocking = $this->makeDueRequest($blockingUser);
        $other = $this->makeDueRequest($otherUser);

        GdprDeletionRequest::query()->update(['scheduled_deletion_at' => now()->subHour()]);

        $this->mock(UserDeletionService::class, function ($mock) use ($blockingUser, $otherUser) {
            $mock->shouldReceive('deleteUser')
                ->once()
                ->withArgs(fn (User $u) => $u->id === $blockingUser->id)
                ->andThrow(new \RuntimeException('corrupted related record'));

            $mock->shouldReceive('deleteUser')
                ->once()
                ->withArgs(fn (User $u) => $u->id === $otherUser->id);
        });

        $this->artisan('exospace:process-gdpr-deletions')->assertExitCode(1);

        // The failing request stays pending for the next run…
        $this->assertDatabaseHas('gdpr_deletion_requests', [
            'id'     => $blocking->id,
            'status' => 'pending',
        ]);

        // …while the unrelated request completed. (Users are NOT deleted
        // here — the service is mocked — only the request state moves.)
        $this->assertDatabaseHas('gdpr_deletion_requests', [
            'id'     => $other->id,
            'status' => 'completed',
        ]);
    }

    // ── schedule registration ────────────────────────────────────────────

    public function test_command_is_registered_on_the_daily_schedule(): void
    {
        $this->assertCommandScheduled('exospace:process-gdpr-deletions');
    }

    public function test_legacy_inline_closure_is_no_longer_scheduled(): void
    {
        $this->app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

        $descriptions = collect($this->app->make(\Illuminate\Console\Scheduling\Schedule::class)->events())
            ->map(fn ($event) => (string) ($event->description ?? ''))
            ->all();

        $this->assertNotContains(
            'gdpr-deletion-processing',
            $descriptions,
            'The inline GDPR closure was replaced by exospace:process-gdpr-deletions — the closure must be gone.'
        );
    }
}
