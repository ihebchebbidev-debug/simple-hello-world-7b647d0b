<?php

declare(strict_types=1);

namespace App\Http\Requests\Pest;

use Illuminate\Foundation\Http\FormRequest;

final class IndexPestRequest extends FormRequest
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
            'category'  => ['nullable', 'string', 'max:60'],
            'search'    => ['nullable', 'string', 'max:120'],
            'per_page'  => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
