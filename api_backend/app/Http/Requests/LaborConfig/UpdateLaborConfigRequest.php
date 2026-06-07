<?php

declare(strict_types=1);

namespace App\Http\Requests\LaborConfig;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateLaborConfigRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
