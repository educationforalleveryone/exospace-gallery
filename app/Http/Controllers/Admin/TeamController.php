<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\TeamInvitationMail;
use App\Models\AdminAuditLog;
use App\Models\Team;
use App\Models\TeamInvitation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\View\View;

class TeamController extends Controller
{

    public function index(): View
    {
        $user = Auth::user();

        $ownedTeams = $user->ownedTeams()->withCount('members')->with('members')->get();
        $memberTeams = $user->teams()
            ->where('owner_id', '!=', $user->id)
            ->withCount('members')
            ->with('owner')
            ->get();

        return view('admin.teams.index', compact('ownedTeams', 'memberTeams'));
    }

    public function create(): View
    {
        return view('admin.teams.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'description' => 'nullable|string|max:500',
        ]);

        $team = \Illuminate\Support\Facades\DB::transaction(function () use ($validated) {
            $team = Team::create([
                'owner_id' => Auth::id(),
                'name' => $validated['name'],
                'description' => $validated['description'] ?? null,
            ]);

            $team->members()->attach(Auth::id(), ['role' => 'owner']);

            return $team;
        });

        Auth::user()->switchTeam($team);

        return redirect()->route('admin.teams.show', $team)
            ->with('status', 'Team created! Invite your collaborators below.');
    }

    public function show(Team $team): View
    {
        $this->authorize('view', $team);

        $team->load(['members', 'owner', 'galleries' => fn ($q) => $q->latest()->limit(5)]);
        $pendingInvitations = $team->invitations()->where('expires_at', '>', now())->get();
        $userRole = Auth::user()->teamRole($team);

        return view('admin.teams.show', compact('team', 'pendingInvitations', 'userRole'));
    }

    // ── Update team settings (owner OR editor) ───────────────────────────

    public function update(Request $request, Team $team): RedirectResponse
    {
        $this->authorize('update', $team);

        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'description' => 'nullable|string|max:500',
        ]);

        $team->update($validated);

        return back()->with('status', 'Team settings updated.');
    }

    public function destroy(Team $team): RedirectResponse
    {
        // P1-6: TeamPolicy::delete() returns $team->isOwner($user) — owner only.
        $this->authorize('delete', $team);

        // If this was the active team for anyone, reset their current_team_id
        \App\Models\User::where('current_team_id', $team->id)
            ->update(['current_team_id' => null]);

        $team->delete();

        return redirect()->route('admin.teams.index')
            ->with('status', 'Team deleted.');
    }

    public function invite(Request $request, Team $team): RedirectResponse
    {
        // P1-6: TeamPolicy::invite() returns $team->canEdit($user) — owner OR editor.
        $this->authorize('invite', $team);

        $validated = $request->validate([
            'email' => 'required|email|max:255',
            'role' => 'required|in:editor,viewer',
        ]);

        // Can't invite yourself
        if (strtolower($validated['email']) === strtolower(Auth::user()->email)) {
            return back()->withErrors(['email' => 'You cannot invite yourself.']);
        }

        // Check if already a member
        $existingMember = \App\Models\User::where('email', $validated['email'])->first();
        if ($existingMember && $team->hasMember($existingMember)) {
            return back()->withErrors(['email' => 'This person is already a team member.']);
        }

        $plaintextToken = TeamInvitation::generateToken();
        $hashedToken = TeamInvitation::hashToken($plaintextToken);

        $invitation = TeamInvitation::updateOrCreate(
            ['team_id' => $team->id, 'email' => $validated['email']],
            [
                'role' => $validated['role'],
                'token' => $hashedToken, // D-6 FIX: store the HASH, not the plaintext
                'expires_at' => now()->addDays(7),
            ]
        );

        // AUDIT-P1-4.9: Log team invitation. 'email' is PII — auto-scrubbed.
        AdminAuditLog::record('team.invited', $team, [
            'email' => $validated['email'],
            'role' => $validated['role'],
            'invitation_id' => $invitation->id,
            'expires_at' => $invitation->expires_at->toIso8601String(),
        ]);

        $invitation->plaintext_token = $plaintextToken;

        Mail::to($validated['email'])->send(new TeamInvitationMail($invitation, $plaintextToken));

        return back()->with('status', "Invitation sent to {$validated['email']}.");
    }

    // ── Revoke a pending invitation (owner OR editor) ────────────────────

    public function revokeInvitation(Team $team, TeamInvitation $invitation): RedirectResponse
    {
        // P1-6: Use 'invite' policy — editors who can invite can also revoke.
        $this->authorize('invite', $team);
        abort_unless($invitation->team_id === $team->id, 404);

        $invitation->delete();

        // AUDIT-P1-4.10: Log invitation revocation.
        AdminAuditLog::record('team.invitation_revoked', $invitation, [
            'team_id' => $team->id,
            'email' => $invitation->email,
            'role' => $invitation->role,
        ]);

        return back()->with('status', 'Invitation revoked.');
    }

    public function removeMember(Request $request, Team $team): RedirectResponse
    {
        // P1-6: TeamPolicy::manageMembers() returns $team->isOwner($user) — owner only.
        $this->authorize('manageMembers', $team);

        $validated = $request->validate(['user_id' => 'required|integer|exists:users,id']);

        // Cannot remove the owner
        if ($validated['user_id'] == $team->owner_id) {
            return back()->withErrors(['user_id' => 'Cannot remove the team owner.']);
        }

        $isMember = $team->members()->where('team_user.user_id', $validated['user_id'])->exists();
        if (! $isMember) {
            return back()->withErrors(['user_id' => 'That user is not a member of this team.']);
        }

        $team->members()->detach($validated['user_id']);

        // Reset their current_team_id if it was this team
        \App\Models\User::where('id', $validated['user_id'])
            ->where('current_team_id', $team->id)
            ->update(['current_team_id' => null]);

        // AUDIT-P1-4.11: Log team member removal.
        AdminAuditLog::record('team.member_removed', $team, [
            'user_id' => $validated['user_id'],
        ]);

        return back()->with('status', 'Member removed.');
    }

    public function updateMemberRole(Request $request, Team $team): RedirectResponse
    {
        // P1-6: TeamPolicy::manageMembers() returns $team->isOwner($user) — owner only.
        $this->authorize('manageMembers', $team);

        $validated = $request->validate([
            'user_id' => 'required|integer|exists:users,id',
            'role' => 'required|in:editor,viewer',
        ]);

        if ($validated['user_id'] == $team->owner_id) {
            return back()->withErrors(['role' => 'Cannot change the owner\'s role.']);
        }

        $targetMember = $team->members()->where('team_user.user_id', $validated['user_id'])->first();
        if (! $targetMember) {
            return back()->withErrors(['user_id' => 'That user is not a member of this team.']);
        }

        $oldRole = $targetMember->pivot->role;

        $team->members()->updateExistingPivot($validated['user_id'], ['role' => $validated['role']]);

        AdminAuditLog::record('team.member_role_changed', $team, [
            'user_id' => $validated['user_id'],
            'old_role' => $oldRole,
            'new_role' => $validated['role'],
        ]);

        return back()->with('status', 'Member role updated.');
    }

    public function leave(Team $team): RedirectResponse
    {
        $user = Auth::user();

        $this->authorize('view', $team);

        if ($team->isOwner($user)) {
            return back()->withErrors(['team' => 'Owners cannot leave their own team. Transfer ownership or delete the team.']);
        }

        $team->members()->detach($user->id);

        if ($user->current_team_id === $team->id) {
            $user->forceFill(['current_team_id' => null])->save();
        }

        // AUDIT-P1-4.13: Log user leaving a team.
        AdminAuditLog::record('team.left', $team);

        return redirect()->route('admin.teams.index')
            ->with('status', "You've left {$team->name}.");
    }

    public function switchTeam(Team $team): RedirectResponse
    {
        $this->authorize('switch', $team);

        Auth::user()->switchTeam($team);

        return redirect()->intended(route('admin.teams.show', $team))
            ->with('status', "Switched to team: {$team->name}");
    }
}
