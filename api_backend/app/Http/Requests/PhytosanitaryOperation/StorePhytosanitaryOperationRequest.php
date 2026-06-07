<?php

declare(strict_types=1);

namespace App\Http\Requests\PhytosanitaryOperation;

use Illuminate\Foundation\Http\FormRequest;

final class StorePhytosanitaryOperationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'plot_id'          => ['required', 'uuid', 'exists:plots,id'],
            'campaign_id'      => ['sometimes', 'nullable', 'uuid', 'exists:campaigns,id'],
            'pesticide_id'     => ['required', 'uuid', 'exists:pesticides,id'],
            'operation_date'   => ['required', 'date', 'date_format:Y-m-d'],
            'quantity_applied' => ['required', 'numeric', 'gt:0', 'max:9999999.999', 'decimal:0,3'],
            'water_volume_l'   => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:9999999.999', 'decimal:0,3'],
            'target_pest'      => ['sometimes', 'nullable', 'string', 'max:255'],
            'remarks'          => ['sometimes', 'nullable', 'string', 'max:2000'],
            'posting_id'       => ['sometimes', 'nullable', 'uuid', 'exists:postings,id'],
        ];
    }
}
