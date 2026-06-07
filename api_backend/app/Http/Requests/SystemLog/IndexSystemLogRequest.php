<?php

declare(strict_types=1);

namespace App\Http\Requests\SystemLog;

use App\Models\SystemLog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class IndexSystemLogRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'level'       => ['nullable', Rule::in(SystemLog::LEVELS)],
            'category'    => ['nullable', 'string', 'max:50'],
            'action'      => ['nullable', 'string', 'max:100'],
            'entity_type' => ['nullable', 'string', 'max:50'],
            'entity_id'   => ['nullable', 'uuid'],
            'user_id'     => ['nullable', 'uuid', 'exists:users,id'],
            'date_from'   => ['nullable', 'date', 'date_format:Y-m-d'],
            'date_to'     => ['nullable', 'date', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'search'      => ['nullable', 'string', 'max:120'],
            'per_page'    => ['nullable', 'integer', 'min:1', 'max:200'],
        ];
    }
}
