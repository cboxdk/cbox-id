<?php

declare(strict_types=1);

namespace App\Platform\ServiceAccess;

use App\Platform\Actions\ActionRefused;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipStatus;
use Cbox\Id\Organization\Models\Membership;

final readonly class WaitlistTeams
{
    public function __construct(private Memberships $memberships, private Organizations $organizations) {}

    public function assertMember(string $subject, string $organization): void
    {
        $team = $this->organizations->find($organization);
        if ($team === null || $team->status->revokesAccess() || $this->memberships->activeRole($organization, $subject) === null) {
            throw ActionRefused::notFound('organization');
        }
    }

    /** @return list<array{id: string, name: string}> */
    public function choices(string $subject): array
    {
        $memberships = $this->memberships->forUser($subject)->where('status', MembershipStatus::Active);
        $organizations = $this->organizations->findMany($memberships->map(static fn (Membership $membership): string => $membership->organization_id)->all());
        $choices = [];
        foreach ($organizations as $organization) {
            if (! $organization->status->revokesAccess()) {
                $choices[] = ['id' => $organization->id, 'name' => $organization->name];
            }
        }

        return $choices;
    }
}
