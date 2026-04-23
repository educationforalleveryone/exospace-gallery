<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Mail\TeamInvitationMail;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\View\View;

class TeamController extends Controller
{
    // ── List all teams the user belongs to ────────────────────────────────

    public function index(): View
    {
        $user = Auth::user();

        $ownedTeams  = $user->ownedTeams()->withCount('members')->with('members')->get();
        $memberTeams = $user->teams()
                            ->where('owner_id', '!=', $user->id)
                            ->withCount('members')
                            ->with('owner')
                            ->get();

        return view('admin.teams.index', compact('ownedTeams', 'memberTeams'));
    }

    // ── Create a new team ─────────────────────────────────────────────────

    public function create(): View
    {
        return view('admin.teams.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name'        => 'required|string|max:100',
            'description' => 'nullable|string|max:500',
        ]);

        $team = Team::create([
            'owner_id'    => Auth::id(),
            'name'        => $validated['name'],
            'description' => $validated['description'] ?? null,
        ]);

        // Add owner as member with owner role
        $team->members()->attach(Auth::id(), ['role' => 'owner']);

        // Switch to new team
        Auth::user()->switchTeam($team);

        return redirect()->route('admin.teams.show', $team)
                         ->with('status', 'Team created! Invite your collaborators below.');
    }

    // ── Show team detail / member management ──────────────────────────────

    public function show(Team $team): View
    {
        $this->authorizeTeamAccess($team);

        $team->load(['members', 'owner', 'galleries' => fn($q) => $q->latest()->limit(5)]);
        $pendingInvitations = $team->invitations()->where('expires_at', '>', now())->get();
        $userRole = Auth::user()->teamRole($team);

        return view('admin.teams.show', compact('team', 'pendingInvitations', 'userRole'));
    }

    // ── Update team settings (owner only) ────────────────────────────────

    public function update(Request $request, Team $team): RedirectResponse
    {
        $this->authorizeOwner($team);

        $validated = $request->validate([
            'name'        => 'required|string|max:100',
            'description' => 'nullable|string|max:500',
        ]);

        $team->update($validated);

        return back()->with('status', 'Team settings updated.');
    }

    // ── Delete team (owner only) ──────────────────────────────────────────

    public function destroy(Team $team): RedirectResponse
    {
        $this->authorizeOwner($team);

        // If this was the active team for anyone, reset their current_team_id
        \App\Models\User::where('current_team_id', $team->id)
                        ->update(['current_team_id' => null]);

        $team->delete();

        return redirect()->route('admin.teams.index')
                         ->with('status', 'Team deleted.');
    }

    // ── Invite a member ───────────────────────────────────────────────────

    public function invite(Request $request, Team $team): RedirectResponse
    {
        $this->authorizeOwner($team);

        $validated = $request->validate([
            'email' => 'required|email|max:255',
            'role'  => 'required|in:editor,viewer',
        ]);

        // Can't invite yourself
        if ($validated['email'] === Auth::user()->email) {
            return back()->withErrors(['email' => 'You cannot invite yourself.']);
        }

        // Check if already a member
        $existingMember = \App\Models\User::where('email', $validated['email'])->first();
        if ($existingMember && $team->hasMember($existingMember)) {
            return back()->withErrors(['email' => 'This person is already a team member.']);
        }

        // Upsert invitation (reset token + expiry if re-inviting)
        $invitation = TeamInvitation::updateOrCreate(
            ['team_id' => $team->id, 'email' => $validated['email']],
            [
                'role'       => $validated['role'],
                'token'      => TeamInvitation::generateToken(),
                'expires_at' => now()->addDays(7),
            ]
        );

        Mail::to($validated['email'])->send(new TeamInvitationMail($invitation));

        return back()->with('status', "Invitation sent to {$validated['email']}.");
    }

    // ── Revoke a pending invitation ───────────────────────────────────────

    public function revokeInvitation(Team $team, TeamInvitation $invitation): RedirectResponse
    {
        $this->authorizeOwner($team);
        abort_unless($invitation->team_id === $team->id, 404);

        $invitation->delete();

        return back()->with('status', 'Invitation revoked.');
    }

    // ── Remove a member ───────────────────────────────────────────────────

    public function removeMember(Request $request, Team $team): RedirectResponse
    {
        $this->authorizeOwner($team);

        $validated = $request->validate(['user_id' => 'required|integer|exists:users,id']);

        // Cannot remove the owner
        if ($validated['user_id'] == $team->owner_id) {
            return back()->withErrors(['user_id' => 'Cannot remove the team owner.']);
        }

        $team->members()->detach($validated['user_id']);

        // Reset their current_team_id if it was this team
        \App\Models\User::where('id', $validated['user_id'])
                        ->where('current_team_id', $team->id)
                        ->update(['current_team_id' => null]);

        return back()->with('status', 'Member removed.');
    }

    // ── Update a member's role ────────────────────────────────────────────

    public function updateMemberRole(Request $request, Team $team): RedirectResponse
    {
        $this->authorizeOwner($team);

        $validated = $request->validate([
            'user_id' => 'required|integer|exists:users,id',
            'role'    => 'required|in:editor,viewer',
        ]);

        if ($validated['user_id'] == $team->owner_id) {
            return back()->withErrors(['role' => 'Cannot change the owner\'s role.']);
        }

        $team->members()->updateExistingPivot($validated['user_id'], ['role' => $validated['role']]);

        return back()->with('status', 'Member role updated.');
    }

    // ── Leave a team ──────────────────────────────────────────────────────

    public function leave(Team $team): RedirectResponse
    {
        $user = Auth::user();

        if ($team->isOwner($user)) {
            return back()->withErrors(['team' => 'Owners cannot leave their own team. Transfer ownership or delete the team.']);
        }

        $team->members()->detach($user->id);

        if ($user->current_team_id === $team->id) {
            $user->forceFill(['current_team_id' => null])->save();
        }

        return redirect()->route('admin.teams.index')
                         ->with('status', "You've left {$team->name}.");
    }

    // ── Switch active team context ────────────────────────────────────────

    public function switchTeam(Team $team): RedirectResponse
    {
        $user = Auth::user();

        if (! $user->belongsToTeam($team)) {
            abort(403);
        }

        $user->switchTeam($team);

        return redirect()->intended(route('admin.teams.show', $team))
                         ->with('status', "Switched to team: {$team->name}");
    }

    // ── Private helpers ───────────────────────────────────────────────────

    private function authorizeTeamAccess(Team $team): void
    {
        $user = Auth::user();
        if (! $user->belongsToTeam($team)) {
            abort(403);
        }
    }

    private function authorizeOwner(Team $team): void
    {
        if (! $team->isOwner(Auth::user())) {
            abort(403, 'Only the team owner can perform this action.');
        }
    }
}
