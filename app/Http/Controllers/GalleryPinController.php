<?php

namespace App\Http\Controllers;

use App\Models\Gallery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

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

        $lockedUntil = Cache::get($lockoutKey);
        if ($lockedUntil !== null && $lockedUntil > now()) {
            $minutes = (int) ceil(now()->diffInSeconds($lockedUntil) / 60);
            return back()
                ->withErrors(['pin' => "Too many incorrect attempts. This gallery is locked for {$minutes} minute(s). Please try again later."])
                ->withInput();
        }

        if ($gallery->verifyPin($request->pin)) {
            // ── Success: clear the failed-attempts counter ──
            Cache::forget($attemptsKey);
            session(["pin_verified_{$gallery->id}" => true]);
            return redirect()->route('gallery.view', $slug);
        }

        // ── Failure: increment the counter, maybe lock out ──
        $attempts = Cache::increment($attemptsKey);
        // Ensure the counter has a TTL so it expires if the attacker gives up
        if ($attempts === 1) {
            Cache::put($attemptsKey, 1, now()->addMinutes(self::LOCKOUT_MINUTES * 4));
        }

        if ($attempts >= self::MAX_FAILED_ATTEMPTS) {
            Cache::put($lockoutKey, now()->addMinutes(self::LOCKOUT_MINUTES), now()->addMinutes(self::LOCKOUT_MINUTES));
            Cache::forget($attemptsKey);

            return back()
                ->withErrors(['pin' => 'Too many incorrect attempts. This gallery has been locked for ' . self::LOCKOUT_MINUTES . ' minutes. Please try again later.'])
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
