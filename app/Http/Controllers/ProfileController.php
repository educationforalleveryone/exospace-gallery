<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use App\Mail\EmailChangedNoticeMail;
use App\Models\AdminAuditLog;
use App\Services\UserDeletionService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class ProfileController extends Controller
{
    /**
     * Display the user's profile form.
     */
    public function edit(Request $request): View
    {
        return view('profile.edit', [
            'user' => $request->user(),
        ]);
    }

    /**
     * Update the user's profile information.
     *
     * ITERATION-7: the email-address-change lifecycle is now explicit and
     * self-consistent. The identity gate itself (current password) lives in
     * ProfileUpdateRequest — conditional on the email actually changing, so
     * name-only edits keep the one-step UX. This method owns everything that
     * happens AROUND the change:
     *
     *   1. Race safety — the validation layer's `unique` rule is a pre-check;
     *      the DB unique index on users.email is the real arbiter. A request
     *      whose address gets claimed between validation and save used to
     *      blow up as an unhandled 500; it now lands back on the form with a
     *      clean "already in use" error and no state change.
     *   2. Stale credentials — password_reset_tokens is keyed by EMAIL, and a
     *      change FREES the old address. A surviving token row for the freed
     *      address would stay valid against whoever claims that address next
     *      (the broker resolves users by email). Rows for the old address are
     *      deleted with the change.
     *   3. Verification state — EXISTING product design preserved: the new
     *      address starts unverified (email_verified_at = null), matching the
     *      framework signed-URL structure (id + sha1(email)), which already
     *      guarantees old links cannot verify the new address. The lifecycle
     *      for the new address is INITIATED here (registration behaves the
     *      same via the Registered event) — the user no longer has to hunt
     *      for the resend button, and the verify page's "we sent a link"
     *      copy becomes true at the moment they read it.
     *   4. Compromise signal — the OLD address receives a link-free security
     *      notice (EmailChangedNoticeMail). If the change was made by a
     *      hijacked session, the real owner's inbox is the one channel the
     *      attacker did not capture.
     *   5. Audit — identity-critical self-service changes are audited like
     *      mfa.enabled / mfa.disabled already are ('email_changed'; the
     *      from/to values pass through AdminAuditLog's PII scrubbing).
     *   6. Redirect — straight to the verification prompt (one hop). The old
     *      /profile redirect bounced through the 'verified' middleware into a
     *      SECOND hop, which aged out the flashed status before any page
     *      rendered it — the user got zero acknowledgment of the change.
     *      The prompt page renders the "A fresh verification link has been
     *      sent." confirmation for this exact flash key.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $user = $request->user();
        $oldEmail = $user->email;

        $user->fill($request->validated());

        // EXISTING PRODUCT DESIGN: a changed address starts unverified.
        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        try {
            $user->save();
        } catch (UniqueConstraintViolationException $e) {
            // Validation's unique pre-check passed, another account claimed
            // the address before our save hit the DB unique index. Fail
            // cleanly with no state change; the flash excludes passwords.
            return back()
                ->withInput()
                ->withErrors(['email' => __('That email address is already in use by another account.')]);
        }

        if ($oldEmail === $user->email) {
            // Name-only (or no-op) update — established behavior, unchanged.
            return Redirect::route('profile.edit')->with('status', 'profile-updated');
        }

        // --- Email changed: the lifecycle below is email-change-specific. ---

        // Stale reset tokens for the FREED old address must not outlive it.
        DB::table('password_reset_tokens')->where('email', $oldEmail)->delete();

        // Initiate the verification lifecycle for the new address. (Framework
        // notification; on deployments with the branded auth-mail override it
        // ships the branded template — the call is identical either way.)
        $user->sendEmailVerificationNotification();

        // Security notice to the OLD address — the compromise signal channel.
        Mail::to($oldEmail)->send(new EmailChangedNoticeMail($user, $oldEmail));

        // Forensic visibility, matching the mfa.enabled/mfa.disabled precedent.
        AdminAuditLog::record('email_changed', $user, [
            'from' => $oldEmail,
            'to' => $user->email,
        ]);

        // One-hop redirect to the verification prompt: correct next step AND
        // an acknowledgment that actually renders (no double-hop flash loss).
        return redirect()
            ->route('verification.notice')
            ->with('status', 'verification-link-sent');
    }

    /**
     * Export the user's personal data as a JSON download (GDPR Art. 20 —
     * right to data portability).
     *
     * Returns a JSON document containing:
     *   - User profile (name, email, plan, created_at)
     *   - All galleries (with images metadata, not file contents)
     *   - All transactions (invoice history)
     *   - All team memberships
     *   - All artist profiles created by this user
     *
     * File contents (uploaded artwork, audio, logos) are NOT included in
     * the JSON. Users who want the actual files can download them from
     * the admin UI individually, or contact support for a bulk export.
     *
     * NOTE: This endpoint streams the JSON directly. For users with
     * thousands of galleries, consider dispatching a queued job that
     * generates a ZIP and emails a download link. Future enhancement.
     *
     * M-26 FIX: Now generates a ZIP archive containing:
     *   - profile.json  (the full structured data, same as before)
     *   - profile.csv   (a flat CSV summary for spreadsheet import)
     *   - galleries.csv (gallery metadata in CSV format)
     *   - transactions.csv (transaction history in CSV format)
     *   - README.txt    (explains what's in the ZIP)
     *
     * The ZIP is streamed directly to the browser (no temp file on disk).
     * Uses PHP's ZipArchive (available in all PHP 8.2+ installations).
     */
    public function export(Request $request)
    {
        $user = $request->user()->load([
            'galleries.images' => fn($q) => $q->select(['id', 'gallery_id', 'title', 'description', 'filename', 'original_name', 'mime_type', 'size', 'width', 'height', 'orientation', 'position_order', 'artist_id', 'price', 'currency', 'for_sale', 'medium', 'year', 'dimensions', 'edition_size', 'edition_number', 'external_url', 'created_at', 'updated_at']),
            'galleries.scheduleEvents',
            'galleries.newsletterSignups',
            'ownedTeams',
            'teams',
            'createdArtists',
        ]);

        $transactions = \DB::table('transactions')
            ->where('user_id', $user->id)
            ->orderBy('created_at', 'desc')
            ->get();

        $data = [
            'exported_at'       => now()->toIso8601String(),
            'user'              => [
                'id'              => $user->id,
                'name'            => $user->name,
                'email'           => $user->email,
                'plan'            => $user->plan,
                'plan_started_at' => $user->plan_started_at?->toIso8601String(),
                'plan_expires_at' => $user->plan_expires_at?->toIso8601String(),
                'created_at'      => $user->created_at?->toIso8601String(),
                'updated_at'      => $user->updated_at?->toIso8601String(),
            ],
            'galleries'         => $user->galleries->map(fn($g) => [
                'id'              => $g->id,
                'title'           => $g->title,
                'slug'            => $g->slug,
                'description'     => $g->description,
                'is_active'       => $g->is_active,
                'view_count'      => $g->view_count,
                'opens_at'        => $g->opens_at?->toIso8601String(),
                'closes_at'       => $g->closes_at?->toIso8601String(),
                'custom_domain'   => $g->custom_domain,
                'has_pin'         => !empty($g->pin_hash),
                'created_at'      => $g->created_at?->toIso8601String(),
                'updated_at'      => $g->updated_at?->toIso8601String(),
                'images'          => $g->images->map(fn($i) => [
                    'id'             => $i->id,
                    'title'          => $i->title,
                    'description'    => $i->description,
                    'filename'       => $i->filename,
                    'original_name'  => $i->original_name,
                    'mime_type'      => $i->mime_type,
                    'size'           => $i->size,
                    'width'          => $i->width,
                    'height'         => $i->height,
                    'orientation'    => $i->orientation,
                    'position_order' => $i->position_order,
                    'price'          => $i->price,
                    'currency'       => $i->currency,
                    'for_sale'       => $i->for_sale,
                    'medium'         => $i->medium,
                    'year'           => $i->year,
                    'dimensions'     => $i->dimensions,
                    'edition_size'   => $i->edition_size,
                    'edition_number' => $i->edition_number,
                    'external_url'   => $i->external_url,
                    'created_at'     => $i->created_at?->toIso8601String(),
                    'updated_at'     => $i->updated_at?->toIso8601String(),
                ])->toArray(),
                'schedule_events' => $g->scheduleEvents->map(fn($e) => [
                    'id'          => $e->id,
                    'title'       => $e->title,
                    'description' => $e->description,
                    'starts_at'   => $e->starts_at?->toIso8601String(),
                    'ends_at'     => $e->ends_at?->toIso8601String(),
                    'capacity'    => $e->capacity,
                    'created_at'  => $e->created_at?->toIso8601String(),
                ])->toArray(),
                'newsletter_signups' => $g->newsletterSignups->map(fn($n) => [
                    'id'         => $n->id,
                    'email'      => $n->email,
                    'created_at' => $n->created_at?->toIso8601String(),
                ])->toArray(),
            ])->toArray(),
            'transactions'      => $transactions->map(fn($t) => [
                'id'             => $t->id,
                'invoice_id'     => $t->invoice_id,
                'sale_id'        => $t->sale_id,
                'product_id'     => $t->product_id,
                'plan'           => $t->plan,
                'amount'         => $t->amount,
                'currency'       => $t->currency,
                'status'         => $t->status,
                'created_at'     => $t->created_at,
            ])->toArray(),
            'teams'             => $user->teams->map(fn($t) => [
                'id'         => $t->id,
                'name'       => $t->name,
                'slug'       => $t->slug,
                'role'       => $t->pivot->role,
                'created_at' => $t->created_at?->toIso8601String(),
            ])->toArray(),
            'owned_teams'       => $user->ownedTeams->map(fn($t) => [
                'id'         => $t->id,
                'name'       => $t->name,
                'slug'       => $t->slug,
                'created_at' => $t->created_at?->toIso8601String(),
            ])->toArray(),
            'created_artists'   => $user->createdArtists->map(fn($a) => [
                'id'         => $a->id,
                'name'       => $a->name,
                'slug'       => $a->slug,
                'bio'        => $a->bio,
                'website'    => $a->website,
                'instagram'  => $a->instagram,
                'twitter'    => $a->twitter,
                'email'      => $a->email,
                'location'   => $a->location,
                'created_at' => $a->created_at?->toIso8601String(),
            ])->toArray(),
        ];

        $filename = 'exospace-export-user-' . $user->id . '-' . now()->format('Y-m-d-His');

        // M-26: Generate a ZIP archive with JSON + CSV + README
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        // Build CSV for galleries
        $galleriesCsv = $this->buildGalleriesCsv($user);

        // Build CSV for transactions
        $transactionsCsv = $this->buildTransactionsCsv($transactions);

        // Build profile summary CSV
        $profileCsv = $this->buildProfileCsv($user);

        // Build README
        $readme = $this->buildReadme($user);

        // Create ZIP
        $zipPath = storage_path('app/temp/' . $filename . '.zip');
        if (!is_dir(dirname($zipPath))) {
            mkdir(dirname($zipPath), 0755, true);
        }

        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) === true) {
            $zip->addFromString('profile.json', $json);
            $zip->addFromString('profile.csv', $profileCsv);
            $zip->addFromString('galleries.csv', $galleriesCsv);
            $zip->addFromString('transactions.csv', $transactionsCsv);
            $zip->addFromString('README.txt', $readme);
            $zip->close();
        } else {
            // Fallback: return JSON if ZIP creation fails
            return response()->json($data, 200, [
                'Content-Type'        => 'application/json',
                'Content-Disposition' => 'attachment; filename="' . $filename . '.json"',
            ]);
        }

        $zipContent = file_get_contents($zipPath);
        @unlink($zipPath); // clean up temp file

        return response($zipContent, 200, [
            'Content-Type'        => 'application/zip',
            'Content-Disposition' => 'attachment; filename="' . $filename . '.zip"',
        ]);
    }

    /**
     * M-26: Build a CSV string of the user's galleries.
     */
    private function buildGalleriesCsv($user): string
    {
        $headers = ['ID', 'Title', 'Slug', 'Description', 'Active', 'View Count', 'Created At', 'Image Count'];
        $rows = [];

        foreach ($user->galleries as $g) {
            $rows[] = [
                $g->id,
                $g->title,
                $g->slug,
                $g->description,
                $g->is_active ? 'Yes' : 'No',
                $g->view_count,
                $g->created_at?->format('Y-m-d H:i:s'),
                $g->images->count(),
            ];
        }

        return $this->arrayToCsv($headers, $rows);
    }

    /**
     * M-26: Build a CSV string of the user's transactions.
     */
    private function buildTransactionsCsv($transactions): string
    {
        $headers = ['ID', 'Invoice ID', 'Plan', 'Amount', 'Currency', 'Status', 'Date'];
        $rows = [];

        foreach ($transactions as $t) {
            $rows[] = [
                $t->id,
                $t->invoice_id,
                $t->plan,
                $t->amount,
                $t->currency,
                $t->status,
                $t->created_at,
            ];
        }

        return $this->arrayToCsv($headers, $rows);
    }

    /**
     * M-26: Build a CSV summary of the user's profile.
     */
    private function buildProfileCsv($user): string
    {
        $headers = ['Field', 'Value'];
        $rows = [
            ['User ID', $user->id],
            ['Name', $user->name],
            ['Email', $user->email],
            ['Plan', $user->plan],
            ['Plan Started', $user->plan_started_at?->format('Y-m-d')],
            ['Plan Expires', $user->plan_expires_at?->format('Y-m-d') ?? 'Lifetime'],
            ['Galleries', $user->galleries->count()],
            ['Total Images', $user->galleries->sum(fn($g) => $g->images->count())],
            ['Teams', $user->teams->count() + $user->ownedTeams->count()],
            ['Account Created', $user->created_at?->format('Y-m-d')],
        ];

        return $this->arrayToCsv($headers, $rows);
    }

    /**
     * M-26: Build a README.txt explaining the ZIP contents.
     */
    private function buildReadme($user): string
    {
        return "Exospace GDPR Data Export\n" .
               "=========================\n\n" .
               "User: {$user->name} ({$user->email})\n" .
               "User ID: {$user->id}\n" .
               "Exported: " . now()->format('Y-m-d H:i:s') . "\n\n" .
               "This ZIP archive contains your personal data from Exospace Gallery,\n" .
               "exported in accordance with GDPR Article 20 (Right to Data Portability).\n\n" .
               "Contents:\n" .
               "  profile.json       - Complete structured data (JSON format)\n" .
               "  profile.csv        - Profile summary (CSV format, spreadsheet-importable)\n" .
               "  galleries.csv      - Gallery metadata (CSV format)\n" .
               "  transactions.csv   - Transaction history (CSV format)\n\n" .
               "Note: Uploaded image/audio/logo files are NOT included in this export.\n" .
               "To download individual files, visit your gallery admin pages.\n\n" .
               "For questions about your data, contact support@exospace.gallery\n";
    }

    /**
     * M-26: Convert an array to a CSV string.
     */
    private function arrayToCsv(array $headers, array $rows): string
    {
        $output = fopen('php://temp', 'r+');

        // BOM for Excel UTF-8 compatibility
        fwrite($output, "\xEF\xBB\xBF");

        fputcsv($output, $headers);
        foreach ($rows as $row) {
            fputcsv($output, $row);
        }

        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        return $csv;
    }

    /**
     * Delete the user's account.
     *
     * Delegates to UserDeletionService which:
     *   - Deletes all gallery image / audio / logo / curtain_logo files
     *   - Deletes artist portraits created by this user
     *   - Calls PlanDowngradeService to remove Coolify custom domains
     *   - Clears current_team_id for any users pointing at owned teams
     *   - Deletes the user row (DB cascade handles the rest)
     *
     * WITHOUT the service, self-serve account deletion called $user->delete()
     * and nothing else — every uploaded file stayed on disk forever. That
     * was a GDPR violation (privacy policy promises "right to delete your
     * personal information") and a disk leak.
     */
    public function destroy(Request $request, UserDeletionService $deletionService): RedirectResponse
    {
        $request->validateWithBag('userDeletion', [
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();

        Auth::logout();

        $deletionService->deleteUser($user, 'Self-serve account deletion');

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Redirect::to('/');
    }
}
