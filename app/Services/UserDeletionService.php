<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Gallery;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;
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
 *   - P0-4: Spatie Media Library originals + all generated conversions
 *     (thumb, small, medium, large WebP) are also deleted via
 *     clearMediaCollection('original'). Previously these persisted on
 *     disk forever — a GDPR violation (the Spatie originals contained
 *     unstripped EXIF/GPS data from the raw upload).
 *   - Owned teams' galleries are also cleaned up.
 *   - ITERATION-003 FIX (G-2): Transactions are anonymized (not deleted)
 *     before the user is deleted. The transactions.user_id FK was dropped
 *     for partitioning, so cascade-delete no longer works — without this
 *     fix, transactions become orphaned with PII intact.
 *   - ITERATION-003 FIX (G-5): Invoices are also anonymized. The invoices
 *     table has the SAME PII (customer_email, customer_name, billing_address)
 *     as transactions, and must be anonymized for GDPR compliance.
 *   - The user row is deleted last; DB cascade handles galleries, images,
 *     events, team memberships.
 *
 * ITERATION-9 (account-deletion readiness):
 *   - SHARED-DATA FIX: galleries the user CREATED inside teams they do
 *     NOT own are no longer destroyed by the galleries.user_id cascade.
 *     This service's own documented policy ("team galleries belong to
 *     the team, not the user") is now actually enforced: they are
 *     re-assigned to the team owner first, so the team keeps the gallery
 *     and every file/analytics row under it.
 *   - SHARED-DATA FIX: galleries of OTHER MEMBERS inside teams owned by
 *     the deleting user are no longer stripped of their files. The team
 *     row cascade-deletes with its owner, but the members' gallery rows
 *     SURVIVE (their own user_id FK keeps them alive, team_id becomes
 *     null) — deleting their files used to leave live husks.
 *   - TRANSACTIONAL SAFETY: every database mutation that must succeed
 *     or fail together with the user row (team-id clears, transaction
 *     and invoice anonymization, stale reset-token purge, the delete
 *     itself) now runs inside one DB transaction. Filesystem deletion
 *     stays OUTSIDE transactions (it cannot roll back) and keeps its
 *     existing files-first order; per-file failures remain non-fatal.
 *   - STALE-CREDENTIAL FIX: the password_reset_tokens row for the
 *     user's email is purged with the deletion. The table is EMAIL-keyed
 *     with no FK, so the row would otherwise survive and stay valid
 *     against whoever claims the freed address next (same replay class
 *     fixed for email change and password change).
 *
 * NOTE: Financial records (transactions) and audit logs
 * (admin_audit_logs.actor_id) are now anonymized (not deleted) for
 * compliance with tax retention laws (IRS 7-year, HMRC 6-year, EU VAT
 * 10-year). The G-1 migration changes invoices.user_id FK to nullOnDelete
 * so invoice records are preserved (with user_id = null) when the user is
 * deleted.
 */
class UserDeletionService
{
    /**
     * Delete a user and all their files on disk.
     *
     * @param  string  $reason  Short reason for log context (e.g. "Self-serve account deletion",
     *                          "Admin deletion"). Logged but not stored in the DB.
     */
    public function deleteUser(User $user, string $reason): void
    {
        Log::info('UserDeletionService: deleting user', [
            'user_id' => $user->id,
            'reason' => $reason,
        ]);

        // 0. ITERATION-9 SHARED-DATA FIX: galleries this user created inside
        //    teams they do NOT own belong to the TEAM (this class's own long-
        //    standing policy). Left alone, the galleries.user_id cascade
        //    would destroy those rows — and with them the team's data —
        //    while this class deliberately skipped their files. Re-assign
        //    them to the team owner BEFORE any file deletion, so the team
        //    keeps the gallery, its media, its analytics and its branding.
        //    (Owned teams' galleries are intentionally NOT transferred:
        //    the team itself cascade-deletes with its owner, and the
        //    user's own galleries die with the user as before.)
        $this->transferTeamGalleriesToTeamOwners($user);

        // 1. Delete files for all personal galleries (images + audio + logos
        //    + Spatie media originals + conversions). After step 0 this is
        //    correctly scoped: only galleries that will actually be cascade-
        //    deleted with the user.
        $user->galleries()->with('images')->chunkById(50, function ($galleries) {
            foreach ($galleries as $gallery) {
                $this->deleteGalleryFiles($gallery);
            }
        });

        // 2. ITERATION-9 REMOVED: this step used to delete the FILES of ALL
        //    galleries inside teams owned by the deleting user — including
        //    galleries created by OTHER MEMBERS. Those members' gallery rows
        //    survive the owner's deletion (their own user_id FK keeps them
        //    alive; the dying team only nulls galleries.team_id), so the file
        //    deletion produced live, file-less husks of other people's data.
        //    Members' galleries now survive INTACT: rows via their own
        //    user_id, files because nothing deletes them anymore.

        // 3. Delete artist portraits created by this user.
        //    Artists themselves are NOT deleted — they may be referenced by
        //    other users' galleries (the audit's C16 finding notes that
        //    Artist has no ownership enforcement today). Just remove the
        //    portrait file and null the column so the artist record doesn't
        //    point at a missing file.
        //
        //    G-7 FIX (Iter-003 partial, Iter-010 complete): Also null the
        //    artist's email AND replace the name with "Anonymous Artist" if
        //    the artist's email matches the deleted user's email (GDPR leak
        //    prevention — audit G-7). Iter-003 only nulled the email; Iter-010
        //    also nulls the name per the audit recommendation ("replace name
        //    with 'Anonymous Artist' or similar").
        //
        //    Rationale: artists may have a legitimate public contact email
        //    unrelated to the deleted user (e.g. an artist represented by
        //    multiple curators). We only null when the email matches the
        //    deleted user's email — at that point, the artist record's email
        //    is the deleted user's PII and must be removed. The name is also
        //    nulled because curators often use their own name as the artist
        //    name (same person = same PII).
        $userEmail = strtolower($user->email ?? '');
        $user->createdArtists()->whereNotNull('portrait_path')->chunkById(50, function ($artists) use ($userEmail) {
            foreach ($artists as $artist) {
                $this->deletePublicDiskFile($artist->getOriginal('portrait_path'));
                $updates = ['portrait_path' => null];

                // G-7 FIX (Iter-010): If the artist's email matches the deleted
                // user's email, null email + replace name with "Anonymous Artist"
                // (GDPR — both fields are PII when they match the deleted user).
                if ($userEmail && strtolower($artist->email ?? '') === $userEmail) {
                    $updates['email'] = null;
                    $updates['name'] = 'Anonymous Artist';
                }

                $artist->forceFill($updates)->save();
            }
        });

        // 3b. G-7 FIX (Iter-010): Also process artists where the email matches
        //     the deleted user's email BUT portrait_path is already null
        //     (the Iter-003 fix only ran on artists with non-null portrait_path).
        //     This catches artists whose portrait was already removed in a
        //     prior cleanup but whose email + name still match the deleted user.
        if ($userEmail) {
            $user->createdArtists()
                ->whereNull('portrait_path')
                ->whereRaw('LOWER(email) = ?', [$userEmail])
                ->chunkById(50, function ($artists) {
                    foreach ($artists as $artist) {
                        $artist->forceFill([
                            'email' => null,
                            'name' => 'Anonymous Artist',
                        ])->save();
                    }
                });
        }

        // 4. Coolify custom-domain cleanup for any Studio galleries.
        //    Delegates to PlanDowngradeService which calls
        //    CoolifyDomainManager::removeDomain + cache forget + file deletion.
        //    This is a no-op if the user is not on Studio.
        app(PlanDowngradeService::class)
            ->downgradeToFree($user, "User deletion: {$reason}");

        // 5–9. ITERATION-9 TRANSACTIONAL SAFETY: everything that must commit
        //      or roll back TOGETHER with the user row now runs inside one
        //      transaction. A failure anywhere in here (e.g. an unexpected
        //      FK constraint on the final delete) previously could leave
        //      half-anonymized records or cleared team ids with the user
        //      still alive; now the tail is all-or-nothing and the request
        //      fails safely with the account intact (files already deleted
        //      are logged and non-fatal — the user can retry).
        DB::transaction(function () use ($user) {
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

            // 7. ITERATION-003 FIX (G-2): Anonymize the user's transactions
            //    BEFORE deleting the user.
            //
            //    The transactions.user_id FK was dropped by the partition migration
            //    (2026_07_04_000001) because MySQL/InnoDB cannot be the target of
            //    a FK when the table is partitioned. As a result, deleting a user
            //    does NOT cascade-delete their transactions — the transactions
            //    become orphaned with a dangling user_id and PII intact.
            //
            //    This fix anonymizes the PII (customer_email, customer_name) on
            //    the user's transactions before the user is deleted. The financial
            //    record (amount, currency, plan, status, invoice_id, sale_id) is
            //    preserved for tax audit compliance (IRS 7-year retention).
            //
            //    The user_id column is left as-is (pointing at the soon-to-be-
            //    deleted user). This is acceptable because:
            //      - The user_id is no longer a FK (it's just an indexed column).
            //      - Joins to users will return null for the deleted user.
            //      - The anonymized PII means the transaction can't be linked
            //        back to a real person.
            $this->anonymizeUserTransactions($user);

            // 8. ITERATION-003 FIX (G-5): Anonymize the user's invoices
            //    BEFORE deleting the user.
            //
            //    The G-1 migration changes invoices.user_id FK to nullOnDelete,
            //    so the invoice ROW is preserved (user_id becomes null). But the
            //    invoice still has PII (customer_email, customer_name, billing_address)
            //    that must be anonymized for GDPR compliance.
            //
            //    We anonymize the PII but keep the financial record (amount,
            //    tax_amount, tax_rate, currency, invoice_number) for tax audit.
            $this->anonymizeUserInvoices($user);

            // 8b. ITERATION-9 STALE-CREDENTIAL FIX: purge the password_reset
            //     row for the user's email. The table is EMAIL-keyed with no
            //     FK, so deleting the user does NOT remove it — and the freed
            //     address can then be claimed by a new registrant while the
            //     still-valid token (60-min TTL) would reset THAT stranger's
            //     password. Same replay class already fixed for email change
            //     and password change.
            DB::table('password_reset_tokens')->where('email', $user->email)->delete();

            // 9. Finally, delete the user. DB cascade handles:
            //    - galleries (onDelete cascade)
            //    - gallery_images (via galleries cascade)
            //    - analytics_events (via galleries cascade)
            //    - team_user pivot rows (via teams cascade)
            //    - team_invitations (via teams cascade)
            //    - invoices (G-1 FIX: now nullOnDelete — invoice row preserved
            //      with user_id = null)
            //    - admin_audit_logs.actor_id (nullOnDelete since 2026_07_04_000007
            //      — audit rows survive with actor_id = NULL)
            //
            // NOTE: The Spatie `media` table rows are deleted by Spatie's
            // own observer when the GalleryImage model is deleted (via the
            // gallery cascade). The MEDIA FILES on disk were already deleted
            // in step 1 via clearMediaCollection(). If any media DB rows
            // remain (e.g. race between step 1 and the cascade), Spatie's
            // cascade will clean up the DB rows but the files are already gone.
            $user->delete();
        });

        Log::info('UserDeletionService: user deleted', [
            'user_id' => $user->id,
            'reason' => $reason,
        ]);
    }

    /**
     * ITERATION-9 SHARED-DATA FIX: re-assign the galleries this user created
     * inside OTHER users' teams to those teams' owners, so the team keeps
     * its gallery when the creator deletes their account.
     *
     * Why this must run FIRST (before every file cleanup and before the
     * Coolify downgrade): everything downstream scopes itself off
     * $user->galleries() (hasMany by user_id) — transferring ownership here
     * automatically excludes these galleries from file deletion, Studio-
     * resource cleanup, and the final cascade.
     *
     * Why the team OWNER: the gallery belongs to the team (this service's
     * documented policy); the owner is the one user that always exists while
     * the team does and the natural continuing owner. Owned teams are
     * skipped (their galleries die with the user/team by design), as are
     * galleries whose team row has somehow vanished (defensive: they are
     * effectively personal galleries and die with the user).
     *
     * Runs inside a transaction so the set is transferred all-or-nothing.
     * The bulk updates are query-builder level and deliberately bypass model
     * events — no side effects, no touched timestamps beyond updated_at.
     */
    private function transferTeamGalleriesToTeamOwners(User $user): void
    {
        $galleries = Gallery::query()
            ->where('user_id', $user->id)
            ->whereNotNull('team_id')
            ->get(['id', 'team_id']);

        if ($galleries->isEmpty()) {
            return;
        }

        $teamOwners = Team::query()
            ->whereIn('id', $galleries->pluck('team_id')->unique())
            ->pluck('owner_id', 'id');

        $transferred = 0;

        DB::transaction(function () use ($user, $galleries, $teamOwners, &$transferred) {
            foreach ($galleries as $gallery) {
                $ownerId = $teamOwners->get($gallery->team_id);

                if ($ownerId && (int) $ownerId !== (int) $user->id) {
                    Gallery::query()
                        ->where('id', $gallery->id)
                        ->update(['user_id' => $ownerId, 'updated_at' => now()]);
                    $transferred++;
                }
            }
        });

        if ($transferred > 0) {
            Log::info('UserDeletionService: transferred team galleries to team owners', [
                'user_id' => $user->id,
                'transferred' => $transferred,
            ]);
        }
    }

    /**
     * ITERATION-003 FIX (G-2): Anonymize PII on the user's transactions.
     *
     * Replaces customer_email with 'anonymized:' + hash (stable, allows
     * correlation without revealing the email) and nulls customer_name.
     * The financial fields (amount, currency, plan, status, invoice_id,
     * sale_id) are preserved for tax audit compliance.
     *
     * This mirrors the AnonymizeTransactionPii command's logic, but runs
     * immediately on user deletion (vs. the 18-month retention window
     * for the scheduled command).
     */
    private function anonymizeUserTransactions(User $user): void
    {
        $appId = config('app.key');
        $anonymizedEmail = 'anonymized:'.substr(hash('sha256', $appId.$user->email), 0, 16);

        // ITERATION-1 FIX (cross-driver consistency): also NULL the user_id.
        // On MySQL the FK is gone (partitioning) and orphaned rows survive
        // — but on SQLite the DROP FOREIGN KEY no-ops, the cascade stays
        // active, and the anonymized rows were cascade-deleted anyway (the
        // G-2 tax-retention guarantee only held on the production driver;
        // PRAGMA foreign_keys is a no-op inside RefreshDatabase's
        // transaction, so it can't bridge the gap either). Nulling
        // user_id detaches the rows BEFORE the user delete on EVERY
        // driver — no cascade can match them.
        $count = DB::table('transactions')
            ->where('user_id', $user->id)
            ->update([
                'customer_email' => $anonymizedEmail,
                'customer_name' => null,
                'user_id' => null,
                'updated_at' => now(),
            ]);

        Log::info('UserDeletionService: anonymized user transactions (G-2 fix)', [
            'user_id' => $user->id,
            'transactions_count' => $count,
        ]);
    }

    /**
     * ITERATION-003 FIX (G-5): Anonymize PII on the user's invoices.
     *
     * Replaces customer_email with 'anonymized:' + hash, nulls customer_name
     * and billing_address. The financial fields (amount, tax_amount, tax_rate,
     * currency, invoice_number, pdf_path) are preserved for tax audit.
     *
     * The invoice row itself is NOT deleted — the G-1 migration changes the
     * user_id FK to nullOnDelete, so the row survives with user_id = null.
     * This method anonymizes the PII fields so the surviving row doesn't
     * contain personally identifiable information.
     */
    private function anonymizeUserInvoices(User $user): void
    {
        $appId = config('app.key');
        $anonymizedEmail = 'anonymized:'.substr(hash('sha256', $appId.$user->email), 0, 16);

        // ITERATION-1 FIX (cross-driver consistency): NULL the user_id here
        // too. On MySQL the FK is nullOnDelete (row preserved with null
        // user_id) — matching that outcome explicitly neutralizes the
        // still-active CASCADE on SQLite, where the G-1 migration's FK
        // change could not be applied and the invoice rows were being
        // cascade-deleted instead of preserved.
        $count = DB::table('invoices')
            ->where('user_id', $user->id)
            ->update([
                'customer_email' => $anonymizedEmail,
                'customer_name' => null,
                'billing_address' => null,
                'user_id' => null,
                'updated_at' => now(),
            ]);

        Log::info('UserDeletionService: anonymized user invoices (G-5 fix)', [
            'user_id' => $user->id,
            'invoices_count' => $count,
        ]);
    }

    /**
     * Delete all files associated with a single gallery:
     *   - every image file (legacy `path` column)
     *   - P0-4: every image's Spatie Media Library originals + all
     *     generated conversions (thumb, small, medium, large WebP)
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
            // P0-4: Delete the Spatie Media Library files FIRST.
            // clearMediaCollection('original') deletes:
            //   - The original file in the 'original' collection
            //   - All generated conversion files (thumb, small, medium, large)
            //   - The `media` table DB rows
            // This is the GDPR-critical cleanup — the Spatie originals
            // previously contained unstripped EXIF/GPS data and persisted
            // on disk forever, even after account deletion.
            try {
                $image->clearMediaCollection('original');
            } catch (\Throwable $e) {
                Log::warning('UserDeletionService: clearMediaCollection failed', [
                    'image_id' => $image->id,
                    'error' => $e->getMessage(),
                ]);
            }

            // Delete the legacy `path` column file (the EXIF-stripped
            // main JPEG saved by ImageProcessingService::process()).
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
        $clean = \Illuminate\Support\Str::after($path, 'storage/');

        try {
            if ($disk->exists($clean)) {
                $disk->delete($clean);
            } elseif ($disk->exists($path)) {
                $disk->delete($path);
            }
        } catch (\Throwable $e) {
            Log::warning('UserDeletionService: file delete failed', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
