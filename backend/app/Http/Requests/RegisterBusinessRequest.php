<?php

namespace App\Http\Requests;

use App\Services\Platform;
use Illuminate\Validation\Rule;

class RegisterBusinessRequest extends LoginRequest
{
    public function rules(): array
    {
        $settings = app(Platform::class)->settings();
        $this->attributes->set('registration_settings', $settings);

        return [
            ...parent::rules(),
            'name' => ['required', 'string', 'max:120'],
            'business_name' => ['required_without:token', Rule::prohibitedIf($this->filled('token')), 'nullable', 'string', 'max:160'],
            'token' => ['nullable', 'string', 'size:64'],
            'currency' => ['nullable', 'string', Rule::in($settings['currencies'])],
            'legal_accepted' => ['accepted'],
            'legal_version' => ['required', Rule::in([$settings['legal_version']])],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            // Bcrypt accepts at most 72 bytes. A character limit alone would allow longer UTF-8 input.
            'password' => ['required', 'string', 'min:'.$settings['password_minimum'], 'confirmed', 'regex:/\A[^\x00]{1,72}\z/'],
        ];
    }
}
