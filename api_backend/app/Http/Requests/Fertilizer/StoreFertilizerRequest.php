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
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
