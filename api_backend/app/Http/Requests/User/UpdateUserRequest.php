<?php

declare(strict_types=1);

namespace App\Http\Requests\User;

use App\Enums\AppRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

final class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $userId = (string) $this->route('id');

        return [
            'name' => ['sometimes', 'string', 'min:2', 'max:120'],
            'email' => ['sometimes', 'string', 'email:rfc', 'max:180', Rule::unique('users', 'email')->ignore($userId)],
            'password' => ['sometimes', 'string', 'max:200', Password::min(8)],
            'roles' => ['sometimes', 'array', 'min:1', 'max:3'],
            'roles.*' => ['required_with:roles', 'string', Rule::in(AppRole::values())],
            'preferred_lang' => ['sometimes', 'string', 'max:5', Rule::in(['fr', 'en'])],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
