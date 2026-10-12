<?php

declare(strict_types=1);

namespace Cbox\Id\Connectors\Http\Controllers;

use App\Http\Props\Shared\HelpProps;
use App\Http\Props\Shared\PaginationProps;
use App\Platform\Help\HelpTopic;
use Cbox\Id\Connectors\Catalog\ConnectorCatalog;
use Cbox\Id\Connectors\Connections\ConnectionsOverview;
use Cbox\Id\Connectors\ValueObjects\ConnectionSummary;
use Cbox\Id\Connectors\ValueObjects\ConnectorDescriptor;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Inertia\Response;

/**
 * CONNECTORS › CONNECTIONS — every live connection, of every type, in one list: what it is,
 * whose it is, whether it is working, when it last did something, and a link to its own page.
 *
 * It listed three types, found an organization's webhooks by guessing at event types, and
 * linked nowhere — so in an environment with an SSO connection, a SCIM directory, a webhook
 * and a log stream it said "No connectors yet", and a row, had there been one, was a dead end.
 *
 * FAILING FIRST. The question this page answers that no type's own list can is "is anything
 * broken?", so rows the records say are failing sort to the top, and `?status=failing` is a
 * filter the catalog links straight to. Type and status are query parameters with their hrefs
 * resolved here, so a filtered view is a URL somebody can send.
 *
 * The scoping and the COPY move together: the page says "in this environment" only when it
 * lists the whole environment, and the Organization chip narrows it the way it narrows every
 * other environment-wide list.
 */
final readonly class ConnectionsController extends ConnectorsPage
{
    private const int PER_PAGE = 50;

    /** Status filter key => label. */
    private const array STATUSES = [
        'failing' => 'Failing',
        'active' => 'Active',
        'inactive' => 'Off or draft',
    ];

    public function __invoke(Request $request, ConnectorCatalog $catalog, ConnectionsOverview $overview): Response
    {
        $this->scope->assertMayAdminister();

        $filter = $this->organizationFilter();
        $organizationId = $filter->id;

        /** @var array<string, ConnectorDescriptor> $offered category value => descriptor */
        $offered = [];

        foreach ($catalog->all() as $descriptor) {
            if ($this->offers($descriptor)) {
                $offered[$descriptor->category->value] = $descriptor;
            }
        }

        // Only types this console serves: a row nobody here can open is a row this page
        // cannot explain. An unknown organization in the filter is an empty list, never
        // the unfiltered one.
        $all = $filter->unknown ? [] : array_values(array_filter(
            $overview->forOrganization($organizationId),
            static fn (ConnectionSummary $row): bool => isset($offered[$row->category->value]),
        ));

        $type = $request->string('type')->toString();
        $type = $catalog->find($type) !== null && isset($offered[$catalog->find($type)->category->value]) ? $type : '';
        $status = $request->string('status')->toString();
        $status = array_key_exists($status, self::STATUSES) ? $status : '';

        $ofType = $type === ''
            ? $all
            : array_values(array_filter($all, static fn (ConnectionSummary $row): bool => $offered[$row->category->value]->key === $type));

        $rows = $status === ''
            ? $ofType
            : array_values(array_filter($ofType, static fn (ConnectionSummary $row): bool => self::hasStatus($row, $status)));

        $order = array_flip(array_keys($offered));

        usort($rows, static fn (ConnectionSummary $a, ConnectionSummary $b): int => [! $a->needsAttention(), $order[$a->category->value], strtolower($a->name)]
            <=> [! $b->needsAttention(), $order[$b->category->value], strtolower($b->name)]);

        $page = max(1, $request->integer('page', 1));
        $paginator = new LengthAwarePaginator(
            array_slice($rows, ($page - 1) * self::PER_PAGE, self::PER_PAGE),
            count($rows),
            self::PER_PAGE,
            $page,
        );

        $names = $this->scope->organizationNames(array_values(array_filter(
            array_map(static fn (ConnectionSummary $row): ?string => $row->organizationId, $paginator->items()),
        )));

        $base = $this->url('connectors.connections');
        $keep = array_filter([
            'organization' => $request->query('organization') !== null ? (string) $organizationId : null,
            'type' => $type !== '' ? $type : null,
            'status' => $status !== '' ? $status : null,
        ]);

        return $this->page('connectors::connections', 'Connections', [
            'help' => HelpProps::for(HelpTopic::Connectors),
            'connections' => array_map(fn (ConnectionSummary $row): array => $this->row($row, $offered[$row->category->value], $names), $paginator->items()),
            'pagination' => PaginationProps::from($paginator),
            'filtered' => $type !== '' || $status !== '',
            'types' => [
                ['key' => '', 'label' => 'Every type', 'count' => count($all), 'href' => $this->withQuery($base, array_diff_key($keep, ['type' => 1])), 'selected' => $type === ''],
                ...array_values(array_map(fn (ConnectorDescriptor $descriptor): array => [
                    'key' => $descriptor->key,
                    'label' => $descriptor->name,
                    'count' => count(array_filter($all, static fn (ConnectionSummary $row): bool => $row->category === $descriptor->category)),
                    'href' => $this->withQuery($base, [...$keep, 'type' => $descriptor->key]),
                    'selected' => $type === $descriptor->key,
                ], $offered)),
            ],
            'statuses' => [
                ['key' => '', 'label' => 'All', 'count' => count($ofType), 'href' => $this->withQuery($base, array_diff_key($keep, ['status' => 1])), 'selected' => $status === ''],
                ...array_map(fn (string $key, string $label): array => [
                    'key' => $key,
                    'label' => $label,
                    'count' => count(array_filter($ofType, static fn (ConnectionSummary $row): bool => self::hasStatus($row, $key))),
                    'href' => $this->withQuery($base, [...$keep, 'status' => $key]),
                    'selected' => $status === $key,
                ], array_keys(self::STATUSES), self::STATUSES),
            ],
            'organizationFilter' => $this->organizationFilterProps($filter),
            // "+ New connection" is the catalog: the type picker, with every type's setup.
            'catalogHref' => $this->url('connectors.catalog'),
            'clearHref' => $this->withQuery($base, array_intersect_key($keep, ['organization' => 1])),
            'wholeEnvironment' => $organizationId === null && ! $filter->unknown && $this->organizationFilterProps($filter) !== null,
        ]);
    }

    /**
     * One line of the list.
     *
     * @param  array<string, string>  $names  organization id => name, for this page's rows
     * @return array<string, mixed>
     */
    private function row(ConnectionSummary $row, ConnectorDescriptor $descriptor, array $names): array
    {
        $href = $this->rowHref($descriptor, $row);

        return [
            'id' => $row->id,
            'type' => $row->category->label(),
            'name' => $row->name,
            'target' => $row->target,
            'organization' => $row->organizationId === null ? null : ($names[$row->organizationId] ?? $row->organizationId),
            'status' => $row->status,
            'verdict' => $row->verdict(),
            'problem' => $row->problem,
            'lastActivityAt' => $row->lastActivityAt?->toIso8601String(),
            'href' => $href,
            // Where it IS changed, when not from here — never a row that just stops.
            'note' => $href !== null ? null : ($row->organizationId === null
                ? 'Set up for the whole environment, so it is changed in the environment console.'
                : 'This organization\'s own, so it is changed in its Admin Portal.'),
        ];
    }

    private static function hasStatus(ConnectionSummary $row, string $status): bool
    {
        return match ($status) {
            'failing' => $row->needsAttention(),
            'active' => $row->isActive(),
            default => ! $row->isActive(),
        };
    }
}
