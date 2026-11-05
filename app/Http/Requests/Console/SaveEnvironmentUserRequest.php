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
            'name' => [$this->has('email') ? 'sometimes' : 'present', 'nullable', 'string', 'max:190'],
            'email' => ['sometimes', 'required', 'email', 'max:190'],
        ];
    }

    /**
     * The contract reads an empty string as "no name given" and null as "leave it alone",
     * so an explicitly cleared field means cleared. Omitted fields are not sent to the action.
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
