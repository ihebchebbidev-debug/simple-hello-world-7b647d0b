<?php

declare(strict_types=1);

namespace App\Http\Requests\PriceHistory;

use App\Models\PriceHistory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class IndexPriceHistoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'entity_type' => ['nullable', 'string', Rule::in(PriceHistory::ENTITY_TYPES)],
            'entity_id' => ['nullable', 'uuid'],
            'active_on' => ['nullable', 'date', 'date_format:Y-m-d'],
            'current_only' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
