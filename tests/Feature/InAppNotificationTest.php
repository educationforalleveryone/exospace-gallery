<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserNotification;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class InAppNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_persists_unread_notification_with_defaults(): void
    {
        $user = User::factory()->create();

        $notification = NotificationService::create($user, 'billing', 'Plan activated!');

        $this->assertNotNull($notification);
        $this->assertTrue($notification->exists);
        $this->assertSame('billing', $notification->type);
        $this->assertSame('Plan activated!', $notification->title);
        $this->assertNull($notification->body);
        $this->assertNull($notification->action_url);
        $this->assertNull($notification->action_label);
        $this->assertTrue($notification->isUnread());
        $this->assertNull($notification->read_at);
        $this->assertSame($user->id, $notification->user_id);
    }

    public function test_create_returns_null_instead_of_throwing_when_persistence_fails(): void
    {
        $user = User::factory()->create();

        // Billing flows must not break because a notification could not be
        // stored: the service swallows the failure and logs it.
        Schema::drop('user_notifications');

        $result = NotificationService::create($user, 'billing', 'Plan activated!');

        $this->assertNull($result);
    }

    public function test_mark_as_read_is_idempotent_and_preserves_first_timestamp(): void
    {
        $notification = UserNotification::factory()->create();

        NotificationService::markAsRead($notification);
        $firstReadAt = $notification->fresh()->read_at;

        $this->assertNotNull($firstReadAt);

        NotificationService::markAsRead($notification);

        $this->assertSame($firstReadAt->toIso8601String(), $notification->fresh()->read_at->toIso8601String());
    }

    public function test_mark_all_as_read_scopes_to_owner_and_returns_count(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();

        UserNotification::factory()->count(3)->for($owner, 'user')->create();
        UserNotification::factory()->for($other, 'user')->create();

        $marked = NotificationService::markAllAsRead($owner);

        $this->assertSame(3, $marked);
        $this->assertSame(0, NotificationService::unreadCount($owner));
        // Another user's notification must be untouched.
        $this->assertSame(1, NotificationService::unreadCount($other));
    }

    public function test_unread_count_counts_only_unread(): void
    {
        $user = User::factory()->create();

        UserNotification::factory()->count(2)->for($user, 'user')->create();
        UserNotification::factory()->read()->for($user, 'user')->create();

        $this->assertSame(2, NotificationService::unreadCount($user));
    }

    public function test_recent_orders_desc_and_respects_limit(): void
    {
        $user = User::factory()->create();

        $oldest = UserNotification::factory()->for($user, 'user')->create(['title' => 'oldest']);
        UserNotification::factory()->for($user, 'user')->create(['title' => 'middle']);
        $newest = UserNotification::factory()->for($user, 'user')->create(['title' => 'newest']);

        $recent = NotificationService::recent($user, 2);

        $this->assertSame(2, $recent->count());
        $this->assertSame('newest', $recent[0]->title);
        $this->assertSame('middle', $recent[1]->title);
        $this->assertNotContains('oldest', $recent->pluck('title')->all());

        $this->assertTrue($newest->created_at->gte($oldest->created_at));
    }

    public function test_guest_cannot_mark_notifications_read(): void
    {
        $notification = UserNotification::factory()->create();

        $this->post(route('notifications.read', $notification))
            ->assertRedirect(route('login'));

        $this->post(route('notifications.mark-all-read'))
            ->assertRedirect(route('login'));

        $this->assertTrue($notification->fresh()->isUnread());
    }

    public function test_non_owner_gets_403_and_notification_stays_unread(): void
    {
        $intruder = User::factory()->create();
        $notification = UserNotification::factory()->create();

        $this->actingAs($intruder)
            ->post(route('notifications.read', $notification))
            ->assertForbidden();

        $this->assertTrue($notification->fresh()->isUnread());
    }

    public function test_owner_marks_read_and_redirects_to_action_url(): void
    {
        $user = User::factory()->create();
        $notification = UserNotification::factory()->for($user, 'user')->create([
            'action_url' => '/billing',
        ]);

        $this->actingAs($user)
            ->post(route('notifications.read', $notification))
            ->assertRedirect('/billing');

        $this->assertFalse($notification->fresh()->isUnread());
    }

    public function test_owner_marks_read_without_action_url_redirects_back(): void
    {
        $user = User::factory()->create();
        $notification = UserNotification::factory()->for($user, 'user')->withoutAction()->create();

        $this->from('/profile')
            ->actingAs($user)
            ->post(route('notifications.read', $notification))
            ->assertRedirect('/profile');

        $this->assertFalse($notification->fresh()->isUnread());
    }

    public function test_mark_all_read_flashes_status_and_clears_badge(): void
    {
        $user = User::factory()->create();
        UserNotification::factory()->count(2)->for($user, 'user')->create();

        $this->actingAs($user)
            ->post(route('notifications.mark-all-read'))
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertSame(0, NotificationService::unreadCount($user));
    }

    public function test_nav_bell_renders_unread_badge_and_notification_content(): void
    {
        $user = User::factory()->create();
        UserNotification::factory()->count(9)->for($user, 'user')->create();
        // Created last so it lands inside the 5-most-recent dropdown window.
        UserNotification::factory()->for($user, 'user')->create([
            'title' => 'Studio plan activated!',
            'body' => 'Your Studio plan is now active.',
        ]);

        $html = $this->actingAs($user)->get(route('profile.edit'))->getContent();

        $this->assertStringContainsString('Studio plan activated!', $html);
        $this->assertStringContainsString('aria-label="Notifications"', $html);
        // 10 unread notifications must render the capped badge, not a raw 10.
        $this->assertStringContainsString('9+', $html);
    }

    public function test_nav_bell_renders_empty_state_without_badge_for_fresh_user(): void
    {
        $user = User::factory()->create();

        $html = $this->actingAs($user)->get(route('profile.edit'))->getContent();

        $this->assertStringContainsString('No notifications yet', $html);
        // The bell itself renders for every authenticated user; only the red
        // unread badge is conditional.
        $this->assertStringContainsString('aria-label="Notifications"', $html);
        $this->assertSame(0, substr_count($html, 'min-w-5 h-5 px-1 bg-red-500'));
    }

    public function test_cleanup_stale_prunes_old_read_notifications_but_keeps_unread_and_recent(): void
    {
        $user = User::factory()->create();

        $agedRead = UserNotification::factory()->for($user, 'user')->read()->create();
        $recentRead = UserNotification::factory()->for($user, 'user')->read()->create();
        $agedUnread = UserNotification::factory()->for($user, 'user')->create();

        // Backdate precisely: read_at drives retention, not created_at.
        DB::table('user_notifications')->where('id', $agedRead->id)->update([
            'read_at' => now()->subDays(91),
        ]);
        DB::table('user_notifications')->where('id', $recentRead->id)->update([
            'read_at' => now()->subDays(10),
        ]);
        DB::table('user_notifications')->where('id', $agedUnread->id)->update([
            'created_at' => now()->subDays(200),
        ]);

        $this->artisan('exospace:cleanup-stale')->assertExitCode(0);

        $this->assertDatabaseMissing('user_notifications', ['id' => $agedRead->id]);
        $this->assertDatabaseHas('user_notifications', ['id' => $recentRead->id]);
        // Unread notifications are never pruned, however old.
        $this->assertDatabaseHas('user_notifications', ['id' => $agedUnread->id]);
    }
}
