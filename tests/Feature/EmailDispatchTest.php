<?php

namespace Tests\Feature;

use App\Listeners\SendWelcomeEmail;
use App\Mail\PlanUpgradedEmail;
use App\Mail\WelcomeEmail;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Email dispatch tests.
 *
 * (Task H28) — verifies that lifecycle emails are actually sent:
 *   - WelcomeEmail on new user registration
 *   - PlanUpgradedEmail on webhook upgrade
 *   - PlanUpgradedEmail on admin plan change
 *
 * Uses Mail::fake() so no actual emails are sent — just verifies the
 * mailable was dispatched to the correct recipient.
 */
class EmailDispatchTest extends TestCase
{
    use RefreshDatabase;

    public function test_welcome_email_is_sent_on_registration(): void
    {
        Mail::fake();

        $response = $this->post('/register', [
            'name'                  => 'Test User',
            'email'                 => 'test@example.com',
            'password'              => 'password',
            'password_confirmation' => 'password',
        ]);

        $response->assertRedirect();
        Mail::assertQueued(WelcomeEmail::class, function ($mail) {
            return $mail->user->email === 'test@example.com';
        });
    }

    public function test_plan_upgraded_email_is_sent_on_webhook_upgrade(): void
    {
        Mail::fake();

        Config::set('services.2checkout.secret_word', 'test-secret');
        Config::set('services.2checkout.product_id_pro', 'PRO-001');

        $user = User::factory()->create(['email' => 'buyer@example.com']);

        $saleId = 'SALE-' . uniqid();
        $invoiceId = 'INV-' . uniqid();
        $stringToHash = strlen($saleId) . $saleId
                      . strlen('V123') . 'V123'
                      . strlen($invoiceId) . $invoiceId
                      . strlen('test-secret') . 'test-secret';
        $hash = strtoupper(md5($stringToHash));

        $response = $this->postJson('/webhooks/2checkout', [
            'message_type'      => 'ORDER_CREATED',
            'sale_id'           => $saleId,
            'vendor_id'         => 'V123',
            'invoice_id'        => $invoiceId,
            'md5_hash'          => $hash,
            'customer_email'    => 'buyer@example.com',
            'item_id_1'         => 'PRO-001',
            'item_list_amount_1'=> '29.00',
            'list_currency'     => 'USD',
        ]);

        $response->assertOk();
        Mail::assertQueued(PlanUpgradedEmail::class, function ($mail) use ($user) {
            return $mail->user->id === $user->id
                && $mail->plan === 'pro';
        });
    }

    public function test_plan_upgraded_email_is_sent_on_admin_plan_change(): void
    {
        Mail::fake();

        // ITERATION-1 FIX: master-control routes demand verified + MFA
        // for super-admins — the old test was redirected to /mfa/setup
        // before reaching the controller.
        $superAdmin = User::factory()->withMfa()->create([
            'is_super_admin'   => true,
            'email_verified_at' => now(),
        ]);
        $user = User::factory()->create(['plan' => 'free']);

        $response = $this->actingAs($superAdmin)
            ->withSession([
                'mfa_verified'          => true,
                'mfa_verified_at'       => now()->timestamp,
                'auth.password_confirmed_at' => now()->timestamp,
            ])
            ->post("/master-control/users/{$user->id}/plan", [
                'plan' => 'pro',
            ]);

        $response->assertRedirect();
        Mail::assertQueued(PlanUpgradedEmail::class, function ($mail) use ($user) {
            return $mail->user->id === $user->id
                && $mail->plan === 'pro';
        });
    }

    public function test_welcome_email_has_html_and_text_parts(): void
    {
        $user = User::factory()->create();
        $email = new WelcomeEmail($user);

        $this->assertNotNull($email->content()->view);
        $this->assertNotNull($email->content()->text);
        $this->assertEquals('emails.welcome', $email->content()->view);
        $this->assertEquals('emails.welcome-text', $email->content()->text);
    }

    public function test_plan_upgraded_email_has_html_and_text_parts(): void
    {
        $user = User::factory()->create();
        $email = new PlanUpgradedEmail($user, 'pro', 'INV-TEST-001');

        $this->assertNotNull($email->content()->view);
        $this->assertNotNull($email->content()->text);
        $this->assertEquals('emails.plan-upgraded', $email->content()->view);
        $this->assertEquals('emails.plan-upgraded-text', $email->content()->text);
    }

    public function test_plan_upgraded_email_subject_includes_invoice_id(): void
    {
        $user = User::factory()->create();
        $email = new PlanUpgradedEmail($user, 'pro', 'INV-12345');

        $this->assertStringContainsString('INV-12345', $email->envelope()->subject);
        $this->assertStringContainsString('Pro', $email->envelope()->subject);
    }

    public function test_plan_upgraded_email_implements_should_queue(): void
    {
        $this->assertContains(
            \Illuminate\Contracts\Queue\ShouldQueue::class,
            class_implements(PlanUpgradedEmail::class)
        );
    }

    public function test_welcome_email_implements_should_queue(): void
    {
        $this->assertContains(
            \Illuminate\Contracts\Queue\ShouldQueue::class,
            class_implements(WelcomeEmail::class)
        );
    }
}
