<?php

declare(strict_types=1);

namespace App\Http\Requests\Campaign;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateCampaignRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $id = $this->route('campaign')?->id ?? null;

        // When only end_date is sent (start_date absent from the request),
        // fall back to the existing model value so the comparison still works.
        $startDate = $this->input('start_date')
            ?? $this->route('campaign')?->start_date;

        return [
            'name'       => ['sometimes', 'string', 'min:1', 'max:120', Rule::unique('campaigns', 'name')->ignore($id)],
            'start_date' => ['sometimes', 'date', 'date_format:Y-m-d'],
            'end_date'   => ['sometimes', 'nullable', 'date', 'date_format:Y-m-d', Rule::afterOrEqual($startDate)],
            'is_active'  => ['sometimes', 'boolean'],
        ];
    }
}
