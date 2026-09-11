<?php

namespace App\Http\Requests\Auth;

use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * Attempt to authenticate the request's credentials.
     *
     * LOGIN-ITERATION FIX (banned users at login time): previously a banned
     * user could complete a "successful" login — CheckBanned only ran on
     * their NEXT request, so the flow was: POST /login succeeds → session
     * created + regenerated → bounce to /dashboard → immediately logged out
     * with the ban error. Secure, but confusing and it minted a session for
     * a user who must never have one.
     *
     * Now ban status is checked the moment credentials are validated:
     * the guard is logged out, no session auth state is kept, the rate
     * limiter is intentionally NOT cleared (banned accounts keep counting
     * toward the lockout), and the same sanitized ban message used by
     * CheckBanned is returned on the email error bag.
     *
     * CheckBanned middleware remains the defense-in-depth layer for
     * sessions authenticated BEFORE a ban was issued (it also purges
     * sessions + API tokens at that point).
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        if (! Auth::attempt($this->only('email', 'password'), $this->boolean('remember'))) {
            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages([
                'email' => trans('auth.failed'),
            ]);
        }

        // The credentials are valid — reject the login if the account is
        // banned. Re-read the ban state from the DB rather than trusting
        // the hydrated model (same reasoning as CheckBanned: the model can
        // be stale relative to a just-issued ban).
        $user = Auth::user();
        $bannedAt = $user->fresh()?->banned_at;

        if (! is_null($bannedAt)) {
            Auth::guard('web')->logout();

            $reason = $user->ban_reason ?: 'Your account has been suspended.';
            // Same sanitization contract as CheckBanned: strip tags, cap
            // at 200 chars — never reflect raw admin-entered text.
            $reason = mb_substr(strip_tags($reason), 0, 200);

            throw ValidationException::withMessages([
                'email' => "Your account has been banned. Reason: {$reason}",
            ]);
        }

        RateLimiter::clear($this->throttleKey());
    }

    /**
     * Ensure the login request is not rate limited.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'email' => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    /**
     * Get the rate limiting throttle key for the request.
     */
    public function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->string('email')).'|'.$this->ip());
    }
}
