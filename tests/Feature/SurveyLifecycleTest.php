<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\SurveyResponse;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SurveyLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_submit_nps(): void
    {
        $this->postJson(route('survey.nps'), ['score' => 9])
            ->assertUnauthorized();

        $this->post(route('survey.nps'), ['score' => 9])
            ->assertRedirect(route('login'));

        $this->assertSame(0, SurveyResponse::count());
    }

    public function test_nps_score_validation_boundaries(): void
    {
        $user = User::factory()->create();

        // Out of range and non-integer scores are rejected.
        foreach ([-1, 11, 100, 'abc'] as $score) {
            $this->actingAs($user)
                ->postJson(route('survey.nps'), ['score' => $score])
                ->assertStatus(422)
                ->assertJsonValidationErrors(['score']);
        }

        // Both endpoints of the 0-10 scale are accepted (separate users —
        // one NPS response per user).
        $this->actingAs(User::factory()->create())
            ->postJson(route('survey.nps'), ['score' => 0])
            ->assertOk();
        $this->actingAs(User::factory()->create())
            ->postJson(route('survey.nps'), ['score' => 10])
            ->assertOk();
    }

    public function test_nps_feedback_length_boundary(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson(route('survey.nps'), ['score' => 9, 'feedback' => str_repeat('x', 2001)])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['feedback']);

        $this->actingAs($user)
            ->postJson(route('survey.nps'), ['score' => 9, 'feedback' => str_repeat('x', 2000)])
            ->assertOk();
    }

    public function test_json_submission_persists_response_with_contract(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson(route('survey.nps'), ['score' => 9, 'feedback' => 'Loving the viewer.'])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Thank you for your feedback!');

        $row = SurveyResponse::firstOrFail();
        $this->assertSame($user->id, $row->user_id);
        $this->assertSame('nps', $row->survey_type);
        $this->assertSame(9, $row->score);
        $this->assertSame('Loving the viewer.', $row->feedback);
        $this->assertNotNull($row->responded_at);
        $this->assertSame('promoter', $row->npsCategory());
    }

    public function test_second_nps_submission_is_rejected_for_the_same_user(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson(route('survey.nps'), ['score' => 9])
            ->assertOk();

        $this->actingAs($user)
            ->postJson(route('survey.nps'), ['score' => 5])
            ->assertStatus(422)
            ->assertJsonPath('error', 'You have already submitted an NPS response.');

        $this->actingAs($user)
            ->post(route('survey.nps'), ['score' => 5])
            ->assertRedirect()
            ->assertSessionHas('info', 'You have already submitted a survey response.');

        $this->assertSame(1, SurveyResponse::count());
    }

    public function test_one_response_per_user_per_type_is_enforced_by_the_database(): void
    {
        $user = User::factory()->create();
        SurveyResponse::create([
            'user_id' => $user->id,
            'survey_type' => 'nps',
            'score' => 9,
            'triggered_at' => now(),
            'responded_at' => now(),
        ]);

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);

        SurveyResponse::create([
            'user_id' => $user->id,
            'survey_type' => 'nps',
            'score' => 5,
            'triggered_at' => now(),
            'responded_at' => now(),
        ]);
    }

    public function test_migration_collapses_historical_duplicates_keeping_latest(): void
    {
        $user = User::factory()->create();

        // Recreate the pre-migration state: drop the unique index, insert
        // the duplicates the check-then-insert race used to produce.
        Schema::table('survey_responses', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'survey_type']);
        });

        $older = SurveyResponse::create([
            'user_id' => $user->id, 'survey_type' => 'nps', 'score' => 2, 'responded_at' => now()->subDays(2),
        ]);
        $newer = SurveyResponse::create([
            'user_id' => $user->id, 'survey_type' => 'nps', 'score' => 9, 'responded_at' => now()->subDay(),
        ]);
        SurveyResponse::create([
            'user_id' => $user->id, 'survey_type' => 'nps', 'score' => 5, 'responded_at' => now(),
        ]);

        require_once database_path('migrations/2026_10_05_000001_add_unique_survey_per_user_to_survey_responses_table.php');
        $migration = require database_path('migrations/2026_10_05_000001_add_unique_survey_per_user_to_survey_responses_table.php');
        $migration->up();

        $rows = SurveyResponse::where('user_id', $user->id)->orderByDesc('responded_at')->get();
        $this->assertSame(1, $rows->count(), 'Duplicates must be collapsed to the latest response.');
        $this->assertSame(5, $rows[0]->score, 'The kept row must be the most recent response.');
        $this->assertNotContains($older->id, [$rows[0]->id]);
        $this->assertNotContains($newer->id, [$rows[0]->id]);

        // And the constraint is back in force.
        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);
        SurveyResponse::create([
            'user_id' => $user->id, 'survey_type' => 'nps', 'score' => 1, 'responded_at' => now(),
        ]);
    }

    public function test_nps_dashboard_requires_super_admin(): void
    {
        $user = User::factory()->create();

        // Guest probes first — actingAs persists for later requests in the test.
        $this->get(route('super.nps.index'))->assertRedirect(route('login'));

        $this->actingAs($user)->get(route('super.nps.index'))->assertForbidden();
    }

    public function test_nps_dashboard_aggregates_promoters_passives_and_detractors(): void
    {
        $scores = [10, 9, 9, 8, 7, 6, 3, 0];

        foreach ($scores as $score) {
            $respondent = User::factory()->create();
            SurveyResponse::create([
                'user_id' => $respondent->id,
                'survey_type' => 'nps',
                'score' => $score,
                'responded_at' => now(),
            ]);
        }

        $admin = User::factory()->superAdmin()->create([
            'google2fa_secret' => encrypt('ABCDEFGHIJKLMNOP'),
            'mfa_enabled_at' => now(),
        ]);

        $response = $this->actingAs($admin)
            ->withSession([
                'mfa_verified' => true,
                'mfa_verified_at' => now()->timestamp,
                'mfa_verified_user_id' => $admin->id,
            ])
            ->get(route('super.nps.index'));

        $response->assertOk();
        $response->assertSee('Promoters: 3');
        $response->assertSee('Passives: 2');
        $response->assertSee('Detractors: 3');
        $response->assertSee('Based on 8 responses');
        // NPS = round(((3 - 3) / 8) * 100) = 0; avg = (10+9+9+8+7+6+3+0)/8 = 6.5
        $response->assertSee('Avg score: 6.5/10');
        $this->assertSame(
            8,
            SurveyResponse::where('survey_type', 'nps')->whereNotNull('responded_at')->count(),
        );
    }

    public function test_nps_dashboard_math_for_positive_score_range(): void
    {
        foreach ([10, 10, 9] as $score) {
            SurveyResponse::create([
                'user_id' => User::factory()->create()->id,
                'survey_type' => 'nps',
                'score' => $score,
                'responded_at' => now(),
            ]);
        }
        SurveyResponse::create([
            'user_id' => User::factory()->create()->id,
            'survey_type' => 'nps',
            'score' => 1,
            'responded_at' => now(),
        ]);

        $admin = User::factory()->superAdmin()->create([
            'google2fa_secret' => encrypt('ABCDEFGHIJKLMNOP'),
            'mfa_enabled_at' => now(),
        ]);

        $response = $this->actingAs($admin)
            ->withSession([
                'mfa_verified' => true,
                'mfa_verified_at' => now()->timestamp,
                'mfa_verified_user_id' => $admin->id,
            ])
            ->get(route('super.nps.index'));

        $response->assertOk();
        // NPS = round(((3 - 1) / 4) * 100) = +50 — the view prefixes positives.
        $response->assertSee('+50');
        $response->assertSee('Promoters: 3');
        $response->assertSee('Detractors: 1');
    }
}
