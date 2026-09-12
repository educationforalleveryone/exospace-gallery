<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProfileUpdateRequest extends FormRequest
{
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
