<?php

declare(strict_types=1);

namespace App\Platform\Console;

use App\Platform\Actions\Principal\ConsoleSessionPrincipal;
use Cbox\Id\Identity\Models\User;
use Cbox\Id\Kernel\Audit\Models\AuditEntry;
use Cbox\Id\Kernel\Tenancy\Contracts\TenantContext;
use Cbox\Id\Kernel\Tenancy\GenericTenant;
use Cbox\Id\OAuthServer\Models\Client;
use Cbox\Id\Organization\Enums\OrganizationStatus;
use Cbox\Id\Organization\Models\Membership;
use Cbox\Id\Organization\Models\Organization;
use Cbox\Id\Platform\Models\EnvironmentApiKey;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Route;

/**
 * ⌘K — FIND A THING IN THIS CONSOLE: a user by email, an organization or an app by name, a
 * key by its prefix, an audit entry by its id.
 *
 * THE SCOPE IS THE FIRST LINE OF EVERY QUERY, not a filter at the end. The environment
 * console searches its own environment — the framework's hard environment scope bounds every
 * model here, so a row of another environment cannot be read whatever the term — and the
 * organization console searches only the organization its person is confined to
 * ({@see ConsoleSessionPrincipal::confinedToOrganization()}, the same answer every action
 * asks). A console that cannot open a kind of record is not offered it: the organization
 * console has no organizations list and no management keys, and a customer's console does
 * not serve the apps pages ({@see CustomerConsole}).
 *
 * AN ID JUMPS. A pasted id — a user's, an organization's, an app's id or client_id, a key's
 * prefix, an audit entry's — that names exactly one record answers with `jump`, so Enter
 * opens it. People paste ids out of logs and tickets far more than they type names.
 *
 * A KEY IS MATCHED BY ITS PREFIX ONLY: the first 13 characters, which is what the console
 * shows and the store keeps. A whole key pasted by mistake is cut to that before it is used
 * for anything — the palette cuts it before sending, too.
 */
final readonly class ConsoleSearch
{
    /** Below this, a term matches too much to be worth a query. */
    public const int MIN_TERM = 2;

    /** Per kind: the palette shows a handful, not a report. */
    private const int LIMIT = 6;

    /** How much of a key the store keeps, and so how much of one is ever looked at. */
    private const int KEY_PREFIX = 13;

    public function __construct(
        private ConsoleScope $scope,
        private TenantContext $tenants,
    ) {}

    /**
     * @return array{query: string, jump: array{kind: string, id: string, title: string, subtitle: string|null, href: string}|null, groups: list<array{key: string, label: string, items: list<array{kind: string, id: string, title: string, subtitle: string|null, href: string}>}>}
     */
    public function search(string $term): array
    {
        $term = trim($term);

        if (str_starts_with($term, 'cbid_')) {
            $term = substr($term, 0, self::KEY_PREFIX);
        }

        if (mb_strlen($term) < self::MIN_TERM) {
            return ['query' => $term, 'jump' => null, 'groups' => []];
        }

        $organizationId = (new ConsoleSessionPrincipal($this->scope))->confinedToOrganization();
        $like = LikeTerm::containing($term);

        $groups = array_values(array_filter([
            ['key' => 'users', 'label' => 'Users', 'items' => $this->users($term, $like, $organizationId)],
            ['key' => 'organizations', 'label' => 'Organizations', 'items' => $organizationId === null ? $this->organizations($term, $like) : []],
            ['key' => 'apps', 'label' => 'Apps', 'items' => $this->apps($term, $like, $organizationId)],
            ['key' => 'keys', 'label' => 'Management keys', 'items' => $organizationId === null ? $this->keys($term, $like) : []],
            ['key' => 'audit', 'label' => 'Audit log', 'items' => $this->audit($term, $organizationId)],
        ], static fn (array $group): bool => $group['items'] !== []));

        return ['query' => $term, 'jump' => $this->jump($term, $groups), 'groups' => $groups];
    }

    /**
     * The one record a pasted id names, when it names exactly one.
     *
     * @param  list<array{key: string, label: string, items: list<array{kind: string, id: string, title: string, subtitle: string|null, href: string}>}>  $groups
     * @return array{kind: string, id: string, title: string, subtitle: string|null, href: string}|null
     */
    private function jump(string $term, array $groups): ?array
    {
        $exact = [];

        foreach ($groups as $group) {
            foreach ($group['items'] as $item) {
                if (strcasecmp($item['id'], $term) === 0 || ($item['kind'] === 'app' && $item['subtitle'] === $term)
                    || ($item['kind'] === 'key' && $item['subtitle'] === $term)) {
                    $exact[] = $item;
                }
            }
        }

        return count($exact) === 1 ? $exact[0] : null;
    }

    /**
     * @return list<array{kind: string, id: string, title: string, subtitle: string|null, href: string}>
     */
    private function users(string $term, LikeTerm $like, ?string $organizationId): array
    {
        $route = $organizationId === null ? 'environment.users.show' : $this->scope->peopleRoute();

        if ($route === null || ! $this->serves($route)) {
            return [];
        }

        $query = User::query()
            ->where(fn (Builder $q): Builder => $q
                ->whereKey($term)
                ->orWhereRaw($like->sqlFor('email'), [$like->pattern])
                ->orWhereRaw($like->sqlFor('name'), [$like->pattern]));

        if ($organizationId !== null) {
            // Only the people of THIS organization. Memberships are tenant-owned, so the
            // read is pinned to the organization itself rather than to whatever the request
            // happens to be standing in.
            $members = $this->tenants->runAs(GenericTenant::of($organizationId), static fn (): array => Membership::query()
                ->where('organization_id', $organizationId)
                ->pluck('user_id')
                ->all());

            $query->whereIn('id', $members);
        }

        return array_values($query->orderBy('email')->limit(self::LIMIT)->get(['id', 'name', 'email'])
            ->map(fn (User $user): array => [
                'kind' => 'user',
                'id' => (string) $user->id,
                'title' => is_string($user->name) && $user->name !== '' ? $user->name : (string) $user->email,
                'subtitle' => $user->email,
                'href' => $organizationId === null ? route($route, $user->id) : route($route),
            ])->all());
    }

    /**
     * @return list<array{kind: string, id: string, title: string, subtitle: string|null, href: string}>
     */
    private function organizations(string $term, LikeTerm $like): array
    {
        if (! $this->serves('environment.organizations.show')) {
            return [];
        }

        return array_values(Organization::query()
            ->where('status', '!=', OrganizationStatus::Deleted->value)
            ->where(fn (Builder $q): Builder => $q
                ->whereKey($term)
                ->orWhereRaw($like->sqlFor('name'), [$like->pattern])
                ->orWhereRaw($like->sqlFor('slug'), [$like->pattern]))
            ->orderBy('name')
            ->limit(self::LIMIT)
            ->get(['id', 'name', 'slug'])
            ->map(fn (Organization $organization): array => [
                'kind' => 'organization',
                'id' => (string) $organization->id,
                'title' => $organization->name,
                'subtitle' => $organization->slug,
                'href' => route('environment.organizations.show', $organization->id),
            ])->all());
    }

    /**
     * @return list<array{kind: string, id: string, title: string, subtitle: string|null, href: string}>
     */
    private function apps(string $term, LikeTerm $like, ?string $organizationId): array
    {
        $route = $this->scope->routeName('clients.show');

        if (! $this->serves($route)) {
            return [];
        }

        return array_values(Client::query()
            ->where(fn (Builder $q): Builder => $q
                ->whereKey($term)
                ->orWhere('client_id', $term)
                ->orWhereRaw($like->sqlFor('name'), [$like->pattern]))
            // The organization console's apps: its own, and the platform's first-party apps
            // every organization sees — exactly what its Apps page lists.
            ->when($organizationId !== null, fn (Builder $q): Builder => $q->where(fn (Builder $scoped): Builder => $scoped
                ->where('organization_id', $organizationId)
                ->orWhere(fn (Builder $platform): Builder => $platform->whereNull('organization_id')->where('first_party', true))))
            ->orderBy('name')
            ->limit(self::LIMIT)
            ->get(['id', 'name', 'client_id'])
            ->map(fn (Client $client): array => [
                'kind' => 'app',
                'id' => (string) $client->id,
                'title' => $client->name,
                'subtitle' => $client->client_id,
                'href' => route($route, $client->id),
            ])->all());
    }

    /**
     * @return list<array{kind: string, id: string, title: string, subtitle: string|null, href: string}>
     */
    private function keys(string $term, LikeTerm $like): array
    {
        if (! $this->serves('environment.agents')) {
            return [];
        }

        return array_values(EnvironmentApiKey::query()
            ->where(fn (Builder $q): Builder => $q
                ->whereKey($term)
                ->orWhereRaw($like->sqlFor('name'), [$like->pattern])
                ->orWhere('prefix', $term)
                ->orWhereRaw($like->sqlFor('prefix'), [$like->pattern]))
            ->orderBy('name')
            ->limit(self::LIMIT)
            ->get(['id', 'name', 'prefix'])
            ->map(fn (EnvironmentApiKey $key): array => [
                'kind' => 'key',
                'id' => (string) $key->id,
                'title' => $key->name,
                'subtitle' => $key->prefix,
                'href' => route('environment.agents'),
            ])->all());
    }

    /**
     * An audit entry by its exact id — there is nothing else in an entry worth matching by
     * name, and the audit log's own filters do the rest.
     *
     * @return list<array{kind: string, id: string, title: string, subtitle: string|null, href: string}>
     */
    private function audit(string $term, ?string $organizationId): array
    {
        $route = $this->scope->routeName('audit');

        if (! $this->serves($route)) {
            return [];
        }

        return array_values(AuditEntry::query()
            ->whereKey($term)
            ->when($organizationId !== null, fn (Builder $q): Builder => $q->where('organization_id', $organizationId))
            ->limit(1)
            ->get(['id', 'action', 'recorded_at'])
            ->map(fn (AuditEntry $entry): array => [
                'kind' => 'audit',
                'id' => (string) $entry->id,
                'title' => $entry->action,
                'subtitle' => $entry->recorded_at?->toIso8601String(),
                'href' => route($route, ['entry' => $entry->id]),
            ])->all());
    }

    /** Whether this console serves the page a result would link to. */
    private function serves(string $route): bool
    {
        if (! Route::has($route)) {
            return false;
        }

        return ! $this->scope->atCustomerAltitude() || CustomerConsole::servesRoute($route);
    }
}
