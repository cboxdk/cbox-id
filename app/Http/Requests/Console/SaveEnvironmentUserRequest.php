<?php

declare(strict_types=1);

namespace App\Http\Requests\Console;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The profile of one end-user: their name, and the address that is also their recovery
 * channel.
 */
final class SaveEnvironmentUserRequest extends FormRequest
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
            'name' => ['nullable', 'string', 'max:190'],
            'email' => ['required', 'email', 'max:190'],
        ];
    }

    /**
     * The contract reads an empty string as "no name given" and null as "leave it alone",
     * and this form always states the name — a cleared field means cleared.
     */
    public function name(): string
    {
        return trim((string) $this->string('name'));
    }

    public function email(): string
    {
        return trim((string) $this->string('email'));
    }
}
