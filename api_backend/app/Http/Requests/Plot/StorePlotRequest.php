<?php

/**
 * Validation for creating plots.
 */

declare(strict_types=1);

namespace App\Http\Requests\Plot;

use Illuminate\Foundation\Http\FormRequest;

final class StorePlotRequest extends FormRequest
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
            'surface_area_ha' => ['required', 'numeric', 'gt:0', 'max:999999.9999', 'decimal:0,4'],
            'crop_type' => ['nullable', 'string', 'max:100'],
            'variety' => ['nullable', 'string', 'max:100'],
            'season_start_date' => ['nullable', 'date', 'date_format:Y-m-d'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
