<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\Environment;

use Cbox\Id\OAuthServer\ValueObjects\ApiScopeDefinition;
use Illuminate\Foundation\Http\FormRequest;

/**
 * `PATCH /v1/apis/{id}` — any of: a new `name`; the linked `client_id` (null unlinks); and
 * `scopes`, which is the API's COMPLETE scope set afterwards: scopes named are defined or
 * updated, scopes left out are removed. The identifier never changes — it is every issued
 * token's `aud`; register a new API instead.
 */
final class UpdateApiRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:120'],
            'client_id' => ['sometimes', 'nullable', 'string', 'max:255'],
            ...ApiScopeInput::rules(),
        ];
    }

    public function name(): ?string
    {
        return $this->has('name') ? $this->string('name')->toString() : null;
    }

    /** Whether the request says anything about the linked app — null included. */
    public function changesClient(): bool
    {
        return $this->exists('client_id');
    }

    public function clientId(): ?string
    {
        return $this->filled('client_id') ? $this->string('client_id')->toString() : null;
    }

    /**
     * The complete scope set, or null when the request leaves scopes alone.
     *
     * @return list<ApiScopeDefinition>|null
     */
    public function scopes(): ?array
    {
        return $this->exists('scopes') ? ApiScopeInput::from($this->input('scopes')) : null;
    }
}
