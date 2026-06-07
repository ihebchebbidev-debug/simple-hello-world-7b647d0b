<?php

declare(strict_types=1);

namespace App\Http\Requests\IrrigationOperation;

use Illuminate\Foundation\Http\FormRequest;

final class StoreIrrigationOperationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'plot_id'        => ['required', 'uuid', 'exists:plots,id'],
            'campaign_id'    => ['sometimes', 'nullable', 'uuid', 'exists:campaigns,id'],
            'operation_date' => ['required', 'date', 'date_format:Y-m-d'],
            'water_quantity' => ['required', 'numeric', 'gt:0', 'max:9999999.99', 'decimal:0,2'],
            'posting_id'     => ['sometimes', 'nullable', 'uuid', 'exists:postings,id'],
        ];
    }
}
