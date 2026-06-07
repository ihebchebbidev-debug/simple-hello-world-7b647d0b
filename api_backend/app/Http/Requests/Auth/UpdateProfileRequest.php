<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $userId = $this->user()?->id;

        return [
            'name'                  => ['sometimes', 'string', 'min:1', 'max:120'],
            'email'                 => ['sometimes', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($userId)],
            'preferred_lang'        => ['sometimes', 'string', Rule::in(['fr', 'en'])],
            'current_password'      => ['required_with:password', 'string'],
            'password'              => ['sometimes', 'string', 'min:8', 'max:255', 'confirmed'],
        ];
    }
}
