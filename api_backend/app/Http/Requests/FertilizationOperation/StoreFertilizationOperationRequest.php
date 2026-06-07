<?php

declare(strict_types=1);

namespace App\Http\Requests\FertilizationOperation;

use Illuminate\Foundation\Http\FormRequest;

final class StoreFertilizationOperationRequest extends FormRequest
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
            'fertilizer_id'    => ['required', 'uuid', 'exists:fertilizers,id'],
            'operation_date'   => ['required', 'date', 'date_format:Y-m-d'],
            'quantity_applied' => ['required', 'numeric', 'gt:0', 'max:9999999.999', 'decimal:0,3'],
            'posting_id'       => ['sometimes', 'nullable', 'uuid', 'exists:postings,id'],
        ];
    }
}
