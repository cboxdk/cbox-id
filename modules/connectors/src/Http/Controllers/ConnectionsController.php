<?php

declare(strict_types=1);

namespace Cbox\Id\Connectors\Http\Controllers;

use App\Http\Props\Shared\HelpProps;
use App\Http\Props\Shared\PaginationProps;
use App\Platform\Help\HelpTopic;
use Cbox\Id\Connectors\Catalog\ConnectorCatalog;
use Cbox\Id\Connectors\Connections\ConnectionsOverview;
use Cbox\Id\Connectors\Enums\ConnectorCategory;
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

        $type = $request->string('type')->toString();
        $chosen = $catalog->find($type);
        $type = $chosen !== null && isset($offered[$chosen->category->value]) ? $type : '';
        $status = $request->string('status')->toString();
        $status = array_key_exists($status, self::STATUSES) ? $status : '';

        // Only types this console serves: a row nobody here can open is a row this page
        // cannot explain.
        $categories = array_values(array_map(static fn (ConnectorDescriptor $descriptor): ConnectorCategory => $descriptor->category, $offered));
        $listed = $type === '' ? $categories : [$catalog->find($type)->category ?? $categories[0]];

        // One aggregate per type for the filters' counts, and the page itself from the
        // database — failing first, then by type, then by name — so the list reads fifty
        // rows whatever the environment holds. An unknown organization in the filter is an
        // empty list, never the unfiltered one.
        $counts = $filter->unknown ? [] : $overview->counts($organizationId, $categories);
        $page = max(1, $request->integer('page', 1));
        $found = $filter->unknown || $listed === []
            ? ['rows' => [], 'total' => 0]
            : $overview->page($organizationId, $listed, $status, self::PER_PAGE, $page);

        $paginator = new LengthAwarePaginator($found['rows'], $found['total'], self::PER_PAGE, $page);

        $names = $this->scope->organizationNames(array_values(array_filter(
            array_map(static fn (ConnectionSummary $row): ?string => $row->organizationId, $found['rows']),
        )));

        $base = $this->url('connectors.connections');
        $keep = array_filter([
            'organization' => $request->query('organization') !== null ? (string) $organizationId : null,
            'type' => $type !== '' ? $type : null,
            'status' => $status !== '' ? $status : null,
        ]);

        return $this->page('connectors::connections', 'Connections', [
            'help' => HelpProps::for(HelpTopic::Connectors),
            'connections' => array_map(fn (ConnectionSummary $row): array => $this->row($row, $offered[$row->category->value], $names), $found['rows']),
            'pagination' => PaginationProps::from($paginator),
            'filtered' => $type !== '' || $status !== '',
            'types' => [
                ['key' => '', 'label' => 'Every type', 'count' => self::sum($counts, $categories, 'total'), 'href' => $this->withQuery($base, array_diff_key($keep, ['type' => 1])), 'selected' => $type === ''],
                ...array_values(array_map(fn (ConnectorDescriptor $descriptor): array => [
                    'key' => $descriptor->key,
                    'label' => $descriptor->name,
                    'count' => $counts[$descriptor->category->value]['total'] ?? 0,
                    'href' => $this->withQuery($base, [...$keep, 'type' => $descriptor->key]),
                    'selected' => $type === $descriptor->key,
                ], $offered)),
            ],
            'statuses' => [
                ['key' => '', 'label' => 'All', 'count' => self::sum($counts, $listed, 'total'), 'href' => $this->withQuery($base, array_diff_key($keep, ['status' => 1])), 'selected' => $status === ''],
                ...array_map(fn (string $key, string $label): array => [
                    'key' => $key,
                    'label' => $label,
                    'count' => match ($key) {
                        'failing' => self::sum($counts, $listed, 'failing'),
                        'active' => self::sum($counts, $listed, 'active'),
                        default => self::sum($counts, $listed, 'total') - self::sum($counts, $listed, 'active'),
                    },
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

    /**
     * One count across several types.
     *
     * @param  array<string, array{total: int, active: int, failing: int}>  $counts
     * @param  list<ConnectorCategory>  $categories
     * @param  'total'|'active'|'failing'  $field
     */
    private static function sum(array $counts, array $categories, string $field): int
    {
        $total = 0;

        foreach ($categories as $category) {
            $total += $counts[$category->value][$field] ?? 0;
        }

        return $total;
    }
}
