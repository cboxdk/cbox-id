<?php

declare(strict_types=1);

namespace App\Platform\Organizations;

use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\Enums\MembershipStatus;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use DomainException;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Which platform-root organization IS which organization in the `cbox` environment, and
 * so which slug addresses it at `/orgs/{slug}`.
 *
 * A Cbox customer has one organization, and Work, Billing and the rest keep it in the
 * `cbox` environment. Cbox ID's console authority (projects, keys, billing, the team)
 * still hangs off a row in the platform-root environment. The link joins the two, and the
 * `cbox` organization's slug is the address: the same `/orgs/{slug}` in every Cbox app.
 * An unlinked platform-root organization keeps its own slug as its address.
 *
 * Reads go straight to the tables with the environment named in the query, never through
 * the environment-scoped models: this class answers across two environments on purpose,
 * and a global scope would quietly answer for whichever one the request stands on.
 */
final class OrganizationLinks
{
    /** @var array<string, array{slug: string, name: string, linked: bool}> */
    private array $addresses = [];

    private ?string $cboxEnvironment = null;

    private bool $cboxResolved = false;

    private mixed $root = null;

    /**
     * The `cbox` environment's id, or null where this deployment has none (a fresh
     * install, a developer machine, the test suite). Never the platform root itself.
     */
    public function cboxEnvironmentId(): ?string
    {
        if ($this->cboxResolved) {
            return $this->cboxEnvironment;
        }

        $slug = config('cbox-id.cbox_environment');
        $id = is_string($slug) && $slug !== ''
            ? DB::table('environments')->where('slug', $slug)->where('is_default', false)->value('id')
            : null;

        $this->cboxResolved = true;

        return $this->cboxEnvironment = is_string($id) ? $id : null;
    }

    /**
     * The platform root's id: the `is_default` environment. A deployment without one (a
     * single-environment install, most of the test suite) runs its console in the
     * environment the request stands in, so that is the one whose memberships count.
     */
    public function rootEnvironmentId(): ?string
    {
        // Once per request: it does not move, and the shell and the page both ask.
        $id = $this->root ??= DB::table('environments')->where('is_default', true)->value('id');

        if (is_string($id)) {
            return $id;
        }

        $ambient = app(EnvironmentContext::class)->current()?->environmentKey();

        return is_string($ambient) && $ambient !== '' ? $ambient : null;
    }

    /**
     * The slug `/orgs/{slug}` names a platform-root organization by, and the name the
     * console shows for it: the linked `cbox` organization's, or its own.
     *
     * @return array{slug: string, name: string, linked: bool}|null
     */
    public function addressOf(string $organizationId): ?array
    {
        return $this->addressesOf([$organizationId])[$organizationId] ?? null;
    }

    /**
     * @param  list<string>  $organizationIds
     * @return array<string, array{slug: string, name: string, linked: bool}>
     */
    public function addressesOf(array $organizationIds): array
    {
        $missing = array_values(array_diff(array_unique($organizationIds), array_keys($this->addresses)));

        if ($missing !== []) {
            $rows = DB::table('organizations as o')
                ->leftJoin('organization_links as l', 'l.organization_id', '=', 'o.id')
                ->leftJoin('organizations as c', 'c.id', '=', 'l.cbox_organization_id')
                ->whereIn('o.id', $missing)
                ->get(['o.id', 'o.slug', 'o.name', 'c.slug as cbox_slug', 'c.name as cbox_name']);

            // An unlinked organization's own slug that a link already claims for another
            // organization: two organizations never share an address, so it is addressed
            // by its id until an operator links it ({@see candidatesFor()}).
            // Asked only about the unlinked rows on hand, and by their ids.
            $unlinked = $rows->filter(static fn (object $row): bool => ! is_string($row->cbox_slug) || $row->cbox_slug === '')
                ->pluck('id')
                ->all();

            $claimed = $unlinked === [] ? [] : DB::table('organization_links as l')
                ->join('organizations as c', 'c.id', '=', 'l.cbox_organization_id')
                ->whereIn('c.slug', DB::table('organizations')->select('slug')->whereIn('id', $unlinked))
                ->pluck('l.organization_id', 'c.slug')
                ->all();

            foreach ($rows as $row) {
                $linked = is_string($row->cbox_slug) && $row->cbox_slug !== '';
                $own = self::str($row->slug);
                $taken = ! $linked && isset($claimed[$own]) && self::str($claimed[$own]) !== self::str($row->id);

                $this->addresses[self::str($row->id)] = [
                    'slug' => $linked ? self::str($row->cbox_slug) : ($taken ? strtolower(self::str($row->id)) : $own),
                    'name' => $linked ? self::str($row->cbox_name) : self::str($row->name),
                    'linked' => $linked,
                ];
            }
        }

        return array_intersect_key($this->addresses, array_flip($organizationIds));
    }

    /**
     * The platform-root organizations `/orgs/{slug}` may name, linked ones first: a linked
     * organization answers to its `cbox` slug, an unlinked one to its own. A slug that a
     * link claims is never also read as an unlinked organization's own, which is the rule
     * {@see link()} keeps true for new pairs.
     *
     * @return list<string>
     */
    public function candidatesFor(string $slug): array
    {
        $root = $this->rootEnvironmentId();

        if ($root === null || $slug === '') {
            return [];
        }

        $linked = DB::table('organization_links as l')
            ->join('organizations as c', 'c.id', '=', 'l.cbox_organization_id')
            ->where('c.slug', $slug)
            ->pluck('l.organization_id')
            ->map(static fn (mixed $id): string => self::str($id))
            ->all();

        // A slug a link claims names the linked organization and nothing else.
        if ($linked !== []) {
            return array_values(array_unique($linked));
        }

        // Else an unlinked organization by its own slug, or by its id where its slug is
        // claimed ({@see addressesOf()}).
        $own = DB::table('organizations as o')
            ->leftJoin('organization_links as l', 'l.organization_id', '=', 'o.id')
            ->where('o.environment_id', $root)
            ->where(static fn (Builder $query): Builder => $query->where('o.slug', $slug)->orWhere('o.id', $slug))
            ->whereNull('l.id')
            ->pluck('o.id')
            ->map(static fn (mixed $id): string => self::str($id))
            ->all();

        return array_values(array_unique($own));
    }

    /**
     * Pair a platform-root organization with its `cbox` organization.
     *
     * Refused, with the reason, when either side is already paired, when the two ids are
     * not in the environments they claim to be in, or when the `cbox` slug is the address
     * of ANOTHER platform-root organization (its own slug, unlinked): pairing it would give
     * two organizations one address.
     *
     * @throws DomainException
     */
    public function link(string $organizationId, string $cboxOrganizationId, string $pairedBy = 'operator'): void
    {
        $root = $this->rootEnvironmentId();
        $cbox = $this->cboxEnvironmentId();

        if ($root === null || $cbox === null) {
            throw new DomainException('This deployment has no `cbox` environment to link to.');
        }

        $platform = DB::table('organizations')->where('id', $organizationId)->where('environment_id', $root)->first(['id', 'slug']);
        $shared = DB::table('organizations')->where('id', $cboxOrganizationId)->where('environment_id', $cbox)->first(['id', 'slug']);

        if ($platform === null) {
            throw new DomainException("No platform-root organization {$organizationId}.");
        }

        if ($shared === null) {
            throw new DomainException("No organization {$cboxOrganizationId} in the cbox environment.");
        }

        if (DB::table('organization_links')->where('organization_id', $organizationId)->exists()) {
            throw new DomainException('Platform-root organization '.self::str($platform->slug).' is already linked.');
        }

        if (DB::table('organization_links')->where('cbox_organization_id', $cboxOrganizationId)->exists()) {
            throw new DomainException('Cbox organization '.self::str($shared->slug).' is already linked.');
        }

        $taken = DB::table('organizations as o')
            ->leftJoin('organization_links as l', 'l.organization_id', '=', 'o.id')
            ->where('o.environment_id', $root)
            ->where('o.slug', $shared->slug)
            ->where('o.id', '!=', $organizationId)
            ->whereNull('l.id')
            ->exists();

        if ($taken) {
            throw new DomainException('The slug '.self::str($shared->slug).' already addresses another platform-root organization.');
        }

        DB::table('organization_links')->insert([
            'id' => (string) Str::ulid(),
            'organization_id' => $organizationId,
            'cbox_organization_id' => $cboxOrganizationId,
            'cbox_environment_id' => $cbox,
            'paired_by' => $pairedBy,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        unset($this->addresses[$organizationId]);
    }

    /**
     * Pair every unpaired platform-root organization with the `cbox` organization of the
     * same slug, where it is plainly the same customer ({@see autoPairs()}). Returns how
     * many it paired. Safe to run again.
     */
    public function pairBySlug(): int
    {
        $paired = 0;

        foreach ($this->autoPairs() as $pair) {
            try {
                $this->link($pair['organization_id'], $pair['cbox_organization_id'], 'slug');
                $paired++;
            } catch (DomainException) {
                // Left for an operator; `orgs:link --unpaired` names it.
            }
        }

        return $paired;
    }

    /**
     * The pairs {@see pairBySlug()} would make, without making them (the dry run).
     *
     * An equal slug alone is not the same customer: two unrelated customers can both have
     * chosen `acme`. A pair is offered only when the slugs match AND an active owner of
     * the platform-root organization is an active member of the `cbox` organization, by
     * e-mail address (a person has one user row per environment). Everything else is left
     * for an operator, by hand, with `orgs:link`.
     *
     * @param  list<string>|null  $organizationIds  only these platform-root organizations
     * @return list<array{organization_id: string, cbox_organization_id: string, slug: string}>
     */
    public function autoPairs(?array $organizationIds = null): array
    {
        $root = $this->rootEnvironmentId();
        $cbox = $this->cboxEnvironmentId();

        if ($root === null || $cbox === null) {
            return [];
        }

        return array_values(DB::table('organizations as o')
            ->join('organizations as c', static function (JoinClause $join) use ($cbox): void {
                $join->on('c.slug', '=', 'o.slug')->where('c.environment_id', '=', $cbox);
            })
            ->leftJoin('organization_links as lo', 'lo.organization_id', '=', 'o.id')
            ->leftJoin('organization_links as lc', 'lc.cbox_organization_id', '=', 'c.id')
            ->where('o.environment_id', $root)
            ->whereNull('lo.id')
            ->whereNull('lc.id')
            ->when($organizationIds !== null, static fn (Builder $query): Builder => $query->whereIn('o.id', $organizationIds ?? []))
            ->whereExists(static function (Builder $query) use ($root, $cbox): void {
                $query->selectRaw('1')
                    ->from('memberships as mo')
                    ->join('users as uo', 'uo.id', '=', 'mo.user_id')
                    ->join('users as uc', static function (JoinClause $join) use ($cbox): void {
                        $join->whereRaw('lower(uc.email) = lower(uo.email)')->where('uc.environment_id', '=', $cbox);
                    })
                    ->join('memberships as mc', 'mc.user_id', '=', 'uc.id')
                    ->whereColumn('mo.organization_id', 'o.id')
                    ->whereColumn('mc.organization_id', 'c.id')
                    ->where('uo.environment_id', $root)
                    ->where('mo.role', MembershipRole::Owner->value)
                    ->where('mo.status', MembershipStatus::Active->value)
                    ->where('mc.status', MembershipStatus::Active->value);
            })
            ->orderBy('o.slug')
            ->distinct()
            ->get(['o.id as organization_id', 'c.id as cbox_organization_id', 'o.slug'])
            ->map(static fn (object $row): array => [
                'organization_id' => self::str($row->organization_id),
                'cbox_organization_id' => self::str($row->cbox_organization_id),
                'slug' => self::str($row->slug),
            ])
            ->all());
    }

    /**
     * Undo a pair made by mistake. The platform-root organization goes back to its own
     * slug as its address. Returns whether there was a link to remove.
     */
    public function unlink(string $organizationId): bool
    {
        $removed = DB::table('organization_links')->where('organization_id', $organizationId)->delete() > 0;

        $this->addresses = [];

        return $removed;
    }

    /**
     * A platform-root organization that was just created gets its `cbox` organization in
     * the same transaction, so a new customer has one organization, at one address, in
     * every Cbox app from the first request.
     *
     * Paired with an existing `cbox` organization only on the rule {@see autoPairs()}
     * keeps (same slug, and the owner is a member there). Otherwise a new `cbox`
     * organization is created with the same name and, where it is free, the same slug,
     * and the owner joins it as owner where they already have a user in the `cbox`
     * environment. A deployment without a `cbox` environment does nothing here.
     *
     * Returns the `cbox` organization's id, or null when nothing was linked.
     */
    public function ensureForNewOrganization(string $organizationId): ?string
    {
        $root = $this->rootEnvironmentId();
        $cboxId = $this->cboxEnvironmentId();

        if ($root === null || $cboxId === null) {
            return null;
        }

        $platform = DB::table('organizations')->where('id', $organizationId)->where('environment_id', $root)->first(['id', 'slug', 'name']);

        if ($platform === null || DB::table('organization_links')->where('organization_id', $organizationId)->exists()) {
            return null;
        }

        // Only a NEW organization: one whose first member is its owner. An owner added to
        // an existing unlinked organization is an operator's pair to make, not this one.
        if (DB::table('memberships')->where('organization_id', $organizationId)->count() !== 1) {
            return null;
        }

        $existing = $this->autoPairs([$organizationId])[0]['cbox_organization_id'] ?? null;

        if ($existing !== null) {
            $this->link($organizationId, $existing, 'created');

            return $existing;
        }

        $environment = Environment::query()->find($cboxId);

        if (! $environment instanceof Environment) {
            return null;
        }

        $slug = $this->freeCboxSlug(self::str($platform->slug), $organizationId, $root, $cboxId);
        $owners = DB::table('memberships as m')
            ->join('users as u', 'u.id', '=', 'm.user_id')
            ->where('m.organization_id', $organizationId)
            ->where('m.role', MembershipRole::Owner->value)
            ->pluck('u.email')
            ->map(static fn (mixed $email): string => strtolower(self::str($email)))
            ->all();

        $created = app(EnvironmentContext::class)->runAs($environment, static function () use ($platform, $slug, $owners, $cboxId): string {
            $organization = app(Organizations::class)->create(new NewOrganization(name: self::str($platform->name), slug: $slug));

            $users = $owners === [] ? [] : DB::table('users')
                ->where('environment_id', $cboxId)
                ->whereIn(DB::raw('lower(email)'), $owners)
                ->pluck('id')
                ->all();

            foreach ($users as $userId) {
                app(Memberships::class)->add($organization->id, self::str($userId), MembershipRole::Owner);
            }

            return $organization->id;
        });

        $this->link($organizationId, $created, 'created');

        return $created;
    }

    /**
     * The organization's own slug in the `cbox` environment where it is free there, else
     * that slug with a short suffix. Never a slug another unlinked platform-root
     * organization answers to ({@see link()} refuses that pair).
     */
    private function freeCboxSlug(string $slug, string $organizationId, string $root, string $cbox): string
    {
        $candidate = $slug;

        for ($attempt = 0; $attempt < 10; $attempt++) {
            $takenInCbox = DB::table('organizations')->where('environment_id', $cbox)->where('slug', $candidate)->exists();
            $takenInRoot = DB::table('organizations')->where('environment_id', $root)->where('slug', $candidate)->where('id', '!=', $organizationId)->exists();

            if (! $takenInCbox && ! $takenInRoot) {
                return $candidate;
            }

            $candidate = $slug.'-'.Str::lower(Str::random(6));
        }

        return $slug.'-'.Str::lower((string) Str::ulid());
    }

    /**
     * Platform-root organizations with no link, for the operator's report.
     *
     * @return list<array{id: string, slug: string, name: string}>
     */
    public function unpaired(): array
    {
        $root = $this->rootEnvironmentId();

        if ($root === null) {
            return [];
        }

        return array_values(DB::table('organizations as o')
            ->leftJoin('organization_links as l', 'l.organization_id', '=', 'o.id')
            ->where('o.environment_id', $root)
            ->whereNull('l.id')
            ->orderBy('o.slug')
            ->get(['o.id', 'o.slug', 'o.name'])
            ->map(static fn (object $row): array => ['id' => self::str($row->id), 'slug' => self::str($row->slug), 'name' => self::str($row->name)])
            ->values()
            ->all());
    }

    /** Forget what this instance read, after a write elsewhere changed it. */
    public function flush(): void
    {
        $this->addresses = [];
        $this->cboxResolved = false;
        $this->cboxEnvironment = null;
    }

    private static function str(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
