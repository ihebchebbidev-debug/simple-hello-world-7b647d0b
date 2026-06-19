<?php

declare(strict_types=1);

namespace App\Http\Requests\BackupSnapshot;

use Illuminate\Foundation\Http\FormRequest;

final class StoreBackupSnapshotRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'label' => ['nullable', 'string', 'max:120'],
        ];
    }
}
