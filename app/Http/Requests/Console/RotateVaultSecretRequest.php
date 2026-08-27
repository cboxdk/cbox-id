<?php

declare(strict_types=1);

namespace App\Http\Requests\Console;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A replacement value for a sealed credential.
 *
 * The one moment a vault secret is handled in the clear. It is sealed on arrival and never
 * echoed back — not into the response, not into the old-input bag, which is why the
 * controller redirects rather than re-rendering with input.
 */
final class RotateVaultSecretRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'secret' => ['required', 'string'],
        ];
    }

    /** NOT trimmed: leading or trailing whitespace can be part of a credential. */
    public function secret(): string
    {
        return (string) $this->string('secret');
    }
}
