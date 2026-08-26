<?php

declare(strict_types=1);

namespace App\Http\Requests\Console;

use Cbox\Id\Federation\Enums\ConnectionType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A new federated identity provider — SAML or OIDC.
 *
 * WHICH FIELDS ARE REQUIRED DEPENDS ON THE TYPE, and the two sets do not overlap: a SAML
 * connection needs an entity id, an SSO URL and a certificate; an OIDC one needs an issuer,
 * a client id, a secret and a signing key. `required_if` rather than two request classes,
 * so the form is one form and the refusal lands on the field the person is looking at.
 */
final class StoreConnectionRequest extends FormRequest
{
    /** WHOSE connection this is comes from the scope and the plane, never from the payload. */
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
            'type' => ['required', Rule::enum(ConnectionType::class)],
            'environmentWide' => ['boolean'],

            'idp_entity_id' => ['required_if:type,saml', 'nullable', 'string', 'max:500'],
            'idp_sso_url' => ['required_if:type,saml', 'nullable', 'url', 'max:500'],
            'idp_x509cert' => ['required_if:type,saml', 'nullable', 'string'],
            'sp_entity_id' => ['required_if:type,saml', 'nullable', 'string', 'max:500'],
            'sp_acs_url' => ['required_if:type,saml', 'nullable', 'url', 'max:500'],

            'issuer' => ['required_if:type,oidc', 'nullable', 'url', 'max:500'],
            'client_id' => ['required_if:type,oidc', 'nullable', 'string', 'max:500'],
            'client_secret' => ['required_if:type,oidc', 'nullable', 'string', 'max:500'],
            'signing_key' => ['required_if:type,oidc', 'nullable', 'string'],
        ];
    }

    public function name(): string
    {
        return trim((string) $this->string('name'));
    }

    public function connectionType(): ConnectionType
    {
        return ConnectionType::from((string) $this->string('type'));
    }

    public function environmentWide(): bool
    {
        return $this->boolean('environmentWide');
    }

    /**
     * The provider config, as the type's own fields and nothing else.
     *
     * Built from a fixed list rather than from whatever was submitted: the config is
     * SEALED and handed back to the federation layer, so an extra key in it is a key
     * nothing validated travelling inside something nothing re-reads.
     *
     * @return array<string, string>
     */
    public function config(): array
    {
        $fields = $this->connectionType() === ConnectionType::Saml
            ? ['idp_entity_id', 'idp_sso_url', 'idp_x509cert', 'sp_entity_id', 'sp_acs_url']
            : ['issuer', 'client_id', 'client_secret', 'signing_key'];

        $config = [];

        foreach ($fields as $field) {
            $config[$field] = trim((string) $this->string($field));
        }

        return $config;
    }
}
