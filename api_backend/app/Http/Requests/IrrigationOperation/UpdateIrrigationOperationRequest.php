<?php

declare(strict_types=1);

namespace App\Http\Requests\IrrigationOperation;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateIrrigationOperationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'plot_id'        => ['sometimes', 'uuid', 'exists:plots,id'],
            'campaign_id'    => ['sometimes', 'nullable', 'uuid', 'exists:campaigns,id'],
            'operation_date' => ['sometimes', 'date', 'date_format:Y-m-d'],
            'water_quantity' => ['sometimes', 'numeric', 'gt:0', 'max:9999999.99', 'decimal:0,2'],
            'unit_at_entry'  => ['sometimes', 'string', 'max:20'],
            'price_at_entry' => ['sometimes', 'numeric', 'min:0'],
        ];
    }
}
