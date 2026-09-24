<?php

declare(strict_types=1);

namespace App\Platform\ApiKeys;

use App\Platform\ApiKeys\ValueObjects\KeyApp;
use App\Platform\ApiKeys\ValueObjects\KeyPermission;
use Cbox\Id\AccessControl\Contracts\AccessChecker;
use Cbox\Id\AccessControl\Models\Permission;
use Cbox\Id\OAuthServer\Models\Client;
use Illuminate\Database\Eloquent\Builder;

/**
 * Which apps a person may create API keys for, and which of an app's permissions a key
 * of theirs may carry.
 *
 * AN APP OPTS IN BY DECLARING A PREFIX, and an organization may only use an app that is
 * its own or registered for the whole environment. The framework's `issue()` checks the
 * first and not the second — who may mint a key for which app is the console's policy —
 * so the second is a WHERE clause here: an app owned by another organization is not found,
 * rather than found and refused afterwards.
 */
final readonly class KeyApps
{
    public function __construct(private AccessChecker $access) {}

    /**
     * Every app this organization's people can create keys for, by name.
     *
     * @return list<KeyApp>
     */
    public function offeredTo(string $organizationId): array
    {
        return array_values($this->query($organizationId)
            ->orderBy('name')
            ->get()
            ->map(static fn (Client $client): KeyApp => KeyApp::from($client))
            ->all());
    }

    /** One app, if this organization's people may create keys for it. */
    public function offered(string $organizationId, string $clientId): ?KeyApp
    {
        $client = $this->query($organizationId)->where('client_id', $clientId)->first();

        return $client === null ? null : KeyApp::from($client);
    }

    /**
     * What a key for `$clientId` may carry, for this person, right now: the permissions an
     * access token for that app would carry — the framework's own issuance cap — with the
     * descriptions the app's manifest gave them.
     *
     * A description declared by the app itself wins over a shared row of the same name,
     * because it is the app that decides what its permission means.
     *
     * @return list<KeyPermission>
     */
    public function permissionsHeld(string $userId, string $organizationId, string $clientId): array
    {
        $held = $this->access->forToken($userId, $organizationId, $clientId)->permissions;

        if ($held === []) {
            return [];
        }

        $described = [];

        $rows = Permission::query()
            ->visibleToOrganization($organizationId)
            ->whereIn('name', $held)
            ->where(fn (Builder $query) => $query->whereNull('client_id')->orWhere('client_id', $clientId))
            // Shared rows first, so the app's own row overwrites them below.
            ->orderByRaw('CASE WHEN client_id IS NULL THEN 0 ELSE 1 END')
            ->get(['name', 'description', 'client_id']);

        foreach ($rows as $row) {
            if (is_string($row->description) && trim($row->description) !== '') {
                $described[$row->name] = $row->description;
            }
        }

        $permissions = array_values(array_unique($held));
        sort($permissions);

        return array_map(
            static fn (string $name): KeyPermission => new KeyPermission($name, $described[$name] ?? null),
            $permissions,
        );
    }

    /**
     * App names for a key list, keyed by client id. Any app in the environment: a key keeps
     * its app's name after the app stops offering keys.
     *
     * @param  list<string>  $clientIds
     * @return array<string, string>
     */
    public function names(array $clientIds): array
    {
        if ($clientIds === []) {
            return [];
        }

        /** @var array<string, string> $names */
        $names = Client::query()
            ->whereIn('client_id', array_values(array_unique($clientIds)))
            ->pluck('name', 'client_id')
            ->all();

        return $names;
    }

    /**
     * @return Builder<Client>
     */
    private function query(string $organizationId): Builder
    {
        return Client::query()
            ->whereNotNull('api_key_prefix')
            ->where(fn (Builder $query) => $query
                ->whereNull('organization_id')
                ->orWhere('organization_id', $organizationId));
    }
}
