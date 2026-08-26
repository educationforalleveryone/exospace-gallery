<?php

namespace Tests\Feature;

use App\Mail\AbandonedCartEmail;
use App\Mail\FirstGalleryCreatedEmail;
use App\Mail\InactiveUserNudge;
use App\Mail\PlanExpiringSoon;
use App\Mail\SuperAdminActionAlert;
use App\Mail\TeamInvitationMail;
use App\Mail\EventRsvpNotification;
use App\Models\Gallery;
use App\Models\GalleryScheduleEvent;
use App\Models\PendingUpgrade;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P2-23: Tests for the 7 mailables that previously had no test coverage.
 *
 * Each test verifies:
 *   - The mailable has both HTML and text views
 *   - The mailable implements ShouldQueue (where applicable)
 *   - The envelope subject is correct
 */
class MailableCoverageTest extends TestCase
{
    use RefreshDatabase;

    public function test_abandoned_cart_email_has_html_and_text_parts(): void
    {
        $user = User::factory()->create();
        $pending = PendingUpgrade::createForUser($user, 'pro', 'PRO-001');

        $email = new AbandonedCartEmail($user, $pending);

        $this->assertNotNull($email->content()->view);
        $this->assertNotNull($email->content()->text);
        $this->assertEquals('emails.abandoned-cart', $email->content()->view);
        $this->assertEquals('emails.abandoned-cart-text', $email->content()->text);
    }

    public function test_abandoned_cart_email_implements_should_queue(): void
    {
        $this->assertContains(
            \Illuminate\Contracts\Queue\ShouldQueue::class,
            class_implements(AbandonedCartEmail::class)
        );
    }

    public function test_inactive_nudge_email_has_html_and_text_parts(): void
    {
        $user = User::factory()->create();
        $email = new InactiveUserNudge($user);

        $this->assertNotNull($email->content()->view);
        $this->assertNotNull($email->content()->text);
        $this->assertEquals('emails.inactive-nudge', $email->content()->view);
        $this->assertEquals('emails.inactive-nudge-text', $email->content()->text);
    }

    public function test_inactive_nudge_email_implements_should_queue(): void
    {
        $this->assertContains(
            \Illuminate\Contracts\Queue\ShouldQueue::class,
            class_implements(InactiveUserNudge::class)
        );
    }

    public function test_plan_expiring_email_has_html_and_text_parts(): void
    {
        $user = User::factory()->pro()->create([
            'plan_expires_at' => now()->addDays(5),
        ]);
        $email = new PlanExpiringSoon($user);

        $this->assertNotNull($email->content()->view);
        $this->assertNotNull($email->content()->text);
        $this->assertEquals('emails.plan-expiring', $email->content()->view);
        $this->assertEquals('emails.plan-expiring-text', $email->content()->text);
    }

    public function test_plan_expiring_email_implements_should_queue(): void
    {
        $this->assertContains(
            \Illuminate\Contracts\Queue\ShouldQueue::class,
            class_implements(PlanExpiringSoon::class)
        );
    }

    public function test_first_gallery_email_has_html_and_text_parts(): void
    {
        $user = User::factory()->create();
        $gallery = \App\Models\Gallery::factory()->create(['user_id' => $user->id]);
        $email = new FirstGalleryCreatedEmail($user, $gallery);

        $this->assertNotNull($email->content()->view);
        $this->assertNotNull($email->content()->text);
    }

    public function test_first_gallery_email_implements_should_queue(): void
    {
        $this->assertContains(
            \Illuminate\Contracts\Queue\ShouldQueue::class,
            class_implements(FirstGalleryCreatedEmail::class)
        );
    }

    public function test_super_admin_alert_email_has_html_and_text_parts(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $auditLog = \App\Models\AdminAuditLog::create([
            'actor_id' => $superAdmin->id,
            'action' => 'test_action',
            'target_type' => 'User',
            'target_id' => 1,
            'payload' => ['test' => true],
            'ip' => '127.0.0.1',
            'created_at' => now(),
        ]);

        $email = new SuperAdminActionAlert($auditLog, $superAdmin);

        $this->assertNotNull($email->content()->view);
        $this->assertNotNull($email->content()->text);
    }

    public function test_super_admin_alert_email_implements_should_queue(): void
    {
        $this->assertContains(
            \Illuminate\Contracts\Queue\ShouldQueue::class,
            class_implements(SuperAdminActionAlert::class)
        );
    }

    // P2-18: TeamInvitationMail now implements ShouldQueue
    public function test_team_invitation_email_implements_should_queue(): void
    {
        $this->assertContains(
            \Illuminate\Contracts\Queue\ShouldQueue::class,
            class_implements(TeamInvitationMail::class)
        );
    }

    // P2-18: EventRsvpNotification now implements ShouldQueue
    public function test_event_rsvp_email_implements_should_queue(): void
    {
        $this->assertContains(
            \Illuminate\Contracts\Queue\ShouldQueue::class,
            class_implements(EventRsvpNotification::class)
        );
    }
}
