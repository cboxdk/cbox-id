<?php

declare(strict_types=1);

namespace Cbox\Id\Connectors\Http\Controllers;

use App\Http\Controllers\Console\ConsoleController;
use Cbox\Id\Connectors\Connections\ConnectionsOverview;
use Inertia\Response;

/**
 * CONNECTORS › CONNECTIONS — every live connector, across outbound SCIM, webhooks and SSO
 * federation.
 *
 * The scoping and the COPY move together. Without that, the page keeps saying "for this
 * organization" while listing the whole environment — and copy that is merely wrong on one
 * plane is how a reader learns to distrust the scoping they cannot see.
 */
final readonly class ConnectionsController extends ConsoleController
{
    public function __invoke(ConnectionsOverview $overview): Response
    {
        $this->scope->assertMayAdminister();

        // See CatalogController: from the scope, where null means "an environment
        // administrator has not chosen an organization" and nothing else.
        $organizationId = $this->scope->organizationId();

        $rows = [];

        foreach ($overview->forOrganization($organizationId) as $summary) {
            $rows[] = [
                'category' => $summary->category->label(),
                'name' => $summary->name,
                'status' => $summary->status,
                'target' => $summary->target,
                'health' => $summary->health?->verdict(),
            ];
        }

        return $this->page('connectors::connections', 'Connections', [
            'connections' => $rows,
            'wholeEnvironment' => $organizationId === null,
        ]);
    }
}
