<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * TeamInvitationController.
 *
 * ITERATION-004 FIXES (audit D-6 + D-7):
 *
 *   D-6 — Tokens are now HASHED at rest.
 *     All `TeamInvitation::where('token', $token)` calls now use
 *     `TeamInvitation::findByToken($token)` which hashes the plaintext
 *     token before querying. The email link contains the plaintext token;
 *     the DB stores the sha256 hash. A DB dump no longer reveals usable
 *     tokens.
 *
 *   D-7 — $accountExists no longer leaked to unauthenticated visitors.
 *     Previously, show() computed $accountExists = User::where('email',
 *     $invitation->email)->exists() and passed it to the view even for
 *     unauthenticated visitors. An attacker with a token (from a forwarded
 *     email) could determine whether any email address has an account by
 *     re-using the invitation form. Now, $accountExists is only set when
 *     the user is logged in (the auth state itself answers the question).
 *
 * PRESERVED FROM PRIOR VERSION:
 *   - show() is public (no auth) — recipient needs to see the invitation
 *     before logging in. Team name is not revealed to unauthenticated
 *     visitors.
 *   - accept() requires auth + email match.
 *   - decline() requires auth + email match.
 */
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
     *
     * D-7 FIX (Iter-004): $accountExists is no longer computed or passed
     * to the view for unauthenticated visitors. Previously it was, which
     * leaked account existence (email enumeration).
     */
    public function show(string $token)
    {
        // D-6 FIX: hash the plaintext token before querying
        $invitation = TeamInvitation::findByToken($token);

        if (! $invitation) {
            abort(404);
        }

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
            // D-7 FIX: Do NOT compute $accountExists for unauthenticated
            // visitors. Previously: $accountExists = User::where('email',
            // $invitation->email)->exists(); — this leaked account existence.
            // Now: $accountExists is only set (to true) when the user is
            // logged in and their email matches. For unauthenticated visitors,
            // it's null (the view should handle null gracefully).
            $accountExists = null;
            $canAccept = false;
        }

        return view('teams.invitation', compact('invitation', 'team', 'token', 'accountExists', 'canAccept'));
    }

    /**
     * Accept the invitation.
     */
    public function accept(Request $request, string $token): RedirectResponse
    {
        // D-6 FIX: hash the plaintext token before querying
        $invitation = TeamInvitation::findByToken($token);

        if (! $invitation) {
            abort(404);
        }

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
        // D-6 FIX: hash the plaintext token before querying
        $invitation = TeamInvitation::findByToken($token);

        if (! $invitation) {
            abort(404);
        }

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
