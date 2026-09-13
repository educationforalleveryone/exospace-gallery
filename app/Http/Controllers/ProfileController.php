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
    public function edit(Request $request): View
    {
        return view('profile.edit', [
            'user' => $request->user(),
        ]);
    }

    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $user = $request->user();
        $oldEmail = $user->email;

        $user->fill($request->safe()->only(['name', 'email']));

        // EXISTING PRODUCT DESIGN: a changed address starts unverified.
        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        try {
            $user->save();
        } catch (UniqueConstraintViolationException $e) {
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

        $user->sendEmailVerificationNotification();

        // Security notice to the OLD address — the compromise signal channel.
        Mail::to($oldEmail)->send(new EmailChangedNoticeMail($user, $oldEmail));

        // Forensic visibility, matching the mfa.enabled/mfa.disabled precedent.
        AdminAuditLog::record('email_changed', $user, [
            'from' => $oldEmail,
            'to' => $user->email,
        ]);

        return redirect()
            ->route('verification.notice')
            ->with('status', 'verification-link-sent');
    }

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

        // Generate a ZIP archive with JSON + CSV + README
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

    public function destroy(Request $request, UserDeletionService $deletionService): RedirectResponse
    {
        $request->validateWithBag('userDeletion', [
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();

        AdminAuditLog::record('user_deleted', $user, [
            'email'      => $user->email,
            'plan'       => $user->plan,
            'self_serve' => true,
        ]);

        Auth::logout();

        $deletionService->deleteUser($user, 'Self-serve account deletion');

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Redirect::to('/');
    }
}
