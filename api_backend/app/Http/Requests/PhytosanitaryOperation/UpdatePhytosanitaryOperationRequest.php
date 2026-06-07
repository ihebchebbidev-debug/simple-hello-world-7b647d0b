<?php

declare(strict_types=1);

namespace App\Http\Requests\PhytosanitaryOperation;

use Illuminate\Foundation\Http\FormRequest;

final class UpdatePhytosanitaryOperationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'plot_id'          => ['sometimes', 'uuid', 'exists:plots,id'],
            'campaign_id'      => ['sometimes', 'nullable', 'uuid', 'exists:campaigns,id'],
            'pesticide_id'     => ['sometimes', 'uuid', 'exists:pesticides,id'],
            'operation_date'   => ['sometimes', 'date', 'date_format:Y-m-d'],
            'quantity_applied' => ['sometimes', 'numeric', 'gt:0', 'max:9999999.999', 'decimal:0,3'],
            'water_volume_l'   => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:9999999.999', 'decimal:0,3'],
            'target_pest'      => ['sometimes', 'nullable', 'string', 'max:255'],
            'remarks'          => ['sometimes', 'nullable', 'string', 'max:2000'],
            'price_at_entry'   => ['sometimes', 'numeric', 'min:0'],
        ];
    }
}
