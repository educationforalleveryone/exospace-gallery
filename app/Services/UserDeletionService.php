<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Gallery;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class UserDeletionService
{
    public function deleteUser(User $user, string $reason): void
    {
        Log::info('UserDeletionService: deleting user', [
            'user_id' => $user->id,
            'reason' => $reason,
        ]);

        $this->transferTeamGalleriesToTeamOwners($user);

        $user->galleries()->with('images')->chunkById(50, function ($galleries) {
            foreach ($galleries as $gallery) {
                $this->deleteGalleryFiles($gallery);
            }
        });

        $userEmail = strtolower($user->email ?? '');
        $user->createdArtists()->whereNotNull('portrait_path')->chunkById(50, function ($artists) use ($userEmail) {
            foreach ($artists as $artist) {
                $this->deletePublicDiskFile($artist->getOriginal('portrait_path'));
                $updates = ['portrait_path' => null];

                if ($userEmail && strtolower($artist->email ?? '') === $userEmail) {
                    $updates['email'] = null;
                    $updates['name'] = 'Anonymous Artist';
                }

                $artist->forceFill($updates)->save();
            }
        });

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

        app(PlanDowngradeService::class)
            ->downgradeToFree($user, "User deletion: {$reason}");

        DB::transaction(function () use ($user) {
            foreach ($user->ownedTeams as $team) {
                User::where('current_team_id', $team->id)
                    ->where('id', '!=', $user->id)
                    ->update(['current_team_id' => null]);
            }

            $user->forceFill(['current_team_id' => null])->save();

            $this->anonymizeUserTransactions($user);

            $this->anonymizeUserInvoices($user);

            DB::table('password_reset_tokens')->where('email', $user->email)->delete();

            $user->delete();
        });

        Log::info('UserDeletionService: user deleted', [
            'user_id' => $user->id,
            'reason' => $reason,
        ]);
    }

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

    private function anonymizeUserTransactions(User $user): void
    {
        $appId = config('app.key');
        $anonymizedEmail = 'anonymized:'.substr(hash('sha256', $appId.$user->email), 0, 16);

        $count = DB::table('transactions')
            ->where('user_id', $user->id)
            ->update([
                'customer_email' => $anonymizedEmail,
                'customer_name' => null,
                'user_id' => null,
                'updated_at' => now(),
            ]);

        Log::info('UserDeletionService: anonymized user transactions', [
            'user_id' => $user->id,
            'transactions_count' => $count,
        ]);
    }

    private function anonymizeUserInvoices(User $user): void
    {
        $appId = config('app.key');
        $anonymizedEmail = 'anonymized:'.substr(hash('sha256', $appId.$user->email), 0, 16);

        $count = DB::table('invoices')
            ->where('user_id', $user->id)
            ->update([
                'customer_email' => $anonymizedEmail,
                'customer_name' => null,
                'billing_address' => null,
                'user_id' => null,
                'updated_at' => now(),
            ]);

        Log::info('UserDeletionService: anonymized user invoices', [
            'user_id' => $user->id,
            'invoices_count' => $count,
        ]);
    }

    public function deleteGalleryFiles(Gallery $gallery): void
    {
        foreach ($gallery->images as $image) {
            try {
                $image->clearMediaCollection('original');
            } catch (\Throwable $e) {
                Log::warning('UserDeletionService: clearMediaCollection failed', [
                    'image_id' => $image->id,
                    'error' => $e->getMessage(),
                ]);
            }

            $this->deletePublicDiskFile($image->getOriginal('path'));
        }

        foreach (['audio_path', 'custom_logo_path', 'curtain_logo_path'] as $field) {
            $path = $gallery->getOriginal($field);
            if (! empty($path)) {
                $this->deletePublicDiskFile($path);
            }
        }
    }

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
