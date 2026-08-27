<?php

declare(strict_types=1);

namespace App\Http\Requests\Console;

use App\Platform\AppKind;
use App\Platform\ScopeCatalog;
use App\Rules\SecureUriLines;
use Cbox\Id\OAuthServer\Enums\ClientType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A new OAuth client — an app that signs people in, a machine credential that calls the
 * API, or both.
 *
 * THE KIND IS THE ONE QUESTION. Everything the specification calls a decision — client
 * type, grants, which scopes to register for — follows from it, and only `Advanced` lets
 * any of that be answered by hand: somebody who has told us they are building a CLI has
 * already told us it is public.
 */
final class StoreClientRequest extends FormRequest
{
    /** WHOSE app this is comes from the scope and the plane, never from the payload. */
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
            'name' => ['required', 'string', 'max:190'],
            'kind' => ['required', Rule::enum(AppKind::class)],
            'type' => ['required', 'in:confidential,public'],
            'grantAuthorizationCode' => ['boolean'],
            'grantClientCredentials' => ['boolean'],
            'scopes' => ['array'],
            'scopes.*' => ['string'],
            'customScopes' => ['nullable', 'string', 'max:500'],
            /*
             * ONE FIELD, MANY URIS. Both textareas are newline-separated lists, and every
             * line has to clear {@see SecureRedirectUri} — https anywhere, http only on
             * loopback, no fragment. Checked as a validation rule rather than after the
             * fact, so the refusal lands on the field that caused it and the form survives
             * to be corrected.
             */
            'redirectUris' => ['nullable', 'string', 'max:2000', new SecureUriLines(
                'Each redirect URI must use https (http is allowed only on localhost) — e.g. https://app.example.com/callback.',
            )],
            'postLogoutRedirectUris' => ['nullable', 'string', 'max:2000', new SecureUriLines(
                'Each sign-out URI must use https (http is allowed only on localhost) — e.g. https://app.example.com/signed-out.',
            )],
            'manifestUrl' => ['nullable', 'url', 'max:500'],
            'firstParty' => ['boolean'],
            'environmentWide' => ['boolean'],
        ];
    }

    /**
     * The redirect URIs. Already held to {@see SecureUriLines} by `rules()`.
     *
     * @return list<string>
     */
    public function redirectUris(): array
    {
        return SecureUriLines::split((string) $this->string('redirectUris'));
    }

    /**
     * Where sign-out may send people back to — held to the SAME bar as the sign-in
     * redirects, because sign-out hands the browser to this address too and a cleartext
     * public URL is a cleartext public URL either way.
     *
     * @return list<string>
     */
    public function postLogoutRedirectUris(): array
    {
        return SecureUriLines::split((string) $this->string('postLogoutRedirectUris'));
    }

    public function name(): string
    {
        return trim((string) $this->string('name'));
    }

    public function kind(): AppKind
    {
        return AppKind::from((string) $this->string('kind'));
    }

    public function clientType(): ClientType
    {
        return $this->string('type')->toString() === 'public'
            ? ClientType::Public
            : ClientType::Confidential;
    }

    /**
     * The grants an ADVANCED registration asks for. Every other kind states its own.
     *
     * @return list<string>
     */
    public function grantTypes(): array
    {
        $grants = [];

        if ($this->boolean('grantAuthorizationCode')) {
            $grants[] = 'authorization_code';
            $grants[] = 'refresh_token';
        }

        if ($this->boolean('grantClientCredentials')) {
            $grants[] = 'client_credentials';
        }

        return $grants;
    }

    /**
     * Catalogue scopes from the picker, plus any advanced custom keys.
     *
     * Intersected with the catalogue rather than trusted: the picker is a list of
     * checkboxes and the payload is a list of strings, and only one of those is bounded.
     *
     * @return list<string>
     */
    public function scopes(ScopeCatalog $catalog): array
    {
        /** @var list<string> $chosen */
        $chosen = array_values(array_filter((array) $this->input('scopes', []), 'is_string'));

        $custom = array_values(array_filter(
            array_map('trim', explode(',', (string) $this->string('customScopes'))),
            static fn (string $scope): bool => $scope !== '',
        ));

        return array_values(array_unique(array_merge(
            array_values(array_intersect($chosen, $catalog->keys())),
            $custom,
        )));
    }

    public function manifestUrl(): ?string
    {
        return trim((string) $this->string('manifestUrl')) ?: null;
    }

    public function firstParty(): bool
    {
        return $this->boolean('firstParty');
    }

    public function environmentWide(): bool
    {
        return $this->boolean('environmentWide');
    }
}
