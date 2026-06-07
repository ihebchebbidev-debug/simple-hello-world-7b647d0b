<?php

declare(strict_types=1);

namespace App\Http\Requests\Pest;

use Illuminate\Foundation\Http\FormRequest;

final class StorePestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'name'            => ['required', 'string', 'min:1', 'max:120', 'unique:pests,name'],
            'scientific_name' => ['nullable', 'string', 'max:160'],
            'category'        => ['nullable', 'string', 'max:60'],
            'description'     => ['nullable', 'string', 'max:2000'],
            'is_active'       => ['sometimes', 'boolean'],
        ];
    }
}
