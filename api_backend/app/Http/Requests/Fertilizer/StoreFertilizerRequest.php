<?php

declare(strict_types=1);

namespace App\Http\Requests\Fertilizer;

use Illuminate\Foundation\Http\FormRequest;

final class StoreFertilizerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:1', 'max:100'],
            'unit' => ['required', 'string', 'max:20', 'regex:/^[A-Za-z0-9%\/\-]+$/'],
            'n_percent' => ['required', 'numeric', 'min:0', 'max:100', 'decimal:0,2'],
            'p_percent' => ['required', 'numeric', 'min:0', 'max:100', 'decimal:0,2'],
            'k_percent' => ['required', 'numeric', 'min:0', 'max:100', 'decimal:0,2'],
            'mg_percent' => ['required', 'numeric', 'min:0', 'max:100', 'decimal:0,2'],
            'ca_percent' => ['required', 'numeric', 'min:0', 'max:100', 'decimal:0,2'],
            's_percent' => ['required', 'numeric', 'min:0', 'max:100', 'decimal:0,2'],
            // kg/L — only meaningful for liquids; required for any N/ha dose maths.
            'density_kg_per_l' => ['sometimes', 'nullable', 'numeric', 'min:0.1', 'max:3', 'decimal:0,3'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
