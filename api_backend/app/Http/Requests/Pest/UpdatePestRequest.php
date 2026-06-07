<?php

declare(strict_types=1);

namespace App\Http\Requests\Pest;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdatePestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $id = $this->route('pest')?->id ?? null;

        return [
            'name'            => ['sometimes', 'string', 'min:1', 'max:120', Rule::unique('pests', 'name')->ignore($id)],
            'scientific_name' => ['sometimes', 'nullable', 'string', 'max:160'],
            'category'        => ['sometimes', 'nullable', 'string', 'max:60'],
            'description'     => ['sometimes', 'nullable', 'string', 'max:2000'],
            'is_active'       => ['sometimes', 'boolean'],
        ];
    }
}
