<?php

declare(strict_types=1);

namespace App\Http\Requests\Pesticide;

use Illuminate\Foundation\Http\FormRequest;

final class UpdatePesticideRequest extends FormRequest
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
            'chemical_composition' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
