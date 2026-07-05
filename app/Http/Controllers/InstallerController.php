<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * P0-5 FIX (audit): This file has been NEUTRALIZED.
 *
 * The original InstallerController contained Artisan::call('migrate:fresh')
 * on line 86 — a single accidental route registration would wipe the entire
 * production database. The file was "removed" from routes in task C08 but
 * the physical file was left on disk, creating a standing catastrophic-risk
 * footgun.
 *
 * This stub replaces the dangerous controller with a harmless shell that:
 *   - Keeps the same namespace/class name (so autoloading doesn't break
 *     if any stale reference exists)
 *   - Contains NO dangerous methods (no migrate:fresh, no .env writing,
 *     no session-controlled DB credentials)
 *   - Returns 404 for any method call (in case a route is accidentally
 *     re-registered)
 *
 * ACTION REQUIRED: The founder should physically delete this file via:
 *     git rm app/Http/Controllers/InstallerController.php
 *
 * The associated views (resources/views/installer/*) and the public
 * installer directory (public/install/) have already been deleted.
 *
 * First-run setup is done via artisan commands (see routes/web.php comment
 * at line 53 for the documented procedure).
 */
class InstallerController extends Controller
{
    public function finalize(): Response
    {
        return response()->noContent(404);
    }

    public function __call($method, $parameters): Response
    {
        return response()->noContent(404);
    }
}
