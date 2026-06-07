<?php

declare(strict_types=1);

namespace App\Http\Requests\WaterConfig;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateWaterConfigRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'unit' => ['sometimes', 'string', 'max:20', 'regex:/^[A-Za-z0-9%\/\-]+$/'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
