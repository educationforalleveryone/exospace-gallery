<?php

namespace App\Http\Controllers;

use App\Models\TeamInvitation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class TeamInvitationController extends Controller
{
    /**
     * Show the invitation acceptance page.
     * Guest users are redirected to register/login first, then back here.
     */
    public function show(string $token)
    {
        $invitation = TeamInvitation::where('token', $token)->firstOrFail();

        if ($invitation->isExpired()) {
            return view('teams.invitation-expired', compact('invitation'));
        }

        $team = $invitation->team;

        return view('teams.invitation', compact('invitation', 'team', 'token'));
    }

    /**
     * Accept the invitation.
     * Note: No auth middleware on this route — we redirect to login here
     * rather than letting the middleware return a 405 or broken redirect.
     */
    public function accept(Request $request, string $token): RedirectResponse
    {
        $invitation = TeamInvitation::where('token', $token)->firstOrFail();

        if ($invitation->isExpired()) {
            return redirect()->route('admin.teams.index')
                             ->withErrors(['invitation' => 'This invitation has expired.']);
        }

        // Not logged in — redirect to login, then back to the show page
        // (they'll click Accept again once authenticated)
        if (! Auth::check()) {
            return redirect()->route('login')
                             ->with('status', 'Please log in to accept the team invitation.')
                             ->with('invitation', $token);
        }

        $user = Auth::user();

        // Make sure the logged-in email matches the invited email
        if (strtolower($user->email) !== strtolower($invitation->email)) {
            return redirect()->route('admin.teams.index')
                             ->withErrors(['invitation' => "This invitation was sent to {$invitation->email}. Please log in with that account."]);
        }

        $team = $invitation->team;

        // Already a member? Just delete the invite and redirect
        if ($team->hasMember($user)) {
            $invitation->delete();
            return redirect()->route('admin.teams.show', $team)
                             ->with('status', "You're already a member of {$team->name}.");
        }

        // Add to team
        $team->members()->attach($user->id, ['role' => $invitation->role]);

        // Switch context to the new team
        $user->switchTeam($team);

        // Clean up the invitation
        $invitation->delete();

        return redirect()->route('admin.teams.show', $team)
                         ->with('status', "Welcome to {$team->name}! You've joined as {$invitation->role}.");
    }

    /**
     * Decline the invitation.
     */
    public function decline(string $token): RedirectResponse
    {
        $invitation = TeamInvitation::where('token', $token)->firstOrFail();
        $invitation->delete();

        return redirect()->route('admin.dashboard')
                         ->with('status', 'Invitation declined.');
    }
}