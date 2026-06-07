<?php

declare(strict_types=1);

namespace App\Http\Requests\SystemLog;

use App\Models\SystemLog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreSystemLogRequest extends FormRequest
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
            'category'    => ['required', 'string', 'max:50'],
            'action'      => ['required', 'string', 'max:100'],
            'entity_type' => ['nullable', 'string', 'max:50'],
            'entity_id'   => ['nullable', 'uuid'],
            'details'     => ['nullable', 'array'],
        ];
    }
}
