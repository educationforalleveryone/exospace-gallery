<?php

namespace App\Http\Controllers;

use App\Services\ReleaseCalendar;
use Illuminate\View\View;

/**
 * M-21: Public changelog controller.
 *
 * Displays a user-friendly changelog at /changelog showing feature releases
 * and improvements. Unlike the internal CHANGELOG.md (which is file-level
 * developer notes), this page shows user-facing release notes — short,
 * feature-focused entries organized by version.
 *
 * ITERATION 6: the release data moved to App\Services\ReleaseCalendar so
 * the Master Control trend charts can annotate releases from the SAME
 * source (two copies of the release list would drift). This controller is
 * now a thin view-layer consumer — to add a release, add an entry at the
 * TOP of ReleaseCalendar::releases() (newest first). The version number
 * should match the iteration milestone (e.g. v1.7 = Iteration 017).
 */
class ChangelogController extends Controller
{
    public function show(): View
    {
        $releases = ReleaseCalendar::releases();

        return view('pages.changelog', compact('releases'));
    }
}
