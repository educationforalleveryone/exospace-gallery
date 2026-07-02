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
     *
     * (Task H06 / audit H13) — show() is intentionally public (no auth)
     * because the recipient needs to see the invitation before logging in.
     * But we no longer reveal the team name to unauthenticated visitors.
     * The view shows "You've been invited to join a team" until they log
     * in with the matching email, at which point the team name is shown.
     */
    public function show(string $token)
    {
        $invitation = TeamInvitation::where('token', $token)->firstOrFail();

        if ($invitation->isExpired()) {
            return view('teams.invitation-expired', compact('invitation'));
        }

        // If the user is logged in AND their email matches the invited
        // email, show the full invitation (team name, role, etc.).
        // Otherwise, show a generic "you've been invited" page that
        // doesn't leak the team name.
        if (Auth::check() && strtolower(Auth::user()->email) === strtolower($invitation->email)) {
            $team = $invitation->team;
            $accountExists = true;
            $canAccept = true;
        } else {
            // Don't reveal the team name to someone who isn't the
            // invited recipient. Show a generic invitation page.
            $team = null;
            $accountExists = User::where('email', $invitation->email)->exists();
            $canAccept = false;
        }

        return view('teams.invitation', compact('invitation', 'team', 'token', 'accountExists', 'canAccept'));
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
     *
     * (Task H06 / audit H13) — previously this was unauthenticated: anyone
     * with a token (from a forwarded email, leaked URL, or referrer header)
     * could delete the invitation, blocking the legitimate recipient.
     *
     * Now requires auth AND the logged-in user's email must match the
     * invited email. Guests are redirected to login.
     */
    public function decline(Request $request, string $token): RedirectResponse
    {
        $invitation = TeamInvitation::where('token', $token)->firstOrFail();

        // Require auth — guests can't decline (they'd need to log in first)
        if (! Auth::check()) {
            return redirect()
                ->to(route('login') . '?redirect=' . urlencode(route('team-invitations.show', $token)))
                ->with('status', 'Please log in to decline the team invitation.');
        }

        $user = Auth::user();

        // Only the invited recipient can decline
        if (strtolower($user->email) !== strtolower($invitation->email)) {
            return redirect()->route('admin.dashboard')
                             ->withErrors(['invitation' => 'This invitation was sent to a different email address.']);
        }

        $invitation->delete();

        return redirect()->route('admin.dashboard')
                         ->with('status', 'Invitation declined.');
    }
}
