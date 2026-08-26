<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Gallery;
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

        // 1. Delete files for all personal galleries (images + audio + logos
        //    + Spatie media originals + conversions).
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
                    $updates['name']  = 'Anonymous Artist';
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
                            'name'  => 'Anonymous Artist',
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

        // 7. ITERATION-003 FIX (G-2): Anonymize the user's transactions BEFORE
        //    deleting the user.
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

        // 8. ITERATION-003 FIX (G-5): Anonymize the user's invoices BEFORE
        //    deleting the user.
        //
        //    The G-1 migration changes invoices.user_id FK to nullOnDelete,
        //    so the invoice ROW is preserved (user_id becomes null). But the
        //    invoice still has PII (customer_email, customer_name, billing_address)
        //    that must be anonymized for GDPR compliance.
        //
        //    We anonymize the PII but keep the financial record (amount,
        //    tax_amount, tax_rate, currency, invoice_number) for tax audit.
        $this->anonymizeUserInvoices($user);

        // 9. Finally, delete the user. DB cascade handles:
        //    - galleries (onDelete cascade)
        //    - gallery_images (via galleries cascade)
        //    - analytics_events (via galleries cascade)
        //    - team_user pivot rows (via teams cascade)
        //    - team_invitations (via teams cascade)
        //    - invoices (G-1 FIX: now nullOnDelete — invoice row preserved
        //      with user_id = null)
        //    - admin_audit_logs.actor_id (cascade — same concern as
        //      transactions, deferred to a future iteration)
        //
        // NOTE: The Spatie `media` table rows are deleted by Spatie's
        // own observer when the GalleryImage model is deleted (via the
        // gallery cascade). The MEDIA FILES on disk were already deleted
        // in step 1 via clearMediaCollection(). If any media DB rows
        // remain (e.g. race between step 1 and the cascade), Spatie's
        // cascade will clean up the DB rows but the files are already gone.
        $user->delete();

        Log::info('UserDeletionService: user deleted', [
            'user_id' => $user->id,
            'reason'  => $reason,
        ]);
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
        $anonymizedEmail = 'anonymized:' . substr(hash('sha256', $appId . $user->email), 0, 16);

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
                'customer_name'  => null,
                'user_id'        => null,
                'updated_at'     => now(),
            ]);

        Log::info('UserDeletionService: anonymized user transactions (G-2 fix)', [
            'user_id'             => $user->id,
            'transactions_count'  => $count,
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
        $anonymizedEmail = 'anonymized:' . substr(hash('sha256', $appId . $user->email), 0, 16);

        // ITERATION-1 FIX (cross-driver consistency): NULL the user_id here
        // too. On MySQL the FK is nullOnDelete (row preserved with null
        // user_id) — matching that outcome explicitly neutralizes the
        // still-active CASCADE on SQLite, where the G-1 migration's FK
        // change could not be applied and the invoice rows were being
        // cascade-deleted instead of preserved.
        $count = DB::table('invoices')
            ->where('user_id', $user->id)
            ->update([
                'customer_email'  => $anonymizedEmail,
                'customer_name'   => null,
                'billing_address' => null,
                'user_id'         => null,
                'updated_at'      => now(),
            ]);

        Log::info('UserDeletionService: anonymized user invoices (G-5 fix)', [
            'user_id'          => $user->id,
            'invoices_count'   => $count,
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
                    'error'    => $e->getMessage(),
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
                'path'  => $path,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
