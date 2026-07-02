<?php

namespace App\Services;

use App\Models\Gallery;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Centralize user-account deletion: DB cascade handles rows, but files on
 * disk must be deleted explicitly. Both the self-serve path
 * (ProfileController::destroy) and the admin path
 * (SuperAdmin\SystemController::deleteUser) call this service.
 *
 * WITHOUT this service:
 *   - ProfileController::destroy called $user->delete() and nothing else —
 *     every uploaded artwork, audio file, custom logo, curtain logo, and
 *     artist portrait stayed on disk forever. GDPR violation (privacy
 *     policy promises "right to delete your personal information").
 *   - SystemController::deleteUser had partial cleanup (galleries + audio
 *     + custom_logo) but missed curtain_logo_path and artist portraits.
 *
 * WITH this service:
 *   - Every gallery owned by the user has its image files, audio file,
 *     custom_logo, curtain_logo, and (transitively) artist portraits
 *     deleted from the public disk.
 *   - Owned teams' galleries are also cleaned up.
 *   - The user row is deleted last; DB cascade handles galleries, images,
 *     events, transactions, team memberships.
 *
 * NOTE: Financial records (transactions) and audit logs
 * (admin_audit_logs.actor_id) currently cascade-delete with the user.
 * Audit H24 recommends retaining these for accounting / forensics —
 * that's a separate future task (soft-delete users OR change FKs to
 * nullOnDelete + archive). For now, this service does NOT touch the
 * transactions or audit_logs tables.
 */
class UserDeletionService
{
    /**
     * Delete a user and all their files on disk.
     *
     * @param  User    $user
     * @param  string  $reason  Short reason for log context (e.g. "Self-serve account deletion",
     *                          "Admin deletion"). Logged but not stored in the DB.
     */
    public function deleteUser(User $user, string $reason): void
    {
        Log::info('UserDeletionService: deleting user', [
            'user_id' => $user->id,
            'reason'  => $reason,
        ]);

        // 1. Delete files for all personal galleries (images + audio + logos).
        $user->galleries()->with('images')->chunkById(50, function ($galleries) {
            foreach ($galleries as $gallery) {
                $this->deleteGalleryFiles($gallery);
            }
        });

        // 2. Delete files for galleries in teams owned by this user.
        //    Team galleries created by this user but in teams they don't own
        //    are left alone — those belong to the team, not the user.
        foreach ($user->ownedTeams as $team) {
            $team->galleries()->with('images')->chunkById(50, function ($galleries) {
                foreach ($galleries as $gallery) {
                    $this->deleteGalleryFiles($gallery);
                }
            });
        }

        // 3. Delete artist portraits created by this user.
        //    Artists themselves are NOT deleted — they may be referenced by
        //    other users' galleries (the audit's C16 finding notes that
        //    Artist has no ownership enforcement today). Just remove the
        //    portrait file and null the column so the artist record doesn't
        //    point at a missing file.
        $user->createdArtists()->whereNotNull('portrait_path')->chunkById(50, function ($artists) {
            foreach ($artists as $artist) {
                $this->deletePublicDiskFile($artist->getOriginal('portrait_path'));
                $artist->forceFill(['portrait_path' => null])->save();
            }
        });

        // 4. Coolify custom-domain cleanup for any Studio galleries.
        //    Delegates to PlanDowngradeService which calls
        //    CoolifyDomainManager::removeDomain + cache forget + file deletion.
        //    This is a no-op if the user is not on Studio.
        app(PlanDowngradeService::class)
            ->downgradeToFree($user, "User deletion: {$reason}");

        // 5. Clear current_team_id for any other users pointing at teams
        //    this user owns (those teams are about to be cascade-deleted).
        foreach ($user->ownedTeams as $team) {
            User::where('current_team_id', $team->id)
                ->where('id', '!=', $user->id)
                ->update(['current_team_id' => null]);
        }

        // 6. Clear this user's current_team_id (defensive — FK set-null on
        //    teams should handle it, but the teams table FK is missing per
        //    audit H23).
        $user->forceFill(['current_team_id' => null])->save();

        // 7. Finally, delete the user. DB cascade handles:
        //    - galleries (onDelete cascade)
        //    - gallery_images (via galleries cascade)
        //    - analytics_events (via galleries cascade)
        //    - team_user pivot rows (via teams cascade)
        //    - team_invitations (via teams cascade)
        //    - transactions (via user_id FK cascade — see audit H24 for
        //      why this is a retention concern)
        //    - admin_audit_logs.actor_id (cascade — same concern)
        $user->delete();

        Log::info('UserDeletionService: user deleted', [
            'user_id' => $user->id,
            'reason'  => $reason,
        ]);
    }

    /**
     * Delete all files associated with a single gallery:
     *   - every image file
     *   - audio_path
     *   - custom_logo_path
     *   - curtain_logo_path
     *
     * DB rows are cascade-deleted with the gallery — this method only
     * handles disk cleanup.
     */
    public function deleteGalleryFiles(Gallery $gallery): void
    {
        foreach ($gallery->images as $image) {
            $this->deletePublicDiskFile($image->getOriginal('path'));
        }

        foreach (['audio_path', 'custom_logo_path', 'curtain_logo_path'] as $field) {
            $path = $gallery->getOriginal($field);
            if (! empty($path)) {
                $this->deletePublicDiskFile($path);
            }
        }
    }

    /**
     * Delete a file from the public disk, handling both disk-relative and
     * `storage/`-prefixed path conventions (the codebase has both — audit M6).
     */
    private function deletePublicDiskFile(?string $path): void
    {
        if (empty($path)) {
            return;
        }

        $disk = Storage::disk('public');
        $clean = str_replace('storage/', '', $path);

        try {
            if ($disk->exists($clean)) {
                $disk->delete($clean);
            } elseif ($disk->exists($path)) {
                $disk->delete($path);
            }
        } catch (\Throwable $e) {
            Log::warning('UserDeletionService: file delete failed', [
                'path'  => $path,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
