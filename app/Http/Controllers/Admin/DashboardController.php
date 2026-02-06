<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    /**
     * Display the admin dashboard.
     */
    public function index(): View
    {
        $user = auth()->user();
        
        // Calculate onboarding metrics
        $galleriesCount = $user->galleries()->count();
        $totalViews = $user->galleries()->sum('view_count');
        
        // Onboarding is complete only if:
        // 1. User has created at least 1 gallery, AND
        // 2. Their galleries have received at least 1 view (meaning they shared it)
        $onboardingComplete = ($galleriesCount > 0 && $totalViews > 0);
        
        // Pass these variables to the view
        return view('admin.dashboard', compact('galleriesCount', 'totalViews', 'onboardingComplete'));
    }
}