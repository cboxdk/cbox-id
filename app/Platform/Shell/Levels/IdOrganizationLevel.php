<?php

declare(strict_types=1);

namespace App\Platform\Shell\Levels;

use App\Platform\CurrentUser;
use App\Platform\OAuth\Contracts\AuthorizationOrganizations;
use App\Platform\OAuth\Exceptions\OrganizationCreationRefused;
use App\Platform\OAuth\ValueObjects\OrganizationChoice;
use App\Platform\Organizations\OrganizationDirectory;
use App\Platform\Organizations\OrganizationEntry;
use App\Platform\Organizations\OrganizationLinks;
use App\Platform\Shell\ShellRequest;
use Cbox\Console\Kit\Contracts\ContextLevel;
use Cbox\Console\Kit\Contracts\CreatesContextItems;
use Cbox\Console\Kit\Shell\ContextItem;
use Cbox\Console\Kit\Shell\ContextPage;
use Cbox\Console\Kit\Shell\ContextQuery;
use Cbox\Console\Kit\Shell\CreatedContextItem;
use Cbox\Id\Platform\PlatformRoot;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * THE ORGANIZATION LEVEL: the platform-root organizations the person belongs to, addressed
 * by the slug `/orgs/{org}` uses (the linked `cbox` organization's, so Work and Billing name
 * the same organization the same way).
 *
 * Local: every read is the membership table and the organization links, through
 * {@see OrganizationDirectory}. Creating one is name only and self-serve, the way an app's
 * "Create organization" works ({@see AuthorizationOrganizations::create()}), and lands on its
 * settings to finish setting up.
 */
final class IdOrganizationLevel implements ContextLevel, CreatesContextItems
{
    /*
     * Built once, at boot, and asked on every request: so it holds nothing, and resolves
     * what it reads per call (the directory keeps a per-request address cache).
     */

    public function key(): string
    {
        return 'organization';
    }

    public function label(): string
    {
        return __('console.organization_d764d425');
    }

    public function routeParameter(): string
    {
        return 'org';
    }

    public function current(Request $request, array $ancestors): ?ContextItem
    {
        if (ShellRequest::onPlatformAdmin($request) || ShellRequest::onTenantConsole()) {
            return null;
        }

        $slug = ShellRequest::organizationSlug($request);
        $subject = ShellRequest::subjectId();

        if ($slug === null || $subject === null) {
            return null;
        }

        // The page's own middleware has already bound the organization the path names and
        // checked the membership: name it from that rather than reading the memberships again.
        $user = app(CurrentUser::class);
        $bound = $user->check() && $user->role() !== null ? $user->organizationId() : null;

        if ($bound !== null && $user->id() === $subject) {
            $address = app(OrganizationLinks::class)->addressesOf([$bound])[$bound] ?? null;

            if ($address !== null && $address['slug'] === $slug) {
                return new ContextItem(id: $bound, name: $address['name'], routeKey: $address['slug']);
            }
        }

        $entry = app(OrganizationDirectory::class)->find($subject, $slug);

        return $entry === null ? null : self::item($entry);
    }

    public function find(Request $request, string $id, array $ancestors): ?ContextItem
    {
        foreach ($this->entries() as $entry) {
            if ($entry->id === $id || $entry->slug === $id) {
                return self::item($entry);
            }
        }

        return null;
    }

    public function list(Request $request, ContextQuery $query): ContextPage
    {
        $term = mb_strtolower(trim((string) $query->search));
        $matches = array_values(array_filter(
            $this->entries(),
            static fn (OrganizationEntry $entry): bool => $term === '' || str_contains(mb_strtolower($entry->name), $term) || str_contains($entry->slug, $term),
        ));

        return self::page(array_map(self::item(...), $matches), $query);
    }

    public function url(ContextItem $item, array $ancestors): string
    {
        return route('orgs.show', ['org' => $item->routeKey], false);
    }

    public function allUrl(array $ancestors): string
    {
        return route('orgs.index', [], false);
    }

    public function settingsUrl(ContextItem $item, array $ancestors): string
    {
        return route('orgs.settings', ['org' => $item->routeKey], false);
    }

    public function canCreate(Request $request, array $ancestors): bool
    {
        return ShellRequest::subjectId() !== null
            && ! ShellRequest::onTenantConsole()
            && app(PlatformRoot::class)->run(fn (): bool => app(AuthorizationOrganizations::class)->creationOffered()) === true;
    }

    public function create(Request $request, string $name, array $ancestors): CreatedContextItem
    {
        $subject = ShellRequest::subjectId();
        abort_if($subject === null, 403);

        try {
            $choice = app(PlatformRoot::class)->run(fn () => app(AuthorizationOrganizations::class)->create($subject, $name));
        } catch (OrganizationCreationRefused $refused) {
            throw ValidationException::withMessages(['name' => $refused->getMessage()]);
        }

        abort_unless($choice instanceof OrganizationChoice, 500);
        $slug = app(OrganizationDirectory::class)->slugOf($choice->id);
        abort_if($slug === null, 500);

        return new CreatedContextItem($choice->id, route('orgs.settings', ['org' => $slug], false).'#finish');
    }

    /** @return list<OrganizationEntry> */
    private function entries(): array
    {
        $subject = ShellRequest::subjectId();

        return $subject === null ? [] : app(OrganizationDirectory::class)->forSubject($subject);
    }

    private static function item(OrganizationEntry $entry): ContextItem
    {
        return new ContextItem(id: $entry->id, name: $entry->name, routeKey: $entry->slug);
    }

    /**
     * One page of an in-memory list. The cursor is the offset: a person's organizations,
     * projects and environments are a short list read whole.
     *
     * @param  list<ContextItem>  $items
     */
    public static function page(array $items, ContextQuery $query): ContextPage
    {
        $offset = max(0, (int) ($query->cursor ?? 0));
        $limit = max(1, $query->limit);
        $slice = array_slice($items, $offset, $limit);

        return new ContextPage($slice, $offset + $limit < count($items) ? (string) ($offset + $limit) : null);
    }
}
