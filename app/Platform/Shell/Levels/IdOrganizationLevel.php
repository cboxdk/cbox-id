<?php

declare(strict_types=1);

namespace App\Platform\Shell\Levels;

use App\Http\Props\Shell\SwitchOptionProps;
use App\Platform\Console\ShellContext;
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
    private const HERE = 'cbox-id.shell.organizations-here';

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
        if (ShellRequest::onCboxConsole()) {
            foreach (self::here() as $item) {
                if ($item['current']) {
                    return $item['item'];
                }
            }

            return null;
        }

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
        if (ShellRequest::onCboxConsole()) {
            foreach (self::here() as $item) {
                if ($item['item']->id === $id) {
                    return $item['item'];
                }
            }

            return null;
        }

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

        if (ShellRequest::onCboxConsole()) {
            $items = array_values(array_filter(
                array_map(static fn (array $row): ContextItem => $row['item'], self::here()),
                static fn (ContextItem $item): bool => $term === '' || str_contains(mb_strtolower($item->name), $term),
            ));

            return self::page($items, $query);
        }

        $matches = array_values(array_filter(
            $this->entries(),
            static fn (OrganizationEntry $entry): bool => $term === '' || str_contains(mb_strtolower($entry->name), $term) || str_contains($entry->slug, $term),
        ));

        return self::page(array_map(self::item(...), $matches), $query);
    }

    public function url(ContextItem $item, array $ancestors): string
    {
        // Cbox's own console keeps the organization in the session: the page posts the
        // choice to `orgs.switch` ({@see \App\Platform\Shell\IdShell}), which lands here.
        if (ShellRequest::onCboxConsole()) {
            return route('dashboard', [], false);
        }

        return route('orgs.show', ['org' => $item->routeKey], false);
    }

    public function allUrl(array $ancestors): string
    {
        if (ShellRequest::onCboxConsole()) {
            return route('dashboard', [], false);
        }

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

    /**
     * Cbox's own console: the person's organizations in the `cbox` environment, read once
     * per request.
     *
     * @return list<array{item: ContextItem, current: bool}>
     */
    private static function here(): array
    {
        $request = request();
        $memo = $request->attributes->get(self::HERE);

        if (is_array($memo)) {
            /** @var list<array{item: ContextItem, current: bool}> $memo */
            return $memo;
        }

        $rows = array_map(
            static fn (SwitchOptionProps $option): array => [
                'item' => new ContextItem(id: $option->id, name: $option->label, routeKey: $option->id),
                'current' => $option->current,
            ],
            app(ShellContext::class)->organizationsHere(),
        );

        $request->attributes->set(self::HERE, $rows);

        return $rows;
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
