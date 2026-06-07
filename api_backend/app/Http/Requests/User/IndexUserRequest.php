<?php

declare(strict_types=1);

namespace App\Http\Requests\User;

use App\Enums\AppRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class IndexUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'role' => ['nullable', 'string', Rule::in(AppRole::values())],
            'is_active' => ['nullable', 'boolean'],
            'search' => ['nullable', 'string', 'max:120', 'regex:/^[\p{L}\p{N}\s@._\-]+$/u'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
