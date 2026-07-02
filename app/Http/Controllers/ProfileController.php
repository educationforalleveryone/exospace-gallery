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
     */
    public function export(Request $request): JsonResponse
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

        $filename = 'exospace-export-user-' . $user->id . '-' . now()->format('Y-m-d-His') . '.json';

        return response()->json($data, 200, [
            'Content-Type'        => 'application/json',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
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
