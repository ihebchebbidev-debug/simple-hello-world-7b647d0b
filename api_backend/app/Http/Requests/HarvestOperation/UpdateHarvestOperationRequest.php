<?php

declare(strict_types=1);

namespace App\Http\Requests\HarvestOperation;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateHarvestOperationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'plot_id'              => ['sometimes', 'uuid', 'exists:plots,id'],
            'campaign_id'          => ['sometimes', 'nullable', 'uuid', 'exists:campaigns,id'],
            'operation_date'       => ['sometimes', 'date', 'date_format:Y-m-d'],
            'num_workers'          => ['sometimes', 'integer', 'min:1'],
            'days_worked'          => ['sometimes', 'numeric', 'gt:0', 'max:999.99', 'decimal:0,2'],
            'quantity_harvested'   => ['sometimes', 'numeric', 'gt:0', 'max:9999999.99', 'decimal:0,2'],
            'daily_rate_at_entry'  => ['sometimes', 'numeric', 'min:0'],
        ];
    }
}
