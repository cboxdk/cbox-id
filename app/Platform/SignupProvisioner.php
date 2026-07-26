<?php

declare(strict_types=1);

namespace App\Platform;

use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\EnvironmentType;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Organization\Models\Organization;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Cbox\Id\Platform\AccountProvisioner;
use Cbox\Id\Platform\Contracts\AccountMembers;
use Cbox\Id\Platform\Contracts\Accounts;
use Cbox\Id\Platform\Contracts\Projects;
use Cbox\Id\Platform\Exceptions\EnvironmentLimitReached;
use Cbox\Id\Platform\Models\Account;
use Cbox\Id\Platform\Models\AccountMember;
use Cbox\Id\Platform\PlatformRoot;
use Cbox\Id\Platform\ValueObjects\AccountBlueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Self-serve signup provisioning, split in two around email verification.
 *
 * WHY THE SPLIT. {@see AccountProvisioner::provision()} stands up the account, its
 * home organization, the owner and a live environment in one go. That is right for an
 * operator or an API caller — someone already vouched for — but on the open signup
 * form it means an unverified stranger mints a routable IdP with a signing key. In
 * production five of eight accounts were exactly that: bot signups, each with its own
 * empty environment, none of which ever verified an address. Deferring the environment
 * doesn't make the abuse harder, it makes it WORTHLESS: what the bot walks away with
 * is a row nobody routes to.
 *
 * The account, its home organization and the owner member are still created up front,
 * because they are what the verification email is addressed to — the token binds to the
 * owner's platform-root SUBJECT, which does not exist until the member does — and
 * because they cost nothing: no key material, no subdomain, no discovery document.
 *
 * The environment is released by {@see releaseEnvironment()} through the package's own
 * {@see AccountProvisioner::addEnvironment()}, so the first environment is created by
 * exactly the code path that has always created it (same slug seeding off the project
 * name, same plan-limit and suspension checks, same warmed signing key).
 */
final class SignupProvisioner
{
    public function __construct(
        private readonly Accounts $accounts,
        private readonly AccountMembers $members,
        private readonly Projects $projects,
        private readonly Organizations $organizations,
        private readonly PlatformRoot $platformRoot,
        private readonly EnvironmentContext $context,
        private readonly AccountProvisioner $provisioner,
    ) {}

    /**
     * Everything a new account needs EXCEPT its environment, in one transaction — so a
     * failure leaves no half-born account, exactly as the package's own provisioner
     * guarantees.
     */
    public function provisionPending(AccountBlueprint $blueprint): PendingAccount
    {
        return DB::transaction(function () use ($blueprint): PendingAccount {
            $account = $this->accounts->create($blueprint->accountName, $blueprint->environmentLimit);

            // Mirrors AccountProvisioner::homeAccount(), which is private to the package.
            // It has to run BEFORE the member is created: creating a member attaches
            // their platform-root subject and files them in the account's organization,
            // and it reads that organization off the account row.
            $this->homeAccount($account);

            $member = $this->members->create(
                $account->id,
                $blueprint->ownerEmail,
                $blueprint->ownerPassword,
                $blueprint->ownerName,
            );

            $project = $this->projects->create($account->id, $blueprint->accountName, $blueprint->environmentLimit);

            return new PendingAccount($account, $member, $project);
        });
    }

    /**
     * Stand up the account's first environment — the step that was deferred until the
     * owner's email was verified.
     *
     * Idempotent and self-limiting: an account that already owns an environment gets
     * nothing (so a replayed verification link, or a second address verified on the same
     * account, can never mint a second free environment), and a suspended account or
     * project gets nothing rather than an exception on a link click.
     */
    public function releaseEnvironment(AccountMember $member): ?Environment
    {
        $account = Account::query()->whereKey($member->account_id)->first();

        if ($account === null || Environment::query()->where('account_id', $account->id)->exists()) {
            return null;
        }

        $project = $this->projects->forAccount($account->id)->first();

        // A suspended account or project is asked here rather than caught below: an
        // operator who suspended a signup while it was still unverified must not have
        // that decision undone by a link click, and a 500 on a link click is nobody's
        // idea of a refusal.
        if ($project === null || ! $account->isActive() || ! $project->isActive()) {
            return null;
        }

        try {
            return $this->provisioner->addEnvironment($project, 'Production', type: EnvironmentType::Production);
        } catch (EnvironmentLimitReached $e) {
            Log::warning('deferred environment not released', [
                'account_id' => $account->id,
                'reason' => $e::class,
            ]);

            return null;
        }
    }

    /**
     * Give the account an organization in the platform-root environment and link it —
     * the home the owner's subject and membership are written into.
     *
     * A deployment with no platform root yet (first install, before `cbox-id:install`)
     * leaves the account unhomed rather than inventing an environment, matching the
     * package's behaviour exactly.
     */
    private function homeAccount(Account $account): void
    {
        $platformRoot = $this->platformRoot->model();

        if ($platformRoot === null) {
            return;
        }

        $organization = $this->context->runAs($platformRoot, fn (): Organization => $this->organizations->create(
            new NewOrganization(
                name: $account->name,
                slug: $this->uniqueOrganizationSlug($account->name),
            ),
        ));

        $account->forceFill(['organization_id' => $organization->id])->save();
    }

    private function uniqueOrganizationSlug(string $name): string
    {
        $base = Str::slug($name);
        $base = $base !== '' ? $base : 'account-'.Str::lower(Str::random(6));
        $slug = $base;
        $suffix = 1;

        while (Organization::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.(++$suffix);
        }

        return $slug;
    }
}
