<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class TeamInvitationController extends Controller
{
    public function show(string $token)
    {
        // Hash the plaintext token before querying
        $invitation = TeamInvitation::findByToken($token);

        if (! $invitation) {
            abort(404);
        }

        if ($invitation->isExpired()) {
            return view('teams.invitation-expired', compact('invitation'));
        }

        if (Auth::check() && strtolower(Auth::user()->email) === strtolower($invitation->email)) {
            $team = $invitation->team;
            $accountExists = true;
            $canAccept = true;
        } else {
            $team = null;
            $accountExists = null;
            $canAccept = false;
        }

        return view('teams.invitation', compact('invitation', 'team', 'token', 'accountExists', 'canAccept'));
    }

    public function accept(Request $request, string $token): RedirectResponse
    {
        // Hash the plaintext token before querying
        $invitation = TeamInvitation::findByToken($token);

        if (! $invitation) {
            abort(404);
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

        $outcome = \Illuminate\Support\Facades\DB::transaction(function () use ($invitation, $user, $team) {
            $fresh = TeamInvitation::whereKey($invitation->getKey())->lockForUpdate()->first();

            if (! $fresh) {
                return $team->hasMember($user) ? 'member' : 'consumed';
            }

            if ($fresh->isExpired()) {
                return 'expired';
            }

            if ($team->hasMember($user)) {
                $fresh->delete();
                return 'member';
            }

            $team->members()->syncWithoutDetaching([$user->id => ['role' => $fresh->role]]);
            $user->switchTeam($team);

            // If their email isn't verified yet, verify it now — invitation proves ownership
            if (! $user->hasVerifiedEmail()) {
                $user->markEmailAsVerified();
            }

            $fresh->delete();

            return 'joined';
        });

        if ($outcome === 'joined') {
            return redirect()->route('admin.teams.show', $team)
                             ->with('status', "Welcome to {$team->name}! You've joined as {$invitation->role}.");
        }

        if ($outcome === 'member') {
            return redirect()->route('admin.teams.show', $team)
                             ->with('status', "You're already a member of {$team->name}.");
        }

        $message = $outcome === 'expired'
            ? 'This invitation has expired.'
            : 'This invitation is no longer valid.';

        return redirect()->route('admin.teams.index')
                         ->withErrors(['invitation' => $message]);
    }

    public function decline(Request $request, string $token): RedirectResponse
    {
        // Hash the plaintext token before querying
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
