<?php

declare(strict_types=1);

namespace App\Http\Requests\PriceHistory;

use App\Models\PriceHistory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StorePriceHistoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'entity_type' => ['required', 'string', Rule::in(PriceHistory::ENTITY_TYPES)],
            'entity_id' => ['nullable', 'uuid'],
            'price_per_unit' => ['required', 'numeric', 'min:0', 'max:99999999.9999', 'decimal:0,4'],
            'unit' => ['nullable', 'string', 'max:20', 'regex:/^[A-Za-z0-9%\/\-]+$/'],
            'effective_from' => ['required', 'date', 'date_format:Y-m-d'],
            'effective_to' => ['nullable', 'date', 'date_format:Y-m-d', 'after_or_equal:effective_from'],
        ];
    }
}
