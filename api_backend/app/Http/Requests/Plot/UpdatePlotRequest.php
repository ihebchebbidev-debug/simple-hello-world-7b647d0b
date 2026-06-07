<?php

/**
 * Validation for updating plots.
 */

declare(strict_types=1);

namespace App\Http\Requests\Plot;

use Illuminate\Foundation\Http\FormRequest;

final class UpdatePlotRequest extends FormRequest
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
            'surface_area_ha' => ['sometimes', 'numeric', 'gt:0', 'max:999999.9999', 'decimal:0,4'],
            'crop_type' => ['sometimes', 'nullable', 'string', 'max:100'],
            'variety' => ['sometimes', 'nullable', 'string', 'max:100'],
            'season_start_date' => ['sometimes', 'date', 'date_format:Y-m-d'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
