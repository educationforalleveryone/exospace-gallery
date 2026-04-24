<?php

namespace App\Http\Controllers;

use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class TeamInvitationController extends Controller
{
    /**
     * Show the invitation acceptance page.
     */
    public function show(string $token)
    {
        $invitation = TeamInvitation::where('token', $token)->firstOrFail();

        if ($invitation->isExpired()) {
            return view('teams.invitation-expired', compact('invitation'));
        }

        $team = $invitation->team;

        // Check if an account already exists for the invited email.
        // If yes, the view will show Login as the only option (no Register).
        $accountExists = User::where('email', $invitation->email)->exists();

        return view('teams.invitation', compact('invitation', 'team', 'token', 'accountExists'));
    }

    /**
     * Accept the invitation.
     */
    public function accept(Request $request, string $token): RedirectResponse
    {
        $invitation = TeamInvitation::where('token', $token)->firstOrFail();

        if ($invitation->isExpired()) {
            return redirect()->route('admin.teams.index')
                             ->withErrors(['invitation' => 'This invitation has expired.']);
        }

        if (! Auth::check()) {
            return redirect()
                ->to(route('login') . '?redirect=' . urlencode(route('team-invitations.show', $token)))
                ->with('status', 'Please log in to accept the team invitation.');
        }

        $user = Auth::user();

        if (strtolower($user->email) !== strtolower($invitation->email)) {
            return redirect()->route('team-invitations.show', $token)
                             ->withErrors(['email' => "This invitation was sent to {$invitation->email}. Please log in with that account."]);
        }

        $team = $invitation->team;

        if ($team->hasMember($user)) {
            $invitation->delete();
            return redirect()->route('admin.teams.show', $team)
                             ->with('status', "You're already a member of {$team->name}.");
        }

        $team->members()->attach($user->id, ['role' => $invitation->role]);
        $user->switchTeam($team);

        // If their email isn't verified yet, verify it now — invitation proves ownership
        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
        }

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