<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class LifecycleEmailDedupTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_reminded_inside_the_current_expiry_window_is_not_reminded_again(): void
    {
        Mail::fake();

        User::factory()->pro()->create([
            'email_verified_at' => now(),
            'plan_expires_at' => now()->addDays(5),
            'plan_expiry_reminded_at' => now()->subDays(2), // inside [expiry-14d, expiry]
        ]);

        $this->artisan('exospace:send-lifecycle-emails')->assertSuccessful();

        Mail::assertNothingSent();
    }

    public function test_renewed_user_gets_a_reminder_for_the_new_expiry_cycle(): void
    {
        Mail::fake();

        // The prior reminder landed long before the new cycle's window —
        // it must not suppress a reminder for the renewed expiry date.
        User::factory()->pro()->create([
            'email_verified_at' => now(),
            'plan_expires_at' => now()->addDays(5),
            'plan_expiry_reminded_at' => now()->subDays(40),
        ]);

        $this->artisan('exospace:send-lifecycle-emails')->assertSuccessful();

        Mail::assertQueued(\App\Mail\PlanExpiringSoon::class, 1);
    }

    public function test_already_reminded_users_do_not_consume_the_batch_slots(): void
    {
        Mail::fake();

        // Fill the batch with users whose reminder already fired this cycle;
        // under the old filter-after-limit behaviour they could occupy every
        // slot and starve the eligible users behind them.
        for ($i = 0; $i < 50; $i++) {
            User::factory()->pro()->create([
                'email_verified_at' => now(),
                'plan_expires_at' => now()->addDays(5),
                'plan_expiry_reminded_at' => now()->subDays(1),
            ]);
        }

        $eligible = User::factory()->pro()->count(2)->create([
            'email_verified_at' => now(),
            'plan_expires_at' => now()->addDays(4),
            'plan_expiry_reminded_at' => null,
        ]);

        $this->artisan('exospace:send-lifecycle-emails')->assertSuccessful();

        Mail::assertQueued(\App\Mail\PlanExpiringSoon::class, 2);

        $notifiedIds = collect(Mail::queued(\App\Mail\PlanExpiringSoon::class))
            ->map(fn ($mail) => $mail->user->id)
            ->all();

        $this->assertEqualsCanonicalizing($eligible->pluck('id')->all(), $notifiedIds);
    }

    public function test_expired_plans_are_a_stop_condition(): void
    {
        Mail::fake();

        User::factory()->pro()->create([
            'email_verified_at' => now(),
            'plan_expires_at' => now()->subDay(), // already expired
            'plan_expiry_reminded_at' => null,
        ]);

        $this->artisan('exospace:send-lifecycle-emails')->assertSuccessful();

        Mail::assertNothingSent();
    }
}
