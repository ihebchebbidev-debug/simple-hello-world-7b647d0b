<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the credentials submitted to `POST /api/auth/login`.
 *
 * All error messages are resolved through the translator so the frontend
 * receives them in French or English depending on the `Accept-Language`
 * header (see `App\Http\Middleware\SetLocale`). Failing validation is
 * rendered by the global exception handler as:
 *
 *   { "error": { "code": "validation_failed", "message": "...",
 *                "details": { "fields": { "email": [...], "password": [...] } } } }
 */
final class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normalise inputs before validation so downstream code can rely on
     * a trimmed, lower-cased email regardless of client behaviour.
     */
    protected function prepareForValidation(): void
    {
        $merge = [];

        if ($this->has('email') && is_string($this->input('email'))) {
            $merge['email'] = strtolower(trim((string) $this->input('email')));
        }

        if ($this->has('device_name') && is_string($this->input('device_name'))) {
            $merge['device_name'] = trim((string) $this->input('device_name'));
        }

        if ($merge !== []) {
            $this->merge($merge);
        }
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email:rfc,strict', 'max:180'],
            'password' => ['required', 'string', 'min:1', 'max:200'],
            'device_name' => ['nullable', 'string', 'max:120'],
        ];
    }

    /**
     * Human-readable field names used when interpolating `:attribute`
     * in validation messages.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'email' => __('validation.attributes.email'),
            'password' => __('validation.attributes.password'),
            'device_name' => __('validation.attributes.device_name'),
        ];
    }

    /**
     * Field-specific overrides. Everything else falls back to the
     * default translations in `lang/{locale}/validation.php`.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.required' => __('validation.custom.email.required'),
            'email.email' => __('validation.custom.email.email'),
            'email.max' => __('validation.custom.email.max'),
            'password.required' => __('validation.custom.password.required'),
            'password.max' => __('validation.custom.password.max'),
        ];
    }
}
