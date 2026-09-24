<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\Environment;

use App\Platform\AppKind;
use Cbox\Id\OAuthServer\Enums\ClientType;
use Cbox\Id\OAuthServer\Exceptions\InvalidClientMetadata;
use Cbox\Id\OAuthServer\ValueObjects\ClientBlueprint;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `POST /v1/apps` — register an app, from a {@see ClientBlueprint} or from a short form.
 *
 * A BLUEPRINT is what `GET /v1/apps/{id}/blueprint` returns in another environment: send it
 * as `blueprint` to create the same app here (promote staging to production). Its redirect
 * URIs usually name the environment it came from, so `redirect_uris`,
 * `post_logout_redirect_uris` and `name` beside it replace the blueprint's own.
 *
 * The SHORT FORM is the console's: a `name` and a `type` — `web`, `spa`, `cli`, `service`,
 * `agent` — which decides the client type, the grants and the default scopes, exactly as
 * the console's "Create app" does. `advanced` takes `client_type` and `grant_types` by hand.
 *
 * Either way it becomes one blueprint, imported by the framework's registry: one path, one
 * set of rules.
 */
final class CreateAppRequest extends FormRequest
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
            'blueprint' => ['sometimes', 'array'],
            'name' => ['required_without:blueprint', 'string', 'min:1', 'max:120'],
            'type' => ['sometimes', Rule::enum(AppKind::class)],
            'client_type' => ['required_if:type,advanced', Rule::enum(ClientType::class)],
            'grant_types' => ['required_if:type,advanced', 'array'],
            'grant_types.*' => ['string', 'max:100'],
            'redirect_uris' => ['sometimes', 'array', 'max:50'],
            'redirect_uris.*' => ['string', 'max:2048'],
            'post_logout_redirect_uris' => ['sometimes', 'array', 'max:50'],
            'post_logout_redirect_uris.*' => ['string', 'max:2048'],
            'scopes' => ['sometimes', 'array', 'max:100'],
            'scopes.*' => ['string', 'max:128'],
            'first_party' => ['sometimes', 'boolean'],
            'organization_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'jwks' => ['sometimes', 'nullable', 'array'],
        ];
    }

    public function organizationId(): ?string
    {
        return $this->filled('organization_id') ? $this->string('organization_id')->toString() : null;
    }

    /**
     * The public key set a `private_key_jwt` blueprint is imported with — never part of a
     * blueprint, because a separate environment should hold separate keys.
     *
     * @return array<string, mixed>|null
     */
    public function jwks(): ?array
    {
        $jwks = $this->input('jwks');

        if (! is_array($jwks)) {
            return null;
        }

        $out = [];

        foreach ($jwks as $key => $value) {
            $out[(string) $key] = $value;
        }

        return $out;
    }

    /**
     * @throws InvalidClientMetadata when the blueprint document or the short form describes
     *                               an app the registry would refuse
     */
    public function blueprint(): ClientBlueprint
    {
        $document = $this->input('blueprint');

        $blueprint = is_array($document)
            ? ClientBlueprint::fromArray($document)
            : $this->fromShortForm();

        if ($this->has('name') && is_array($document)) {
            $blueprint = $blueprint->withName(trim($this->string('name')->toString()));
        }

        if ($this->has('redirect_uris') && is_array($document)) {
            $blueprint = $blueprint->withRedirectUris($this->strings('redirect_uris'));
        }

        if ($this->has('post_logout_redirect_uris') && is_array($document)) {
            $blueprint = $blueprint->withPostLogoutRedirectUris($this->strings('post_logout_redirect_uris'));
        }

        $blueprint->assertValid();

        return $blueprint;
    }

    private function fromShortForm(): ClientBlueprint
    {
        $kind = $this->enum('type', AppKind::class) ?? AppKind::WebApp;

        return new ClientBlueprint(
            name: trim($this->string('name')->toString()),
            type: $kind === AppKind::Advanced
                ? ($this->enum('client_type', ClientType::class) ?? ClientType::Confidential)
                : $kind->clientType(),
            grantTypes: $kind === AppKind::Advanced ? $this->strings('grant_types') : $kind->grantTypes(),
            redirectUris: $this->strings('redirect_uris'),
            postLogoutRedirectUris: $this->strings('post_logout_redirect_uris'),
            scopes: $this->has('scopes') ? $this->strings('scopes') : $kind->defaultScopes(),
            firstParty: $this->boolean('first_party'),
        );
    }

    /**
     * @return list<string>
     */
    private function strings(string $key): array
    {
        return array_values(array_filter(
            (array) $this->input($key, []),
            static fn (mixed $value): bool => is_string($value) && trim($value) !== '',
        ));
    }
}
