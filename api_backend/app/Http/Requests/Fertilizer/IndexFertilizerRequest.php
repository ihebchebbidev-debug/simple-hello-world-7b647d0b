<?php

declare(strict_types=1);

namespace App\Http\Requests\Fertilizer;

use Illuminate\Foundation\Http\FormRequest;

final class IndexFertilizerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'is_active' => ['nullable', 'boolean'],
            'search' => ['nullable', 'string', 'max:120', 'regex:/^[\p{L}\p{N}\s._\-]+$/u'],
            'unit' => ['nullable', 'string', 'max:20', 'regex:/^[A-Za-z0-9%\/\-]+$/'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
