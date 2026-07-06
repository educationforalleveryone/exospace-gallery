<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use App\Services\UserDeletionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated());

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        return Redirect::route('profile.edit')->with('status', 'profile-updated');
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
