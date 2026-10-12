<?php

declare(strict_types=1);

namespace Cbox\Id\Connectors\Http\Controllers;

use App\Http\Props\Shared\HelpProps;
use App\Platform\Console\ConsolePlane;
use App\Platform\Help\HelpTopic;
use Cbox\Id\Connectors\Catalog\ConnectorCatalog;
use Cbox\Id\Connectors\Connections\ConnectionsOverview;
use Cbox\Id\Connectors\ValueObjects\ConnectionSummary;
use Inertia\Response;

/**
 * CONNECTORS › CATALOG — every kind of integration this platform has, how many of each are
 * live, and the next step for each: set one up, or manage the ones there are.
 *
 * It was four cards that did nothing. No card linked anywhere, Directory Sync said "Managed in
 * module" where a count belonged, and the footnote sent the reader to "the Directory pages"
 * without a link. An administrator who opened Connectors to connect something could read
 * about four things and do none of them. Every card now has a primary action, a live count
 * and a guide; the kinds a form can take (a provider, an HR system, a SIEM) are shortcuts
 * that open that form with the kind chosen. The pages they lead to do every write.
 *
 * Read-only, and one page on both planes. The route's middleware carries a session gate and
 * the feature flag, neither of which is a ROLE check — the rail hides the area from a plain
 * member, which is styling rather than authorization, and the URL is typeable. So the role
 * gate is here.
 */
final readonly class CatalogController extends ConnectorsPage
{
    /** How many of a type's options the card shows before "and n more". */
    private const int SHORTCUTS = 6;

    public function __invoke(ConnectorCatalog $catalog, ConnectionsOverview $overview): Response
    {
        $this->scope->assertMayAdminister();

        /*
         * FROM THE SCOPE, not from console-kit's `CurrentContext`, which answers null whenever
         * no SUBJECT is signed in — always, on the environment plane. Here null means one
         * thing: an environment administrator has not chosen an organization.
         */
        $organizationId = $this->scope->organizationId();

        /** @var array<string, list<ConnectionSummary>> $byCategory */
        $byCategory = [];

        foreach ($overview->forOrganization($organizationId) as $summary) {
            $byCategory[$summary->category->value][] = $summary;
        }

        $types = [];
        $live = 0;
        $failing = 0;

        foreach ($catalog->all() as $descriptor) {
            if (! $this->offers($descriptor)) {
                continue;
            }

            $rows = $byCategory[$descriptor->category->value] ?? [];
            $count = count($rows);
            $broken = count(array_filter($rows, static fn (ConnectionSummary $row): bool => $row->needsAttention()));
            $live += $count;
            $failing += $broken;

            $options = [];

            foreach ($descriptor->options as $key => $label) {
                $href = $this->setupHref($descriptor, $organizationId, $key);

                if ($href !== null) {
                    $options[] = ['key' => $key, 'label' => $label, 'href' => $href];
                }
            }

            $types[] = [
                'key' => $descriptor->key,
                'name' => $descriptor->name,
                'summary' => $descriptor->summary,
                'group' => $descriptor->group,
                'direction' => $descriptor->category->isOutbound() ? 'Outbound' : 'Inbound',
                'count' => $count,
                'active' => count(array_filter($rows, static fn (ConnectionSummary $row): bool => $row->isActive())),
                'failing' => $broken,
                'setupHref' => $this->setupHref($descriptor, $organizationId),
                // The list itself, whatever the count: it is where a type's rows are managed,
                // and its own empty state starts the first one.
                'manageHref' => $this->manageHref($descriptor, $organizationId),
                // The failing ones, on the unified list — one click from "1 failing" to WHICH.
                'failingHref' => $broken > 0
                    ? $this->withQuery($this->url('connectors.connections'), ['type' => $descriptor->key, 'status' => 'failing'])
                    : null,
                'docsHref' => $this->docsHref($descriptor),
                'options' => array_slice($options, 0, self::SHORTCUTS),
                'moreOptions' => max(0, count($options) - self::SHORTCUTS),
            ];
        }

        return $this->page('connectors::catalog', 'Catalog', [
            'help' => HelpProps::for(HelpTopic::Connectors),
            'groups' => $catalog->groups(),
            'types' => $types,
            'totals' => ['connections' => $live, 'failing' => $failing],
            'connectionsHref' => $this->url('connectors.connections'),
            'failingHref' => $failing > 0 ? $this->withQuery($this->url('connectors.connections'), ['status' => 'failing']) : null,
            'wholeEnvironment' => $organizationId === null && $this->scope->plane() === ConsolePlane::Environment,
        ]);
    }
}
