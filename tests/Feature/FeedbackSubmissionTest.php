<?php

namespace Tests\Feature;

use App\Models\UserFeedback;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FeedbackSubmissionTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function guests_cannot_submit_feedback(): void
    {
        $this->post('/feedback', [
            'category' => 'bug',
            'message'  => 'Something broke',
        ])->assertRedirect(route('login'));

        $this->assertSame(0, UserFeedback::count());
    }

    #[Test]
    public function authenticated_user_can_submit_feedback(): void
    {
        $user = \App\Models\User::factory()->create();

        $response = $this->actingAs($user)
            ->post('/feedback', [
                'category' => 'bug',
                'message'  => 'The upload screen froze on me.',
            ], ['Referer' => 'https://exospace.gallery/dashboard']);

        $response->assertRedirect()->assertSessionHas('status');

        $feedback = UserFeedback::firstOrFail();
        $this->assertSame($user->id, $feedback->user_id);
        $this->assertSame('bug', $feedback->category);
        $this->assertSame('new', $feedback->status);
        $this->assertSame('https://exospace.gallery/dashboard', $feedback->page_url);
    }

    #[Test]
    public function feedback_rejects_unknown_categories(): void
    {
        $user = \App\Models\User::factory()->create();

        $this->actingAs($user)->post('/feedback', [
            'category' => 'not-a-category',
            'message'  => 'Hello',
        ])->assertSessionHasErrors('category');

        $this->assertSame(0, UserFeedback::count());
    }

    #[Test]
    public function feedback_rejects_empty_message(): void
    {
        $user = \App\Models\User::factory()->create();

        $this->actingAs($user)->post('/feedback', [
            'category' => 'bug',
        ])->assertSessionHasErrors('message');

        $this->assertSame(0, UserFeedback::count());
    }

    #[Test]
    public function feedback_survives_oversized_client_headers(): void
    {
        // page_url is a varchar(255) column; a Referer header longer than the
        // column must be capped, not fail the whole submission.
        $user = \App\Models\User::factory()->create();

        $response = $this->actingAs($user)
            ->post('/feedback', [
                'category' => 'feature_request',
                'message'  => 'Loved the new viewer.',
            ], [
                'Referer'    => 'https://exospace.gallery/gallery/' . str_repeat('long-segment-', 100),
                'User-Agent' => str_repeat('Mozilla/5.0 compatible; ', 100),
            ]);

        $response->assertRedirect()->assertSessionHas('status');

        $feedback = UserFeedback::firstOrFail();
        $this->assertSame('feature_request', $feedback->category);
        $this->assertSame('Loved the new viewer.', $feedback->message);
        $this->assertLessThanOrEqual(255, mb_strlen((string) $feedback->page_url));
        $this->assertStringStartsWith('https://exospace.gallery/gallery/', (string) $feedback->page_url);
        $this->assertLessThanOrEqual(1000, mb_strlen((string) $feedback->user_agent));
    }

    #[Test]
    public function feedback_json_client_gets_success_contract(): void
    {
        $user = \App\Models\User::factory()->create();

        $this->actingAs($user)
            ->postJson('/feedback', [
                'category' => 'praise',
                'message'  => 'Great tool!',
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Thank you for your feedback!');
    }

    #[Test]
    public function super_admin_triage_status_change_is_audited(): void
    {
        $user = \App\Models\User::factory()->create();
        $this->actingAs($user)
            ->post('/feedback', ['category' => 'bug', 'message' => 'Viewer flickers on Safari.'])
            ->assertRedirect();

        $feedback = UserFeedback::firstOrFail();

        $admin = \App\Models\User::factory()->superAdmin()->create([
            'email_verified_at' => now(),
            'google2fa_secret' => encrypt('ABCDEFGHIJKLMNOP'),
            'mfa_enabled_at' => now(),
        ]);

        $this->actingAs($admin)
            ->withSession([
                'auth.password_confirmed_at' => now()->timestamp,
                'mfa_verified' => true,
                'mfa_verified_at' => now()->timestamp,
                'mfa_verified_user_id' => $admin->id,
            ])
            ->patch(route('super.feedback.update-status', $feedback), ['status' => 'resolved'])
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertSame('resolved', $feedback->fresh()->status);

        $audit = \App\Models\AdminAuditLog::where('action', 'feedback.status_changed')
            ->where('actor_id', $admin->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($audit, 'Feedback triage is a super-admin action and must be audited.');
        $this->assertSame('new', $audit->payload['old_status']);
        $this->assertSame('resolved', $audit->payload['new_status']);
    }

    #[Test]
    public function feedback_json_validation_errors_follow_the_json_contract(): void
    {
        $user = \App\Models\User::factory()->create();

        $this->actingAs($user)
            ->postJson('/feedback', ['category' => 'nope'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['category']);
    }
}
