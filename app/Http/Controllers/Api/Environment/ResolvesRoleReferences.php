<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Environment;

use Cbox\Id\AccessControl\Models\Role;

/**
 * A role named on the management API: by its id, or — because an app knows its own
 * manifest by KEY, not by the ids this server minted for it — by `key` together with the
 * `client_id` of the app that declared it. A key is only unique within one app, so without
 * a client id only an id resolves.
 *
 * Orphaned roles (dropped from their app's latest manifest) never resolve: a role nobody can
 * see in a console is exactly the one that must not be granted by name.
 */
trait ResolvesRoleReferences
{
    private function role(string $reference, ?string $clientId = null): ?Role
    {
        return Role::query()
            ->whereNull('orphaned_at')
            ->where(function ($query) use ($reference, $clientId): void {
                $query->whereKey($reference);

                if ($clientId !== null && $clientId !== '') {
                    $query->orWhere(fn ($byKey) => $byKey->where('client_id', $clientId)->where('key', $reference));
                }
            })
            ->first();
    }
}
