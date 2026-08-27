<?php

declare(strict_types=1);

namespace App\Http\Requests\Console;

use Cbox\Id\Platform\Enums\EnvironmentApiScope;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A new environment management-plane key.
 *
 * THE SCOPES ARE THE CREDENTIAL. A key with none is not a weaker key, it is a key that can
 * do nothing — so at least one is required, and every one is checked against the enum
 * rather than trusted. The environment is checked for REACHABILITY by the controller,
 * because whether a given id is one the caller may mint against is an authorization
 * question and not a shape question.
 */
final class IssueEnvironmentKeyRequest extends FormRequest
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
            'environment' => ['required', 'string'],
            'name' => ['required', 'string', 'max:120'],
            'scopes' => ['required', 'array', 'min:1'],
            'scopes.*' => [Rule::in(EnvironmentApiScope::all())],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'scopes.required' => 'Choose at least one scope — a key with none can do nothing.',
            'scopes.min' => 'Choose at least one scope — a key with none can do nothing.',
        ];
    }

    public function environmentId(): string
    {
        return trim((string) $this->string('environment'));
    }

    public function name(): string
    {
        return trim((string) $this->string('name'));
    }

    /**
     * @return list<string>
     */
    public function scopes(): array
    {
        return array_values(array_unique(array_filter(
            (array) $this->input('scopes', []),
            'is_string',
        )));
    }
}
