<?php

declare(strict_types=1);

namespace App\Http\Requests\Fertilizer;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateFertilizerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'min:1', 'max:100'],
            'unit' => ['sometimes', 'string', 'max:20', 'regex:/^[A-Za-z0-9%\/\-]+$/'],
            'n_percent' => ['sometimes', 'numeric', 'min:0', 'max:100', 'decimal:0,2'],
            'p_percent' => ['sometimes', 'numeric', 'min:0', 'max:100', 'decimal:0,2'],
            'k_percent' => ['sometimes', 'numeric', 'min:0', 'max:100', 'decimal:0,2'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
