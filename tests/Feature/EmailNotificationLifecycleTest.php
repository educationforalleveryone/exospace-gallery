<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\EventRsvpNotification;
use App\Mail\FirstGalleryCreatedEmail;
use App\Mail\PlanExpiringSoon;
use App\Mail\TeamInvitationMail;
use App\Models\Gallery;
use App\Models\GalleryScheduleEvent;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class EmailNotificationLifecycleTest extends TestCase
{
    use RefreshDatabase;

    // ── Dynamic content in subject lines ──────────────────────────────

    public function test_rsvp_notification_subject_never_contains_control_characters(): void
    {
        $user = User::factory()->create();
        $gallery = Gallery::create([
            'user_id'     => $user->id,
            'title'       => 'Event Show',
            'slug'        => 'event-show-' . uniqid(),
            'is_active'   => true,
        ]);
        $event = GalleryScheduleEvent::create([
            'gallery_id' => $gallery->id,
            'title'      => "Opening\r\nBcc: victim@example.net",
            'type'       => 'opening',
            'starts_at'  => now()->addDays(3),
            'is_active'  => true,
        ]);

        $mail = new EventRsvpNotification($gallery, $event, [
            'name'  => "Eve\r\nX-Injected: yes",
            'email' => 'visitor@example.com',
        ]);

        $subject = $mail->envelope()->subject;

        // Control characters are what enable header injection — flattened
        // to spaces, the injected text becomes inert subject content.
        $this->assertStringNotContainsString("\r", $subject);
        $this->assertStringNotContainsString("\n", $subject);
        $this->assertStringContainsString('Eve X-Injected: yes', $subject);
    }

    public function test_team_invitation_subject_never_contains_control_characters(): void
    {
        $owner = User::factory()->create();
        $team = Team::create([
            'owner_id'   => $owner->id,
            'name'       => "Evil\r\nTeam",
        ]);
        $invitation = TeamInvitation::create([
            'team_id'    => $team->id,
            'email'      => 'invitee@example.com',
            'role'       => 'editor',
            'token'      => 'hash',
            'expires_at' => now()->addDays(7),
        ]);

        $subject = (new TeamInvitationMail($invitation, 'plaintext'))->envelope()->subject;

        $this->assertStringNotContainsString("\r", $subject);
        $this->assertStringNotContainsString("\n", $subject);
        $this->assertStringContainsString('Evil Team', $subject);
    }

    public function test_first_gallery_subject_never_contains_control_characters(): void
    {
        $user = User::factory()->create();
        $gallery = Gallery::create([
            'user_id'     => $user->id,
            'title'       => "My\r\nGallery",
            'slug'        => 'my-gallery-' . uniqid(),
            'is_active'   => true,
        ]);

        $subject = (new FirstGalleryCreatedEmail($user, $gallery))->envelope()->subject;

        $this->assertStringNotContainsString("\r", $subject);
        $this->assertStringNotContainsString("\n", $subject);
    }

    // ── Plan expiry emails render whole days, never fractions ─────────

    public function test_plan_expiring_subject_uses_whole_days(): void
    {
        $user = User::factory()->pro()->create([
            'plan_expires_at' => now()->addDays(5)->addHours(6),
        ]);

        $subject = (new PlanExpiringSoon($user))->envelope()->subject;

        $this->assertStringContainsString('in 6 days', $subject);
        $this->assertStringNotContainsString('5.', $subject);
    }

    public function test_plan_expiring_body_uses_whole_days_and_formats_expiry_date(): void
    {
        $user = User::factory()->pro()->create([
            'plan_expires_at' => now()->addDays(5)->addHours(6),
        ]);

        $mail = new PlanExpiringSoon($user);
        $html = $mail->render();

        $this->assertStringContainsString('6 days', $html);
        $this->assertStringContainsString('expires on ' . $user->plan_expires_at->format('M j, Y'), $html);
    }

    public function test_plan_expiring_survives_a_cleared_expiry_date(): void
    {
        // The queued mailable re-fetches the user; a renewal may have nulled
        // plan_expires_at between enqueue and render.
        $user = User::factory()->pro()->create(['plan_expires_at' => null]);

        $mail = new PlanExpiringSoon($user);

        $this->assertStringContainsString('expires soon', $mail->envelope()->subject);
        $this->assertNotNull($mail->render());
    }

    // ── Plain-text alternatives ───────────────────────────────────────

    public function test_team_invitation_email_has_html_and_text_parts(): void
    {
        $owner = User::factory()->create();
        $team = Team::create([
            'owner_id'    => $owner->id,
            'name'        => 'Studio Team',
        ]);
        $invitation = TeamInvitation::create([
            'team_id'    => $team->id,
            'email'      => 'invitee@example.com',
            'role'       => 'viewer',
            'token'      => 'hash',
            'expires_at' => now()->addDays(7),
        ]);

        $mail = new TeamInvitationMail($invitation, 'plaintext');

        $this->assertEquals('emails.team-invitation', $mail->content()->view);
        $this->assertEquals('emails.team-invitation-text', $mail->content()->text);
    }

    public function test_team_invitation_text_part_renders_invitation_link(): void
    {
        $owner = User::factory()->create();
        $team = Team::create([
            'owner_id'    => $owner->id,
            'name'        => 'Studio Team',
        ]);
        $invitation = TeamInvitation::create([
            'team_id'    => $team->id,
            'email'      => 'invitee@example.com',
            'role'       => 'viewer',
            'token'      => 'hash',
            'expires_at' => now()->addDays(7),
        ]);
        $invitation->plaintext_token = 'plaintext';

        $mail = new TeamInvitationMail($invitation, 'plaintext');
        $link = $mail->invitationLink();

        $rendered = view('emails.team-invitation-text', [
            'invitation'     => $invitation,
            'invitationLink' => $link,
        ])->render();

        $this->assertStringContainsString($link, $rendered);
        $this->assertStringContainsString('Studio Team', $rendered);
    }

    // ── Verification URL generation restores URL-generator state ──────

    public function test_verification_email_uses_configured_app_url_and_restores_url_state(): void
    {
        config(['app.url' => 'https://exospace.gallery']);

        // Simulate a request context rooted on a different host.
        URL::forceRootUrl('https://preview.example.com');
        URL::forceScheme('https');

        try {
            $user = User::factory()->unverified()->create();

            $notification = new \App\Notifications\Auth\VerifyEmail();
            $mail = $notification->toMail($user);
            $url = $mail->verificationUrl;

            $this->assertStringStartsWith('https://exospace.gallery/', $url);
        } finally {
            URL::forceRootUrl(null);
            URL::forceScheme(null);
        }

        $this->assertStringNotContainsString(
            'exospace.gallery',
            URL::to('/'),
            'The canonical root was leaked: forceRootUrl was not restored after generating the verification URL.'
        );
    }

    public function test_verification_email_uses_request_root_when_it_matches_app_url(): void
    {
        config(['app.url' => 'https://exospace.gallery']);
        URL::forceRootUrl('https://exospace.gallery');

        try {
            $user = User::factory()->unverified()->create();

            $url = (new \App\Notifications\Auth\VerifyEmail())->toMail($user)->verificationUrl;

            $this->assertStringStartsWith('https://exospace.gallery/', $url);
        } finally {
            URL::forceRootUrl(null);
            URL::forceScheme(null);
        }
    }
}
