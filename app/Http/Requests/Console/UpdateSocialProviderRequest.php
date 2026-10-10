<?php

declare(strict_types=1);

namespace App\Http\Requests\Console;

use Illuminate\Foundation\Http\FormRequest;

/**
 * New credentials, provider values or extra scopes for a social provider that exists.
 * Blank secrets — the client secret, Apple's private key — keep the ones on file.
 */
final class UpdateSocialProviderRequest extends FormRequest
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
            'clientId' => ['required', 'string', 'max:400'],
            'clientSecret' => ['nullable', 'string', 'max:5000'],
            'parameters' => ['array'],
            'parameters.*' => ['nullable', 'string', 'max:5000'],
            'scopes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * The action's input.
     *
     * @return array{client_id: string, client_secret: string, parameters: array<string, string>, scopes: list<string>}
     */
    public function changes(): array
    {
        $parameters = [];

        foreach ((array) $this->input('parameters', []) as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $parameters[$key] = trim($value);
            }
        }

        return [
            'client_id' => trim((string) $this->string('clientId')),
            'client_secret' => trim((string) $this->string('clientSecret')),
            'parameters' => $parameters,
            'scopes' => array_values(array_filter(preg_split('/[\s,]+/', (string) $this->string('scopes')) ?: [], static fn (string $scope): bool => $scope !== '')),
        ];
    }
}
