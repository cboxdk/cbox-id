<?php

declare(strict_types=1);

namespace App\Platform\Billing;

/**
 * Whether a project still carries a plan somebody is being charged for.
 *
 * A project is its own billing anchor: the billing module keeps a subscription per project,
 * in Cbox Billing, keyed by the project's id. Deleting the project does not end that
 * subscription, so the deletion asks here first and refuses while one is running.
 *
 * The host's answer is {@see NoProjectSubscriptions}: a deployment that does not bill has
 * nothing to leave behind. The billing module binds its own.
 */
interface ProjectSubscriptions
{
    /**
     * True while the project has a subscription that will renew.
     *
     * @throws \Throwable when Billing cannot be asked; the caller refuses rather than guesses
     */
    public function renews(string $organizationId, string $projectId): bool;
}
