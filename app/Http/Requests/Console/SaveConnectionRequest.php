<?php

declare(strict_types=1);

namespace App\Http\Requests\Console;

use Illuminate\Foundation\Http\FormRequest;

/**
 * An edit to a connection that already exists.
 *
 * SECRETS ARE WRITE-ONCE. A sealed certificate, signing key or client secret is never
 * returned to the browser, so the field arrives empty on every edit — and an empty field
 * means "keep what is stored", not "clear it". {@see self::secretOr()} is that rule, in
 * one place, so a caller cannot express it differently by accident.
 *
 * The TYPE is not editable: it decides which fields exist and what the credentials mean,
 * and changing it under a live integration is not an edit, it is a different connection.
 */
final class SaveConnectionRequest extends FormRequest
{
    /** WHICH connection is in the URL, and the controller re-resolves it against the scope. */
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

            // Present for a SAML connection, absent for an OIDC one — validated when they
            // arrive, because which set arrives is decided by the connection's own type
            // and the controller is what knows it.
            'idp_entity_id' => ['nullable', 'string', 'max:500'],
            'idp_sso_url' => ['nullable', 'url', 'max:500'],
            'idp_x509cert' => ['nullable', 'string'],
            'sp_entity_id' => ['nullable', 'string', 'max:500'],
            'sp_acs_url' => ['nullable', 'url', 'max:500'],

            'issuer' => ['nullable', 'url', 'max:500'],
            'client_id' => ['nullable', 'string', 'max:500'],
            'client_secret' => ['nullable', 'string', 'max:500'],
            'signing_key' => ['nullable', 'string'],
        ];
    }

    public function name(): string
    {
        return trim((string) $this->string('name'));
    }

    /** The submitted secret, or the stored one when the field was left blank. */
    public function secretOr(string $field, string $stored): string
    {
        $submitted = trim((string) $this->string($field));

        return $submitted !== '' ? $submitted : $stored;
    }
}
