<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AdminAuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminAuditChainTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_recorded_row_is_chained_to_its_predecessor(): void
    {
        $admin = User::factory()->create();
        $target = User::factory()->create();

        $this->actingAs($admin);

        AdminAuditLog::record('plan_changed', $target, ['plan' => 'pro']);
        AdminAuditLog::record('plan_changed', $target, ['plan' => 'free']);
        AdminAuditLog::record('user_banned', $target, ['sessions_purged' => true]);

        $rows = AdminAuditLog::query()->orderBy('id')->get();

        $this->assertCount(3, $rows);
        $this->assertNotNull($rows[0]->chain_hash, 'Every recorded row must carry a chain hash.');

        // The chain is verifiable end to end.
        $this->assertSame(0, AdminAuditLog::verifyChain());

        // Each row's hash depends on the previous row's hash — the second row
        // must change if the first row's content is different.
        $second = $rows[1];
        $recomputedWithDifferentTail = self::recomputeForTest($second, $rows[0]->chain_hash);
        $this->assertSame($second->chain_hash, $recomputedWithDifferentTail);
        $this->assertNotSame(
            $second->chain_hash,
            self::recomputeForTest($second, str_repeat('a', 64)),
            'A forged predecessor hash must yield a different row hash.'
        );
    }

    public function test_the_first_chained_row_anchors_the_chain(): void
    {
        $target = User::factory()->create();

        AdminAuditLog::record('email_changed', $target, []);

        $row = AdminAuditLog::query()->orderBy('id')->first();

        $this->assertNotNull($row->chain_hash);
        $this->assertSame(
            $row->chain_hash,
            self::recomputeForTest($row, null),
            'With no predecessor the row must hash against the genesis marker.'
        );
    }

    public function test_payload_modification_breaks_the_chain(): void
    {
        $target = User::factory()->create();

        AdminAuditLog::record('plan_changed', $target, ['plan' => 'pro']);
        AdminAuditLog::record('plan_changed', $target, ['plan' => 'free']);

        $this->assertSame(0, AdminAuditLog::verifyChain());

        // Tamper with the first row's payload after the fact.
        DB::table('admin_audit_logs')
            ->orderBy('id')
            ->limit(1)
            ->update(['payload' => json_encode(['plan' => 'studio'])]);

        $this->assertSame(1, AdminAuditLog::verifyChain(), 'A modified row must be detected.');
    }

    public function test_backdating_breaks_the_chain(): void
    {
        $target = User::factory()->create();

        AdminAuditLog::record('plan_changed', $target, ['plan' => 'pro']);

        $this->assertSame(0, AdminAuditLog::verifyChain());

        // Rewrite the timestamp to cover the actor's tracks.
        DB::table('admin_audit_logs')->latest('id')->limit(1)->update([
            'created_at' => now()->subDays(10)->format('Y-m-d H:i:s'),
        ]);

        $this->assertSame(1, AdminAuditLog::verifyChain(), 'A backdated row must be detected.');
    }

    public function test_deleting_a_middle_row_breaks_the_chain_for_all_later_rows(): void
    {
        $target = User::factory()->create();

        AdminAuditLog::record('plan_changed', $target, ['plan' => 'pro']);
        AdminAuditLog::record('plan_changed', $target, ['plan' => 'free']);
        AdminAuditLog::record('plan_changed', $target, ['plan' => 'studio']);

        $middleId = AdminAuditLog::query()->orderBy('id')->skip(1)->first()->id;

        DB::table('admin_audit_logs')->where('id', $middleId)->delete();

        $this->assertSame(1, AdminAuditLog::verifyChain(), 'The successor of a deleted row must fail verification.');
    }

    public function test_legacy_rows_without_a_chain_hash_are_skipped(): void
    {
        $target = User::factory()->create();

        // A pre-chain row (written before the chain_hash column existed).
        DB::table('admin_audit_logs')->insert([
            'actor_id' => null,
            'action' => 'user_banned',
            'target_type' => User::class,
            'target_id' => $target->id,
            'payload' => json_encode(['plan' => 'free']),
            'ip' => '127.0.0.1',
            'created_at' => now()->subYear(),
        ]);

        AdminAuditLog::record('plan_changed', $target, ['plan' => 'pro']);

        $this->assertSame(0, AdminAuditLog::verifyChain(), 'Legacy rows must not fail verification; the chain restarts at the first hashed row.');
    }

    public function test_chain_hash_is_not_mass_assignable(): void
    {
        $target = User::factory()->create();

        AdminAuditLog::record('plan_changed', $target, []);

        $row = AdminAuditLog::query()->orderBy('id')->first();

        $this->assertNotContains('chain_hash', $row->getFillable());
    }

    private static function recomputeForTest(AdminAuditLog $row, ?string $previousHash): string
    {
        $reflection = new \ReflectionClass(AdminAuditLog::class);

        $method = $reflection->getMethod('expectedChainHash');
        $method->setAccessible(true);

        return $method->invoke(null, $row->getAttributes(), $previousHash);
    }
}
