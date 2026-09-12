<?php

namespace App\Http\Controllers;

use App\Services\ReleaseCalendar;
use Illuminate\View\View;

class ChangelogController extends Controller
{
    public function show(): View
    {
        $releases = ReleaseCalendar::releases();

        return view('pages.changelog', compact('releases'));
    }
}
