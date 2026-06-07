<?php

declare(strict_types=1);

namespace App\Http\Requests\PriceHistory;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdatePriceHistoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        // When only effective_to is sent, fall back to the existing model value
        // so the date-order comparison still fires correctly.
        $effectiveFrom = $this->input('effective_from')
            ?? $this->route('priceHistory')?->effective_from;

        return [
            'price_per_unit' => ['sometimes', 'numeric', 'min:0', 'max:99999999.9999', 'decimal:0,4'],
            'unit' => ['sometimes', 'nullable', 'string', 'max:20', 'regex:/^[A-Za-z0-9%\/\-]+$/'],
            'effective_from' => ['sometimes', 'date', 'date_format:Y-m-d'],
            'effective_to' => ['sometimes', 'nullable', 'date', 'date_format:Y-m-d', Rule::afterOrEqual($effectiveFrom)],
        ];
    }
}
