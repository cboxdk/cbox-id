<?php

declare(strict_types=1);

namespace App\Http\Requests\Console;

use Cbox\Id\Directory\Enums\DirectoryProvider;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The credentials for an API-pull directory — Google Workspace or Microsoft Entra.
 *
 * SHAPED HERE RATHER THAN IN THE CONNECTOR, because these are the fields an administrator
 * typed; the connector receives the map its API needs and nothing about this form. The two
 * providers ask for different things, and Google's is a pasted JSON key rather than three
 * boxes — so the refusal has to be able to say which part of it was missing.
 */
final class ConnectDirectoryRequest extends FormRequest
{
    /** WHOSE directory this is comes from the scope, never from the payload. */
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
            'provider' => ['required', Rule::enum(DirectoryProvider::class)],

            // Google Workspace.
            'googleServiceAccountJson' => ['nullable', 'string'],
            'googleAdminEmail' => ['nullable', 'string', 'max:190'],

            // Microsoft Entra.
            'entraTenantId' => ['nullable', 'string', 'max:190'],
            'entraClientId' => ['nullable', 'string', 'max:190'],
            'entraClientSecret' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function provider(): DirectoryProvider
    {
        return DirectoryProvider::from((string) $this->string('provider'));
    }

    /**
     * The provider-specific credential map, or null when the form is incomplete.
     *
     * @return array<string, mixed>|null
     */
    public function credentials(DirectoryProvider $provider): ?array
    {
        if ($provider === DirectoryProvider::GoogleWorkspace) {
            $serviceAccount = json_decode((string) $this->string('googleServiceAccountJson'), true);

            if (! is_array($serviceAccount)
                || ! is_string($serviceAccount['client_email'] ?? null)
                || ! is_string($serviceAccount['private_key'] ?? null)
                || trim((string) $this->string('googleAdminEmail')) === '') {
                return null;
            }

            return [
                'client_email' => $serviceAccount['client_email'],
                'private_key' => $serviceAccount['private_key'],
                'admin_email' => trim((string) $this->string('googleAdminEmail')),
            ];
        }

        foreach (['entraTenantId', 'entraClientId', 'entraClientSecret'] as $field) {
            if (trim((string) $this->string($field)) === '') {
                return null;
            }
        }

        return [
            'tenant_id' => trim((string) $this->string('entraTenantId')),
            'client_id' => trim((string) $this->string('entraClientId')),
            'client_secret' => trim((string) $this->string('entraClientSecret')),
        ];
    }

    /**
     * WHY the credentials were not accepted, in the administrator's words.
     *
     * A single "check your credentials" would be true and useless: the Google half fails
     * because a pasted JSON key is missing two specific properties, and that is a sentence
     * somebody can act on.
     */
    public function credentialProblem(DirectoryProvider $provider): string
    {
        if ($provider !== DirectoryProvider::GoogleWorkspace) {
            return 'Enter the Entra tenant ID, client ID, and client secret.';
        }

        return trim((string) $this->string('googleAdminEmail')) === ''
            ? 'Enter the admin email to impersonate.'
            : 'Paste the full service-account JSON key (it must contain client_email and private_key).';
    }
}
