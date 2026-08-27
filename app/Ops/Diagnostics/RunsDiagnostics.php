<?php

declare(strict_types=1);

namespace App\Ops\Diagnostics;

use App\Ops\Models\OpsApplication;

/**
 * OpsCenter — the contract every diagnostic runner implements (Iteration 3).
 *
 * A runner receives the diagnostic id (one class serves several related ids)
 * and the target application (null = the control plane host itself) and
 * returns a DiagnosticResult. Runners MUST:
 *
 *   - perform READ-ONLY work only (no writes to the database, no state
 *     mutations, no infrastructure changes — ever);
 *   - bound their own I/O (HTTP timeouts come from CoolifyApiClient/config;
 *     queries are single bounded statements);
 *   - degrade, never throw — an unexpected failure is returned as a failed
 *     or inconclusive finding, because the operator must still get an
 *     answer, not a stack trace;
 *   - avoid secrets by construction (no credentials, tokens or .env values
 *     in findings; the engine redacts defensively anyway).
 */
interface RunsDiagnostics
{
    public function runDiagnostic(string $id, ?OpsApplication $application): DiagnosticResult;
}
