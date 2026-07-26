<?php

declare(strict_types=1);

namespace App\Platform;

use Cbox\Id\Platform\Models\Account;
use Cbox\Id\Platform\Models\AccountMember;
use Cbox\Id\Platform\Models\Project;

/**
 * A self-serve account that exists but is not yet an IdP: the workspace, its owner
 * and their first project, with NO environment behind it. The environment — the
 * expensive, routable, key-bearing part — is stood up by
 * {@see SignupProvisioner::releaseEnvironment()} once the owner proves the email.
 *
 * Deliberately NOT a `ProvisionedAccount`: that value object promises an
 * `Environment`, and this state is precisely the absence of one.
 */
final readonly class PendingAccount
{
    public function __construct(
        public Account $account,
        public AccountMember $member,
        public Project $project,
    ) {}
}
