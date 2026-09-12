<?php

declare(strict_types=1);

namespace App\Ops\Diagnostics;

use App\Ops\Models\OpsApplication;

interface RunsDiagnostics
{
    public function runDiagnostic(string $id, ?OpsApplication $application): DiagnosticResult;
}
