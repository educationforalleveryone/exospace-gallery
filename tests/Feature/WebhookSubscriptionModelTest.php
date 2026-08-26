<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Models\WebhookSubscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * ITERATION 10 — webhook_subscriptions migration + model shape.
 *
 * Coverage: the schema + mutators + scopes shipped in the Iter-10
 * migration. This is a small contract test — the dispatch fan-out
 * + UI flows have their own dedicated test files.
 *
 * Run: php artisan test --filter=WebhookSubscriptionModelTest
 */
class WebhookSubscriptionModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_table_exists_with_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('webhook_subscriptions'));

        $columns = Schema::getColumnListing('webhook_subscriptions');
        $expected = ['id', 'event_type', 'target_url', 'secret', 'is_active', 'added_by', 'created_at', 'updated_at'];

        foreach ($expected as $col) {
            $this->assertContains($col, $columns, "Missing column: {$col}");
        }
    }

    public function test_unique_index_on_event_type_plus_target_url(): void
    {
        WebhookSubscription::create([
            'event_type' => 'billing.recipient_added',
            'target_url' => 'https://example.com/dup',
            'secret'     => null,
            'is_active'  => true,
            'added_by'   => null,
        ]);

        // Same event_type + same URL → unique violation.
        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);
        WebhookSubscription::create([
            'event_type' => 'billing.recipient_added',
            'target_url' => 'https://example.com/dup',
            'secret'     => null,
            'is_active'  => true,
            'added_by'   => null,
        ]);
    }

    public function test_same_url_different_events_allowed(): void
    {
        // A security team receiving BOTH billing.recipient_added and
        // _removed at the same URL is one row each (not a duplicate).
        WebhookSubscription::create([
            'event_type' => 'billing.recipient_added',
            'target_url' => 'https://sec.example.com/hook',
            'secret'     => null,
            'is_active'  => true,
            'added_by'   => null,
        ]);
        WebhookSubscription::create([
            'event_type' => 'billing.recipient_removed',
            'target_url' => 'https://sec.example.com/hook', // same URL
            'secret'     => null,
            'is_active'  => true,
            'added_by'   => null,
        ]);

        $this->assertSame(2, WebhookSubscription::count());
    }

    public function test_event_type_mutator_lowercases(): void
    {
        $sub = WebhookSubscription::create([
            'event_type' => 'GALLERY.PUBLISHED',
            'target_url' => 'https://example.com/hook',
            'secret'     => null,
            'is_active'  => true,
            'added_by'   => null,
        ]);

        $sub->refresh();
        $this->assertSame('gallery.published', $sub->event_type);
    }

    public function test_target_url_mutator_trims_but_preserves_case(): void
    {
        $sub = WebhookSubscription::create([
            'event_type' => 'gallery.published',
            'target_url' => '  https://Example.com/PathCase  ',
            'secret'     => null,
            'is_active'  => true,
            'added_by'   => null,
        ]);

        $sub->refresh();
        // Trimmed, but case preserved (URL path is case-sensitive).
        $this->assertSame('https://Example.com/PathCase', $sub->target_url);
    }

    public function test_for_event_scope_returns_only_active_subscriptions_for_event(): void
    {
        WebhookSubscription::create(['event_type' => 'gallery.published', 'target_url' => 'https://a.example.com', 'secret' => null, 'is_active' => true, 'added_by' => null]);
        WebhookSubscription::create(['event_type' => 'gallery.published', 'target_url' => 'https://b.example.com', 'secret' => null, 'is_active' => false, 'added_by' => null]);
        WebhookSubscription::create(['event_type' => 'billing.recipient_added', 'target_url' => 'https://c.example.com', 'secret' => null, 'is_active' => true, 'added_by' => null]);

        $active = WebhookSubscription::forEvent('gallery.published');

        $this->assertCount(1, $active);
        $this->assertSame('https://a.example.com', $active->first()->target_url);
    }

    public function test_added_by_foreign_key_null_on_delete(): void
    {
        $admin = User::factory()->create(['is_super_admin' => true]);
        $sub = WebhookSubscription::create([
            'event_type' => 'gallery.published',
            'target_url' => 'https://example.com/hook',
            'secret'     => null,
            'is_active'  => true,
            'added_by'   => $admin->id,
        ]);

        // Delete the admin — the subscription should survive with added_by = null.
        $admin->delete();

        $sub->refresh();
        $this->assertNull($sub->added_by);
    }
}
