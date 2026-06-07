<?php

declare(strict_types=1);

namespace App\Http\Requests\Plot;

use Illuminate\Foundation\Http\FormRequest;

final class IndexPlotRequest extends FormRequest
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
            'crop_type' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
