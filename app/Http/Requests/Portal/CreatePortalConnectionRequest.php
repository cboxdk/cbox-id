<?php

declare(strict_types=1);

namespace App\Http\Requests\Portal;

use Cbox\Id\Federation\Enums\ConnectionType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * An SSO connection, described by whoever runs the customer's identity provider.
 *
 * THE FIELDS DEPEND ON THE PROTOCOL, and the protocol is validated against the enum rather
 * than a hand-written pair — a new `ConnectionType` cannot be silently unofferable, and an
 * unknown one cannot reach `ConnectionType::from()`, which would be a 500 on a form field.
 *
 * The OIDC half asks for four things and DISCOVERS the rest: an issuer's endpoints come
 * from its own `.well-known` document, so asking an IT admin to copy them by hand is asking
 * them to make a mistake that surfaces days later as a failed sign-in.
 */
final class CreatePortalConnectionRequest extends FormRequest
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
        $rules = [
            'type' => ['required', Rule::enum(ConnectionType::class)],
            'connName' => ['required', 'string', 'max:120'],
        ];

        return [...$rules, ...match ($this->type()) {
            ConnectionType::Saml => [
                'idp_entity_id' => ['required', 'string', 'max:500'],
                'idp_sso_url' => ['required', 'url', 'max:500'],
                'idp_x509cert' => ['required', 'string'],
                'sp_entity_id' => ['required', 'string', 'max:500'],
                'sp_acs_url' => ['required', 'url', 'max:500'],
            ],
            default => [
                'issuer' => ['required', 'url', 'max:500'],
                'client_id' => ['required', 'string', 'max:500'],
                'client_secret' => ['required', 'string', 'max:500'],
                'signing_key' => ['required', 'string'],
            ],
        }];
    }

    public function type(): ConnectionType
    {
        return ConnectionType::tryFrom((string) $this->string('type')) ?? ConnectionType::Saml;
    }

    public function name(): string
    {
        return trim((string) $this->string('connName'));
    }

    /**
     * The protocol's own fields, and only those — never the whole request body.
     *
     * @return array<string, mixed>
     */
    public function config(): array
    {
        $fields = $this->type() === ConnectionType::Saml
            ? ['idp_entity_id', 'idp_sso_url', 'idp_x509cert', 'sp_entity_id', 'sp_acs_url']
            : ['issuer', 'client_id', 'client_secret', 'signing_key'];

        $config = [];

        foreach ($fields as $field) {
            $config[$field] = (string) $this->string($field);
        }

        return $config;
    }

    public function issuer(): string
    {
        return (string) $this->string('issuer');
    }
}
