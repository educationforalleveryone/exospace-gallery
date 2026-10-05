<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\AbandonedCartEmail;
use App\Models\PendingUpgrade;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\SendQueuedMailable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AbandonedCartRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private function abandonedCart(User $user, string $plan = 'pro', array $overrides = []): PendingUpgrade
    {
        $pending = PendingUpgrade::createForUser($user, $plan, strtoupper($plan).'-001');
        $pending->forceFill(array_merge(['created_at' => now()->subHours(25)], $overrides))->save();

        return $pending;
    }

    private function consentedUser(array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'email_verified_at' => now(),
            'marketing_consent' => true,
        ], $attributes));
    }

    private function deliverQueuedEmail(): void
    {
        $job = Queue::pushed(SendQueuedMailable::class)->first();
        $this->assertNotNull($job, 'The recovery email should have been queued.');

        // A worker unserializes the job, which reloads the models from the database.
        unserialize(serialize($job))->handle(app('mail.manager'));
    }

    public function test_second_run_does_not_email_the_same_cart_again(): void
    {
        Mail::fake();
        $user = $this->consentedUser();
        $pending = $this->abandonedCart($user);

        $this->artisan('exospace:abandoned-cart')->assertSuccessful();
        $this->artisan('exospace:abandoned-cart')->assertSuccessful();

        Mail::assertQueued(AbandonedCartEmail::class, 1);
        $this->assertNotNull($pending->fresh()->notified_at);
    }

    public function test_converted_and_expired_carts_are_not_emailed(): void
    {
        Mail::fake();
        $this->abandonedCart($this->consentedUser(), 'pro', ['status' => 'converted']);
        $this->abandonedCart($this->consentedUser(), 'pro', ['status' => 'expired']);
        $this->abandonedCart($this->consentedUser(), 'pro', ['expires_at' => now()->subMinute()]);

        $this->artisan('exospace:abandoned-cart')->assertSuccessful();

        Mail::assertNothingQueued();
    }

    public function test_banned_user_is_not_emailed(): void
    {
        Mail::fake();
        $this->abandonedCart($this->consentedUser(['banned_at' => now()]));

        $this->artisan('exospace:abandoned-cart')->assertSuccessful();

        Mail::assertNothingQueued();
    }

    public function test_user_whose_plan_already_covers_the_cart_is_not_emailed_and_cart_is_closed(): void
    {
        Mail::fake();
        $user = $this->consentedUser(['plan' => 'studio']);
        $pending = $this->abandonedCart($user, 'pro');

        $this->artisan('exospace:abandoned-cart')->assertSuccessful();

        Mail::assertNothingQueued();
        $this->assertSame('expired', $pending->fresh()->status);
    }

    public function test_user_on_a_lower_plan_is_still_emailed_for_the_higher_plan(): void
    {
        Mail::fake();
        $user = $this->consentedUser(['plan' => 'pro']);
        $this->abandonedCart($user, 'studio');

        $this->artisan('exospace:abandoned-cart')->assertSuccessful();

        Mail::assertQueued(AbandonedCartEmail::class, fn ($mail) => $mail->pendingUpgrade->plan === 'studio');
    }

    public function test_trial_user_is_still_emailed_to_convert(): void
    {
        Mail::fake();
        $user = $this->consentedUser();
        $user->startTrial('pro');
        $this->abandonedCart($user->fresh(), 'pro');

        $this->artisan('exospace:abandoned-cart')->assertSuccessful();

        Mail::assertQueued(AbandonedCartEmail::class, 1);
    }

    public function test_covered_newest_cart_does_not_hide_an_older_uncovered_cart(): void
    {
        Mail::fake();
        $user = $this->consentedUser(['plan' => 'pro']);
        $this->abandonedCart($user, 'studio', ['created_at' => now()->subHours(48)]);
        $this->abandonedCart($user, 'pro', ['created_at' => now()->subHours(25)]);

        $this->artisan('exospace:abandoned-cart')->assertSuccessful();

        Mail::assertQueued(AbandonedCartEmail::class, function ($mail) {
            return $mail->pendingUpgrade->plan === 'studio';
        });
    }

    public function test_queued_email_is_delivered_while_the_user_is_still_eligible(): void
    {
        Queue::fake();
        $user = $this->consentedUser();
        $this->abandonedCart($user);

        $this->artisan('exospace:abandoned-cart')->assertSuccessful();
        $this->deliverQueuedEmail();

        $this->assertCount(1, Mail::mailer('array')->getSymfonyTransport()->messages());
    }

    public function test_queued_email_is_dropped_if_user_unsubscribes_before_delivery(): void
    {
        Queue::fake();
        $user = $this->consentedUser();
        $this->abandonedCart($user);

        $this->artisan('exospace:abandoned-cart')->assertSuccessful();
        $user->forceFill(['marketing_consent' => false])->save();
        $this->deliverQueuedEmail();

        $this->assertCount(0, Mail::mailer('array')->getSymfonyTransport()->messages());
    }

    public function test_queued_email_is_dropped_if_user_purchases_before_delivery(): void
    {
        Queue::fake();
        $user = $this->consentedUser();
        $pending = $this->abandonedCart($user);

        $this->artisan('exospace:abandoned-cart')->assertSuccessful();
        PendingUpgrade::expireSatisfiedBy($user->id, 'pro');
        $this->assertSame('expired', $pending->fresh()->status);
        $this->deliverQueuedEmail();

        $this->assertCount(0, Mail::mailer('array')->getSymfonyTransport()->messages());
    }

    public function test_queued_email_is_dropped_if_plan_is_granted_before_delivery(): void
    {
        Queue::fake();
        $user = $this->consentedUser();
        $this->abandonedCart($user);

        $this->artisan('exospace:abandoned-cart')->assertSuccessful();
        $user->forceFill(['plan' => 'pro'])->save();
        $this->deliverQueuedEmail();

        $this->assertCount(0, Mail::mailer('array')->getSymfonyTransport()->messages());
    }
}
