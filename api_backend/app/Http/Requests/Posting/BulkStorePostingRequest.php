<?php

declare(strict_types=1);

namespace App\Http\Requests\Posting;

use App\Enums\OperationType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Bulk submission of offline-queued postings. Each item is validated using
 * the same rules as the single-store request — the controller then replays
 * them through the same idempotent dispatcher.
 */
final class BulkStorePostingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'postings'                  => ['required', 'array', 'min:1', 'max:200'],
            'postings.*.client_id'      => ['required', 'string', 'max:100', 'distinct'],
            'postings.*.operation_type' => ['required', 'string', Rule::in(OperationType::values())],
            'postings.*.payload'        => ['required', 'array'],
            'postings.*.submitted_at'   => ['sometimes', 'nullable', 'date'],
            'postings.*.device_info'    => ['sometimes', 'nullable', 'array'],
        ];
    }
}
