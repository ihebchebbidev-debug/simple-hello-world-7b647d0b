<?php

declare(strict_types=1);

namespace App\Http\Requests\User;

use App\Enums\AppRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

final class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'string', 'email:rfc', 'max:180', 'unique:users,email'],
            'password' => ['required', 'string', 'max:200', Password::min(8)],
            'roles' => ['required', 'array', 'min:1', 'max:3'],
            'roles.*' => ['required', 'string', Rule::in(AppRole::values())],
            'preferred_lang' => ['nullable', 'string', 'max:5', Rule::in(['fr', 'en'])],
        ];
    }
}
