<?php

declare(strict_types=1);

namespace App\Http\Requests\FertilizationOperation;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateFertilizationOperationRequest extends FormRequest
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
            'fertilizer_id'    => ['sometimes', 'uuid', 'exists:fertilizers,id'],
            'operation_date'   => ['sometimes', 'date', 'date_format:Y-m-d'],
            'quantity_applied' => ['sometimes', 'numeric', 'gt:0', 'max:9999999.999', 'decimal:0,3'],
            'n_at_entry'       => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'p_at_entry'       => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'k_at_entry'       => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'price_at_entry'   => ['sometimes', 'numeric', 'min:0'],
        ];
    }
}
