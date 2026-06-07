<?php

declare(strict_types=1);

namespace App\Http\Requests\IrrigationOperation;

use Illuminate\Foundation\Http\FormRequest;

final class IndexIrrigationOperationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'plot_id'   => ['sometimes', 'uuid', 'exists:plots,id'],
            'date_from' => ['sometimes', 'date', 'date_format:Y-m-d'],
            'date_to'   => ['sometimes', 'date', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'per_page'  => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
