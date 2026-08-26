<?php

declare(strict_types=1);

namespace App\Http\Requests\Console;

use Cbox\Id\Provisioning\Enums\AuthScheme;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A new outbound SCIM target.
 *
 * THE SCHEME DECIDES WHICH OTHER FIELDS ARE REQUIRED, and they are required THERE rather
 * than checked afterwards: a connection saved without a token URL is one that can never
 * complete a single push, and it would sit in the list looking configured.
 */
final class RegisterOutboundSyncRequest extends FormRequest
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
        $usesClientCredentials = $this->scheme() === AuthScheme::OAuth2ClientCredentials;

        return [
            'name' => ['required', 'string', 'max:190'],
            // `url`, because this platform calls the address itself with your people's
            // data on it.
            'baseUrl' => ['required', 'url', 'max:500'],
            'scheme' => ['required', Rule::enum(AuthScheme::class)],
            'secret' => ['required', 'string', 'max:4096'],
            'environmentWide' => ['boolean'],

            'tokenUrl' => [...($usesClientCredentials ? ['required'] : ['nullable']), 'string', 'max:2048', 'url'],
            'clientId' => [...($usesClientCredentials ? ['required'] : ['nullable']), 'string', 'max:400'],
            'scope' => ['nullable', 'string', 'max:400'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'tokenUrl' => 'token URL',
            'clientId' => 'client ID',
            'baseUrl' => 'SCIM base URL',
        ];
    }

    public function name(): string
    {
        return trim((string) $this->string('name'));
    }

    public function baseUrl(): string
    {
        return trim((string) $this->string('baseUrl'));
    }

    public function scheme(): AuthScheme
    {
        return AuthScheme::tryFrom((string) $this->string('scheme')) ?? AuthScheme::Bearer;
    }

    /** NOT trimmed: leading or trailing whitespace can be part of a credential. */
    public function secret(): string
    {
        return (string) $this->string('secret');
    }

    public function environmentWide(): bool
    {
        return $this->boolean('environmentWide');
    }

    /**
     * The scheme's extras, and ONLY for the scheme that has them.
     *
     * A bearer connection carrying a stale token URL from a scheme the form was switched
     * away from is a configuration that describes something nobody asked for.
     *
     * @return array<string, string>
     */
    public function authConfig(): array
    {
        if ($this->scheme() !== AuthScheme::OAuth2ClientCredentials) {
            return [];
        }

        return array_filter([
            'token_url' => trim((string) $this->string('tokenUrl')),
            'client_id' => trim((string) $this->string('clientId')),
            'scope' => trim((string) $this->string('scope')),
        ], static fn (string $value): bool => $value !== '');
    }
}
