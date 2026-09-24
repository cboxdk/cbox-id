<?php

declare(strict_types=1);

namespace App\Http\Requests\Console;

use Illuminate\Foundation\Http\FormRequest;

/**
 * An API's name, and the app whose roles and permissions it enforces. The identifier and
 * the owner are not here: the identifier is in every token already minted for the API, and
 * the owner decides which apps may hold its scopes — changing either is registering a
 * different API.
 */
final class UpdateApiRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:120'],
            'clientId' => ['nullable', 'string', 'max:64'],
        ];
    }

    public function name(): string
    {
        return trim((string) $this->string('name'));
    }

    public function clientId(): ?string
    {
        $clientId = trim((string) $this->string('clientId'));

        return $clientId === '' ? null : $clientId;
    }
}
