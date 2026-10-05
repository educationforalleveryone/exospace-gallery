<?php

namespace App\Http\Controllers;

use App\Services\ReleaseCalendar;
use Illuminate\Support\Arr;
use Illuminate\View\View;

class ChangelogController extends Controller
{
    public function show(): View
    {
        // Explicit allowlist projection: only the curated public keys reach
        // the view. Team-only metadata (internal notes, rollout/rollback
        // plans) must never render here even if someone later adds it to a
        // release entry — it belongs to ReleaseCalendar::internalNotes().
        $releases = array_map(
            static fn (array $release): array => Arr::only($release, ReleaseCalendar::PUBLIC_KEYS),
            ReleaseCalendar::releases(),
        );

        return view('pages.changelog', compact('releases'));
    }
}
