<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Ops\Diagnostics\DiagnosticEngine;
use App\Ops\Diagnostics\DiagnosticRegistry;
use App\Ops\Diagnostics\RunsDiagnostics;
use App\Ops\Support\ErrorClassifier;
use PHPUnit\Framework\TestCase;

/**
 * OpsCenter — Iteration 3 — the allow-list integrity tests.
 *
 * These pin the invariant that makes the "no arbitrary execution" promise
 * real: the ErrorClassifier may only recommend diagnostics that exist in
 * the registry, and the registry may only point at runner classes that
 * exist and implement the runner contract. If any of these fail, a button
 * on the dashboard is either dead or unimplemented — both are broken
 * promises to the operator.
 */
class OpsDiagnosticRegistryTest extends TestCase
{
    public function test_every_classifier_recommendation_is_a_real_diagnostic(): void
    {
        $recommended = ErrorClassifier::recommendedDiagnosticIds();

        $this->assertNotEmpty($recommended, 'The classifier must recommend at least one diagnostic.');

        foreach ($recommended as $id) {
            $this->assertTrue(
                DiagnosticRegistry::has($id),
                "ErrorClassifier recommends '{$id}' but DiagnosticRegistry does not declare it — the chip on the error page would be a dead button.",
            );
        }
    }

    public function test_registry_ids_are_unique(): void
    {
        $ids = array_keys(DiagnosticRegistry::all());

        $this->assertSame($ids, array_unique($ids), 'Duplicate diagnostic ids would create ambiguous run targets.');
    }

    public function test_every_registered_diagnostic_has_a_valid_runner_class(): void
    {
        foreach (DiagnosticRegistry::all() as $id => $definition) {
            $this->assertArrayHasKey('runner', $definition, "'{$id}' has no runner.");
            $this->assertTrue(
                class_exists($definition['runner']),
                "'{$id}' points at runner class '{$definition['runner']}' which does not exist.",
            );
            $this->assertTrue(
                is_a($definition['runner'], RunsDiagnostics::class, true),
                "'{$id}' runner '{$definition['runner']}' does not implement RunsDiagnostics.",
            );
        }
    }

    public function test_every_registered_diagnostic_is_well_formed(): void
    {
        foreach (DiagnosticRegistry::all() as $id => $definition) {
            $this->assertArrayHasKey('label', $definition, "'{$id}' lacks a label.");
            $this->assertArrayHasKey('group', $definition, "'{$id}' lacks a group.");
            $this->assertArrayHasKey('description', $definition, "'{$id}' lacks a description.");
            $this->assertArrayHasKey('scope', $definition, "'{$id}' lacks a scope.");
            $this->assertContains(
                $definition['scope'],
                [DiagnosticRegistry::SCOPE_SELF, DiagnosticRegistry::SCOPE_APPLICATION],
                "'{$id}' has an invalid scope '{$definition['scope']}'.",
            );
        }
    }

    public function test_groups_match_the_declared_groups(): void
    {
        $declaredGroups = array_values(array_unique(array_column(DiagnosticRegistry::all(), 'group')));

        $this->assertSame($declaredGroups, DiagnosticRegistry::groups(), 'groups() must mirror declaration order.');
    }

    public function test_runnable_recommendations_filter_out_unknown_ids(): void
    {
        // Defense against future drift: even if the classifier recommended a
        // bogus id, the UI helper must refuse to render it as runnable.
        $runnable = DiagnosticEngine::runnableRecommended([
            'database.connectivity',   // real
            'totally.made.up',         // bogus
            'database.connectivity',   // duplicate
        ]);

        $this->assertSame(['database.connectivity'], $runnable);
    }

    public function test_classifier_recommended_ids_are_exactly_a_subset_of_the_registry(): void
    {
        $registryIds = array_keys(DiagnosticRegistry::all());
        $recommended = ErrorClassifier::recommendedDiagnosticIds();

        $this->assertSame(
            [],
            array_diff($recommended, $registryIds),
        );
    }
}
