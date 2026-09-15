<?php

namespace App\Http\Controllers;

use App\Models\Gallery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class GalleryPinController extends Controller
{
    private const MAX_FAILED_ATTEMPTS = 5;
    private const LOCKOUT_MINUTES = 15;

    public function show(string $slug)
    {
        $gallery = Gallery::publiclyAccessible()->where('slug', $slug)->firstOrFail();

        if (!$gallery->hasPinProtection()) {
            return redirect()->route('gallery.view', $slug);
        }

        // Already verified in this session?
        if (session("pin_verified_{$gallery->id}")) {
            return redirect()->route('gallery.view', $slug);
        }

        return view('gallery.pin', compact('gallery'));
    }

    public function verify(Request $request, string $slug)
    {
        $gallery = Gallery::publiclyAccessible()->where('slug', $slug)->firstOrFail();

        $request->validate(['pin' => 'required|digits:4']);

        $lockoutKey = $this->lockoutKey($gallery->id, $request->ip());
        $attemptsKey = $this->attemptsKey($gallery->id, $request->ip());

        $lockedUntil = null;
        try {
            $lockedUntil = Cache::get($lockoutKey);
        } catch (\Throwable $e) {
            // Cache down: the PIN itself is still checked against the DB
            // hash, only the attempt-counting lockout is suspended.
            Log::warning('PIN lockout check unavailable — proceeding without lockout', [
                'gallery_id' => $gallery->id,
                'error'      => $e->getMessage(),
            ]);
        }

        if ($lockedUntil !== null && $lockedUntil > now()) {
            $minutes = (int) ceil(now()->diffInSeconds($lockedUntil) / 60);
            return back()
                ->withErrors(['pin' => "Too many incorrect attempts. This gallery is locked for {$minutes} minute(s). Please try again later."])
                ->withInput();
        }

        if ($gallery->verifyPin($request->pin)) {
            // ── Success: clear the failed-attempts counter ──
            try {
                Cache::forget($attemptsKey);
            } catch (\Throwable $e) {
                Log::warning('PIN attempt counter could not be cleared', [
                    'gallery_id' => $gallery->id,
                    'error'      => $e->getMessage(),
                ]);
            }
            session(["pin_verified_{$gallery->id}" => true]);
            return redirect()->route('gallery.view', $slug);
        }

        // ── Failure: increment the counter, maybe lock out ──
        $attempts = null;
        try {
            // Seed with a TTL first: a bare INCR on a missing key would
            // create a counter that never expires.
            Cache::add($attemptsKey, 0, now()->addMinutes(self::LOCKOUT_MINUTES * 4));
            $attempts = Cache::increment($attemptsKey);
        } catch (\Throwable $e) {
            Log::warning('PIN attempt counting unavailable — lockout not enforced', [
                'gallery_id' => $gallery->id,
                'error'      => $e->getMessage(),
            ]);
        }

        if ($attempts !== null && $attempts >= self::MAX_FAILED_ATTEMPTS) {
            Cache::put($lockoutKey, now()->addMinutes(self::LOCKOUT_MINUTES), now()->addMinutes(self::LOCKOUT_MINUTES));
            Cache::forget($attemptsKey);

            return back()
                ->withErrors(['pin' => 'Too many incorrect attempts. This gallery has been locked for ' . self::LOCKOUT_MINUTES . ' minutes. Please try again later.'])
                ->withInput();
        }

        if ($attempts === null) {
            return back()
                ->withErrors(['pin' => 'Incorrect PIN.'])
                ->withInput();
        }

        $remaining = self::MAX_FAILED_ATTEMPTS - $attempts;
        return back()
            ->withErrors(['pin' => "Incorrect PIN. {$remaining} attempt(s) remaining before temporary lockout."])
            ->withInput();
    }

    private function lockoutKey(int $galleryId, string $ip): string
    {
        return "pin:lockout:{$galleryId}:{$ip}";
    }

    private function attemptsKey(int $galleryId, string $ip): string
    {
        return "pin:attempts:{$galleryId}:{$ip}";
    }
}
