<?php

declare(strict_types=1);

namespace App\Http\Requests\Posting;

use App\Enums\OperationType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StorePostingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'client_id'      => ['required', 'string', 'max:100'],
            'operation_type' => ['required', 'string', Rule::in(OperationType::values())],
            'payload'        => ['required', 'array'],
            'submitted_at'   => ['sometimes', 'nullable', 'date'],
            'device_info'    => ['sometimes', 'nullable', 'array'],
        ];
    }
}
