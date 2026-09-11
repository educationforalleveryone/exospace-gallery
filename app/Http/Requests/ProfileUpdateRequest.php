<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProfileUpdateRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * ITERATION-7: Changing the account's email address is an identity-critical
     * operation — the address is the login credential, the password-reset
     * destination, and the notification destination in one. It now sits behind
     * the same sudo bar the app already applies to weaker self-service actions
     * (account deletion, MFA disable): the current password.
     *
     * The gate is CONDITIONAL: name-only edits keep the one-step UX, and
     * re-saving an unchanged address does not demand a password either. The
     * comparison normalizes the submitted address to lowercase (mirroring the
     * `lowercase` validation rule below) and compares it against the stored
     * address taken EXACTLY — so a case-variant resubmission of the same
     * address is a no-op, while any real change (including a case-normalizing
     * one) fails closed and asks for the password.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $submittedEmail = trim((string) $this->input('email'));
        $emailChanging = mb_strtolower($submittedEmail) !== $this->user()->email;

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'lowercase',
                'email',
                'max:255',
                Rule::unique(User::class)->ignore($this->user()->id),
            ],
            'password' => $emailChanging
                ? ['required', 'current_password']
                : ['nullable', 'current_password'],
        ];
    }
}
