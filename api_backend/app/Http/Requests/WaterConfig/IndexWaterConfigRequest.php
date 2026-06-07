<?php

declare(strict_types=1);

namespace App\Http\Requests\WaterConfig;

use Illuminate\Foundation\Http\FormRequest;

final class IndexWaterConfigRequest extends FormRequest
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
            'unit' => ['nullable', 'string', 'max:20', 'regex:/^[A-Za-z0-9%\/\-]+$/'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
