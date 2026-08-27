<?php

declare(strict_types=1);

namespace Cbox\Id\Connectors\Http\Controllers;

use App\Http\Controllers\Console\ConsoleController;
use Cbox\Id\Connectors\Catalog\ConnectorCatalog;
use Cbox\Id\Connectors\Connections\ConnectionsOverview;
use Inertia\Response;

/**
 * CONNECTORS › CATALOG — the connector types this platform speaks.
 *
 * Read-only, and one page on both planes. The route's middleware carries a session gate
 * (`platform.auth` on one plane, `env.admin` on the other) and the feature flag, and
 * neither of those is a ROLE check — the navigation hides the area from a plain member,
 * which is styling rather than authorization, and the URL is typeable. So the role gate is
 * here.
 */
final readonly class CatalogController extends ConsoleController
{
    public function __invoke(ConnectorCatalog $catalog, ConnectionsOverview $overview): Response
    {
        $this->scope->assertMayAdminister();

        /*
         * FROM THE SCOPE, not from console-kit's `CurrentContext`. That helper answers null
         * whenever no SUBJECT is signed in — which on the environment plane is always — so
         * an environment administrator would silently have been handed the environment-wide
         * branch even after choosing an organization to act on. Here null means one thing:
         * an environment administrator has not chosen yet.
         */
        $organizationId = $this->scope->organizationId();

        $active = [];

        foreach ($overview->forOrganization($organizationId) as $summary) {
            $key = $summary->category->value;
            $active[$key] = ($active[$key] ?? 0) + ($summary->isActive() ? 1 : 0);
        }

        $types = [];

        foreach ($catalog->all() as $descriptor) {
            $types[] = [
                'key' => $descriptor->key,
                'name' => $descriptor->name,
                'category' => $descriptor->category->label(),
                'description' => $descriptor->description,
                'direction' => $descriptor->category->isOutbound() ? 'Outbound' : 'Inbound',
                // Some connector types are configured on their own page rather than
                // enumerated here, and saying "0 active" for those would be a lie about a
                // thing that has no count.
                'enumerable' => $descriptor->enumerable,
                'active' => $descriptor->enumerable ? ($active[$descriptor->category->value] ?? 0) : null,
            ];
        }

        return $this->page('connectors::catalog', 'Catalog', ['types' => $types]);
    }
}
