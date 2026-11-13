<?php

declare(strict_types=1);

namespace App\Platform\Lifecycle;

use App\Platform\OAuth\OrganizationStanding;
use Cbox\Id\Directory\Contracts\Directories;
use Cbox\Id\Directory\Enums\DirectoryProvider;
use Cbox\Id\Directory\Models\Directory;
use Cbox\Id\Directory\ValueObjects\RegisteredDirectory;

/**
 * A closed organization's SCIM directory stops authenticating.
 *
 * A SCIM bearer token speaks for its organization's identity provider, and suspending or
 * archiving the organization paused nothing: the provider kept creating environment
 * subjects, disabling and reactivating them, and changing memberships of the organization
 * that was supposed to be closed. A directory of a closed organization now authenticates
 * as no directory, so `/scim/v2` answers its ordinary 401 with its challenge.
 *
 * Asked on every request, so reactivating the organization lets the same token in again
 * with nothing to restore.
 */
final readonly class StandingDirectories implements Directories
{
    public function __construct(private Directories $inner) {}

    public function register(string $organizationId, string $name): RegisteredDirectory
    {
        return $this->inner->register($organizationId, $name);
    }

    public function registerPull(string $organizationId, string $name, DirectoryProvider $provider, array $credentials): Directory
    {
        return $this->inner->registerPull($organizationId, $name, $provider, $credentials);
    }

    public function authenticate(string $token): ?Directory
    {
        $directory = $this->inner->authenticate($token);

        return $directory === null || OrganizationStanding::isClosed($directory->organization_id) ? null : $directory;
    }
}
