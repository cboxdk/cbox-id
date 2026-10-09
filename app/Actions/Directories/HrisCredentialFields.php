<?php

declare(strict_types=1);

namespace App\Actions\Directories;

use App\Platform\Actions\ActionRefused;
use App\Platform\Actions\Input\Field;
use Cbox\Id\Directory\DirectoryConnectors;
use Cbox\Id\Directory\Enums\DirectoryProvider;
use Cbox\Id\Directory\Exceptions\DirectoryConnectionFailed;
use Cbox\Id\Directory\Exceptions\IncompleteHrisCredentials;
use Cbox\Id\Directory\Hris\Contracts\HrisProvider;
use Cbox\Id\Directory\Hris\HrisCatalog;

/**
 * The credentials of an HR system, as the actions take and check them. A helper, not an
 * action.
 *
 * THE FIELDS COME FROM THE FRAMEWORK'S CATALOGUE, not from a list kept here: the catalogue
 * is checked against the connectors (a key it declares is one the connector reads), so the
 * API, the MCP tool and both setup screens collect exactly what the sync will use — and a
 * sixth HR system arrives with its fields.
 *
 * Checked in two steps, both before anything is stored: the SHAPE (`credentialsFrom`:
 * required keys present, a BambooHR address reduced to its subdomain, a Workday report URL
 * pinned to Workday's own hosts), then the PROVIDER (`probe`: one cheap request, refused
 * with the provider's reason). The reason is the connector's own sentence — a status code
 * and what was being fetched, never a credential.
 */
final class HrisCredentialFields
{
    /**
     * The `credentials` object: every key any HR system reads, each write-only.
     */
    public static function field(): Field
    {
        $properties = [];

        foreach (HrisCatalog::credentialKeys() as $key) {
            $properties[] = Field::string($key)->max(20000)->describe(self::describe($key));
        }

        return Field::object('credentials', $properties)
            ->describe('The HR system\'s credentials, in the keys its setup guide names. Write-only: sealed, never returned.');
    }

    /**
     * The HR system's credentials, shaped and verified against it, or the refusal.
     *
     * @param  array<mixed>  $given
     * @return array<string, string>
     *
     * @throws ActionRefused
     */
    public static function verified(DirectoryConnectors $connectors, DirectoryProvider $provider, array $given): array
    {
        try {
            $credentials = HrisCatalog::credentialsFrom($provider, $given);
        } catch (IncompleteHrisCredentials $e) {
            throw ActionRefused::because('incomplete_credentials', $e->getMessage(), 'credentials');
        }

        $connector = $connectors->has($provider) ? $connectors->for($provider) : null;

        if (! $connector instanceof HrisProvider) {
            throw ActionRefused::because('unknown_provider', 'Choose an HR system.', 'provider');
        }

        try {
            $connector->probe($credentials);
        } catch (DirectoryConnectionFailed $e) {
            throw ActionRefused::because('credentials_rejected', $e->getMessage(), 'credentials');
        }

        return $credentials;
    }

    /** Which HR systems use a key, and what it is — for the schema's description. */
    private static function describe(string $key): string
    {
        $uses = [];

        foreach (HrisCatalog::all() as $setup) {
            foreach ($setup->credentials as $credential) {
                if ($credential->key === $key) {
                    $uses[] = $setup->name.': '.$credential->label.($credential->secret ? ' (secret)' : '');
                }
            }
        }

        return implode('; ', $uses).'.';
    }
}
