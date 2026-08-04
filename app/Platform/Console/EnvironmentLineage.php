<?php

declare(strict_types=1);

namespace App\Platform\Console;

/**
 * WHOSE an environment is.
 *
 * The hierarchy on this platform is account → project → environment, and the operator
 * plane flattened it: six planes named `production`, `staging`, `demo-co`, `acme`,
 * `acme-staging` and `billing-portal`, listed side by side with nothing saying that the
 * last one is Acme's. "Production" is not a name — half the customers on an install will
 * have one. "Acme / Production" is.
 *
 * Two environments legitimately have no account, and they are not the same thing:
 *
 *  - the PLATFORM ROOT is the environment the deployment itself lives in, where the
 *    platform's own operators and every account member exist as subjects. It belongs to
 *    no customer by construction, and rendering it as a blank cell reads like a bug in
 *    the join rather than the one row that is supposed to look like that;
 *  - an UNATTACHED environment has no project and therefore no account. It is a
 *    leftover — created before an account owned it, or orphaned by a deleted project —
 *    and an operator should be able to see that from the list rather than infer it.
 *
 * Naming both is the whole point: a blank cell says "we did not look", and these two
 * rows are the reason an operator distrusts the column.
 */
final readonly class EnvironmentLineage
{
    public function __construct(
        public string $environmentId,
        public ?string $accountId = null,
        public ?string $accountName = null,
        public ?string $projectId = null,
        public ?string $projectName = null,
        public bool $isPlatformRoot = false,
    ) {}

    /** Whether a customer owns this environment. */
    public function belongsToAccount(): bool
    {
        return $this->accountId !== null && $this->accountName !== null && $this->accountName !== '';
    }

    /** Neither a customer's nor the platform's own — a leftover worth seeing. */
    public function isUnattached(): bool
    {
        return ! $this->belongsToAccount() && ! $this->isPlatformRoot;
    }

    /**
     * The owner, as the words an operator reads: the account's name, or what the
     * environment is instead of a customer's.
     */
    public function owner(): string
    {
        $accountName = $this->accountName;

        if ($this->accountId !== null && $accountName !== null && $accountName !== '') {
            return $accountName;
        }

        return $this->isPlatformRoot ? 'Platform root' : 'Unattached';
    }

    /**
     * "Acme / Production" — the form every list and the target switcher render, so an
     * environment never appears anywhere under a name that could be anybody's.
     */
    public function qualify(string $environmentName): string
    {
        return $this->owner().' / '.$environmentName;
    }

    /** One sentence explaining an environment that no account owns, or null. */
    public function note(): ?string
    {
        if ($this->isPlatformRoot) {
            return 'The environment this deployment itself runs in — operators and account members live here. It belongs to no customer.';
        }

        if ($this->isUnattached()) {
            return 'No project, so no account owns it. Nothing is served to a customer from here.';
        }

        return null;
    }
}
