<?php

declare(strict_types=1);

namespace App\Http\Requests\HarvestOperation;

use Illuminate\Foundation\Http\FormRequest;

final class StoreHarvestOperationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'plot_id'            => ['required', 'uuid', 'exists:plots,id'],
            'campaign_id'        => ['sometimes', 'nullable', 'uuid', 'exists:campaigns,id'],
            'operation_date'     => ['required', 'date', 'date_format:Y-m-d'],
            'num_workers'        => ['required', 'integer', 'min:1'],
            'days_worked'        => ['required', 'numeric', 'gt:0', 'max:999.99', 'decimal:0,2'],
            'quantity_harvested' => ['required', 'numeric', 'gt:0', 'max:9999999.99', 'decimal:0,2'],
            'posting_id'         => ['sometimes', 'nullable', 'uuid', 'exists:postings,id'],
        ];
    }
}
