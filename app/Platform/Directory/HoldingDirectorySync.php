<?php

declare(strict_types=1);

namespace App\Platform\Directory;

use Cbox\Id\Directory\Contracts\DirectorySync;
use Cbox\Id\Directory\Models\DirectoryUser;
use Cbox\Id\Directory\ValueObjects\ScimUser;

/**
 * A directory push may reactivate only an account the directory itself deactivated: one
 * it had on its books as inactive. See {@see DirectoryReactivationHold}.
 */
final readonly class HoldingDirectorySync implements DirectorySync
{
    public function __construct(private DirectorySync $inner, private DirectoryReactivationHold $hold) {}

    public function provisionUser(string $directoryId, ScimUser $user): DirectoryUser
    {
        // What THIS directory last said about the person, before the push overwrites it.
        $previous = DirectoryUser::query()
            ->where('directory_id', $directoryId)
            ->where('external_id', $user->externalId)
            ->first(['id', 'active']);

        return $this->hold->during(
            $previous !== null && ! $previous->active,
            fn (): DirectoryUser => $this->inner->provisionUser($directoryId, $user),
        );
    }

    public function deprovisionUser(string $directoryId, string $externalId): void
    {
        $this->inner->deprovisionUser($directoryId, $externalId);
    }
}
