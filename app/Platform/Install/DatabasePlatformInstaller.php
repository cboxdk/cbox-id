<?php

declare(strict_types=1);

namespace App\Platform\Install;

use App\Platform\Install\Contracts\PlatformInstaller;
use App\Platform\Install\Enums\PlatformOccupant;
use App\Platform\Install\Enums\SchemaState;
use App\Platform\Install\Exceptions\PlatformNotEmpty;
use App\Providers\PlatformServiceProvider;
use Cbox\Id\Identity\Models\User;
use Cbox\Id\Kernel\Crypto\Contracts\KeyManager;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\OAuthServer\Models\Client;
use Cbox\Id\Organization\Enums\EnvironmentStatus;
use Cbox\Id\Organization\Enums\EnvironmentType;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Organization\Models\Organization;
use Cbox\Id\Platform\AccountProvisioner;
use Cbox\Id\Platform\Contracts\PlatformOperators;
use Cbox\Id\Platform\DatabaseAccountMembers;
use Cbox\Id\Platform\DatabasePlatformOperators;
use Cbox\Id\Platform\Models\Account;
use Cbox\Id\Platform\ValueObjects\AccountBlueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

/**
 * The one path from an empty database to a usable deployment.
 *
 * ORDER IS THE WHOLE DESIGN. The platform root is stamped FIRST, before any identity is
 * written, because {@see DatabasePlatformOperators::attachSubject()} needs somewhere to
 * put the operator's subject: with no root it silently leaves the operator unlinked and
 * falls back to the local bootstrap hash, which is the one credential path that skips
 * the password policy, lockout, MFA and passkeys. An installer is the only caller that
 * can guarantee the root exists first, so it does.
 *
 * The account owner and the operator are deliberately the SAME address: the human
 * installing a multi-tenant deployment owns both the staff console and the first
 * account, and {@see DatabaseAccountMembers::create()} reuses the
 * subject that address already has rather than minting a second identity for one person.
 */
final class DatabasePlatformInstaller implements PlatformInstaller
{
    /** @see isEmpty() — the per-request answer, not a cache. */
    private ?bool $emptyRecord = null;

    public function __construct(
        private readonly EnvironmentContext $context,
        private readonly PlatformOperators $operators,
        private readonly AccountProvisioner $accounts,
        private readonly KeyManager $keys,
    ) {}

    public function ready(): bool
    {
        return $this->schema() === SchemaState::Ready;
    }

    /**
     * Whether nobody has claimed this deployment yet.
     *
     * MEMOISED FOR THE REQUEST. {@see PointAtFirstRun} asks on every request, and it is
     * registered twice on purpose — once in the global `web` group and once in Livewire's
     * persistent list, because that list is where "this gate must survive a component
     * action" is stated rather than inferred from grouping ({@see PlatformServiceProvider}).
     * Both registrations are right, so the duplicate ask is answered here instead of by
     * removing one: a Livewire round trip was paying two `select exists(...)` for a fact
     * that cannot change between them.
     *
     * ONLY "CLAIMED" IS REMEMBERED, which is the whole safety of it. A claimed platform
     * cannot become unclaimed — nothing in the product un-installs a deployment — so that
     * answer is stable and it is the one every live request gets. "Empty" is the answer
     * that flips, and it flips against a gate: the first-run screen re-asks on every
     * action precisely because somebody else may have claimed the platform between the
     * render and the submit, and a remembered "yes, still empty" would walk that check
     * past a platform that now has an operator in it. So the empty answer is re-asked, on
     * the one deployment where nobody is waiting for the page.
     *
     * The binding is `scoped` as well, so even the remembered answer cannot outlive the
     * request, and {@see install()} drops it as it writes.
     */
    public function isEmpty(): bool
    {
        if ($this->emptyRecord === false) {
            return false;
        }

        return $this->emptyRecord = $this->resolveEmpty();
    }

    private function resolveEmpty(): bool
    {
        try {
            // ORDER DEPENDS ON WHETHER SOMEBODY ELSE'S TRANSACTION IS OPEN.
            //
            // The fast path is "try the cheap query; if the table is not there, fall back
            // to asking the schema", and it is written that way on purpose: this runs on
            // every web request, and `Schema::hasTable()` is an information-schema round
            // trip on every engine. A live deployment answers on the first query and never
            // pays for the fallback.
            //
            // Inside a transaction that reasoning inverts, because the FAILURE stops being
            // free. PostgreSQL aborts the whole transaction on a failed statement, so the
            // fallback — which is made of `Schema::hasTable()` — cannot run either, and the
            // class reports a reachable-but-un-migrated deployment as OCCUPIED: it 404s the
            // first-run screen, the only page that could have helped.
            //
            // A savepoint was the obvious answer and is the wrong one. MySQL commits
            // implicitly on DDL, so a test that drops a table to reach this state destroys
            // the savepoint before anything can roll back to it — "SAVEPOINT trans2 does
            // not exist", on the one engine PostgreSQL's problem does not exist. Asking the
            // schema first avoids the failing statement altogether, which no engine can
            // disagree about.
            if (DB::transactionLevel() > 0 && $this->schema() === SchemaState::Missing) {
                return true;
            }

            return ! $this->anyOccupant();
        } catch (Throwable) {
            // Only now is it worth asking the schema. This method runs on every web
            // request, and `Schema::hasTable()` is an information-schema round trip on
            // every engine — paying it up front would tax a live deployment forever to
            // describe a state that lasts until the first migration. A live deployment
            // never reaches here: it answers on the first query.
            return $this->schema() === SchemaState::Missing;
        }
    }

    public function occupancy(): PlatformOccupancy
    {
        try {
            // Ordered for the reason {@see resolveEmpty()} gives, and on the same terms.
            if (DB::transactionLevel() > 0 && $this->schema() === SchemaState::Missing) {
                return PlatformOccupancy::of();
            }

            return PlatformOccupancy::of(...array_filter(
                PlatformOccupant::cases(),
                fn (PlatformOccupant $occupant): bool => $this->present($occupant),
            ));
        } catch (Throwable) {
            // Reachable but un-migrated is EMPTY — nothing can have claimed a deployment
            // with no tables. Unreachable is not: an installer that cannot see what is
            // there must not report that nothing is.
            return $this->schema() === SchemaState::Missing
                ? PlatformOccupancy::of()
                : PlatformOccupancy::of(PlatformOccupant::Unreadable);
        }
    }

    public function install(InstallPlan $plan): InstalledPlatform
    {
        $occupancy = $this->occupancy();

        if (! $occupancy->isEmpty()) {
            throw PlatformNotEmpty::with($occupancy);
        }

        // This request is the one that stops the platform being empty, and the first-run
        // screen re-asks after it — through the same scoped instance. Dropped BEFORE the
        // write rather than after, so a failure part-way through cannot leave the memo
        // asserting an emptiness the transaction may already have contradicted.
        $this->emptyRecord = null;

        return DB::transaction(function () use ($plan): InstalledPlatform {
            $root = $this->platformRoot($plan);

            // Only now: the operator's subject lands in the root above, and the account
            // members provisioned below are homed in its organization.
            $operator = $this->operators->create(
                $plan->operator->email,
                $plan->operator->password,
                $plan->operator->name,
            );

            $account = null;
            $tenant = null;

            if ($plan->shape->isMultiTenant()) {
                // Through the package's own provisioner, so the first account is created
                // by exactly the code path that creates every later one — same home
                // organization, same project + plan allowance, same slug seeding, same
                // warmed signing key. A bespoke first account would be the one nobody
                // ever re-tests.
                $provisioned = $this->accounts->provision(new AccountBlueprint(
                    accountName: $plan->accountName,
                    ownerEmail: $plan->operator->email,
                    ownerName: $plan->operator->name,
                    ownerPassword: $plan->operator->password,
                    environmentName: $plan->environmentName,
                ));

                $account = $provisioned->account;
                $tenant = $provisioned->environment;
            }

            // The root issues tokens too — it is the account plane's own environment in
            // the SaaS shape, and the whole IdP in the single-tenant one — so its JWKS
            // must answer before the first request rather than on the first failure.
            $this->context->runAs($root, fn (): mixed => $this->keys->activeSigningKey());

            return new InstalledPlatform($plan->shape, $operator, $root, $account, $tenant);
        });
    }

    /**
     * The platform-root environment, stamped `is_default` so the answer lives in the
     * database rather than in per-process configuration.
     *
     * An existing bare default is ADOPTED rather than duplicated: the framework's own
     * bootstrap (`cbox-id:install` before this override, a `migrate` on a seeded image)
     * may already have stamped one, and a second root would put the platform's people in
     * one environment while every reader resolved the other.
     */
    private function platformRoot(InstallPlan $plan): Environment
    {
        return $this->context->withoutScope(function () use ($plan): Environment {
            $existing = Environment::query()->where('is_default', true)->first();

            if ($existing instanceof Environment) {
                return $existing;
            }

            // Named for the job it does in this shape. In the SaaS shape the root holds
            // the platform's own staff and every account's members and never serves a
            // customer's users; single-tenant has one environment doing both, so it
            // takes the name the operator chose for their IdP.
            $name = $plan->shape->isMultiTenant() ? 'Platform' : $plan->environmentName;

            $environment = Environment::query()->create([
                'name' => $name,
                'slug' => $this->uniqueSlug($name),
                'type' => EnvironmentType::Production,
                'status' => EnvironmentStatus::Active,
                'settings' => [],
            ]);

            $environment->makeDefault();

            return $environment;
        });
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name);
        $base = $base !== '' ? $base : 'platform';
        $slug = $base;
        $suffix = 1;

        while (Environment::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.(++$suffix);
        }

        return $slug;
    }

    /**
     * Whether one kind of occupant is present.
     *
     * Environments are the subtle one: a deployment that has run migrations against a
     * seeded image can already hold a BARE DEFAULT environment and nothing else, and
     * refusing to install there would leave that deployment with no supported way
     * forward at all. So a lone `is_default` environment does not count as occupancy —
     * anything beside it does.
     *
     * Subjects and the environment count both run with the tenancy scope suspended:
     * subjects are environment-owned and the scope is deny-by-default, so the honest
     * question "does ANY identity exist here" cannot be asked from inside one tenant.
     */
    /** Short-circuits at the first thing found — one query on a live deployment. */
    private function anyOccupant(): bool
    {
        foreach (PlatformOccupant::cases() as $occupant) {
            if ($this->present($occupant)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the tables this installer writes into exist, and whether the database can
     * be asked at all.
     *
     * The distinction is the whole point: "no tables" and "no database" look identical
     * from a failed query, and they call for opposite answers — the first is a fresh
     * deployment that should be pointed at its setup screen, the second is an outage
     * that must not open one.
     */
    private function schema(): SchemaState
    {
        try {
            return Schema::hasTable('platform_operators') && Schema::hasTable('environments')
                ? SchemaState::Ready
                : SchemaState::Missing;
        } catch (Throwable) {
            return SchemaState::Unreachable;
        }
    }

    private function present(PlatformOccupant $occupant): bool
    {
        return match ($occupant) {
            // Not a probe: nothing in the database says "unreadable". It is reported by
            // {@see occupancy()} when the database cannot be asked, and never found here.
            PlatformOccupant::Unreadable => false,
            PlatformOccupant::Operator => $this->operators->exists(),
            PlatformOccupant::Account => Account::query()->exists(),
            PlatformOccupant::Environment => $this->context->withoutScope(
                static fn (): bool => Environment::query()->where('is_default', false)->exists(),
            ) === true,
            PlatformOccupant::Subject => $this->context->withoutScope(
                static fn (): bool => User::query()->exists(),
            ) === true,
            // An organization is a TENANT, and a registered client is an application
            // someone integrated. Neither is created by anything but an administered
            // platform, and both outlive the identities that made them — an operator row
            // can be deleted, a customer's organizations cannot vanish with it.
            PlatformOccupant::Organization => $this->context->withoutScope(
                static fn (): bool => Organization::query()->exists(),
            ) === true,
            PlatformOccupant::Client => $this->context->withoutScope(
                static fn (): bool => Client::query()->exists(),
            ) === true,
        };
    }
}
