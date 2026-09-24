<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\Environment;

use Cbox\Id\OAuthServer\ValueObjects\NewApi;
use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /v1/apis` — register an API (a resource server) and its scopes.
 *
 * `identifier` is an absolute URI (RFC 8707) and becomes the token's `aud`; `client_id` is
 * the app whose roles and permissions the API enforces, and must have the API's owner;
 * `organization_id` makes the API one organization's, and null — the default — the
 * environment's own. The framework validates the identifier and scope keys.
 */
final class CreateApiRequest extends FormRequest
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
            'identifier' => ['required', 'string', 'max:255'],
            'name' => ['required', 'string', 'max:120'],
            'organization_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'client_id' => ['sometimes', 'nullable', 'string', 'max:255'],
            ...ApiScopeInput::rules(),
        ];
    }

    public function organizationId(): ?string
    {
        return $this->filled('organization_id') ? $this->string('organization_id')->toString() : null;
    }

    public function toApi(): NewApi
    {
        return new NewApi(
            identifier: $this->string('identifier')->toString(),
            name: $this->string('name')->toString(),
            organizationId: $this->organizationId(),
            clientId: $this->filled('client_id') ? $this->string('client_id')->toString() : null,
            scopes: ApiScopeInput::from($this->input('scopes')),
        );
    }
}
