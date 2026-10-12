<?php

declare(strict_types=1);

namespace Cbox\Id\Connectors\Http\Controllers;

use App\Http\Controllers\Console\ConsoleController;
use App\Platform\Console\ConsolePlane;
use App\Platform\Console\ConsoleScope;
use App\Platform\Console\OrganizationFilter;
use App\Platform\Help\DocsLinks;
use Cbox\Id\Connectors\ValueObjects\ConnectionSummary;
use Cbox\Id\Connectors\ValueObjects\ConnectorDescriptor;

/**
 * WHERE A CONNECTOR LEADS — the links both Connectors pages offer, resolved for the plane
 * and the organization the page is on.
 *
 * Every link here points at a page that already exists for its type, so the rule is the
 * crawl's: a page must not offer what it refuses. Hence three checks before an href is
 * handed out rather than one —
 *
 *  - the page is SERVED on this console ({@see ConsoleScope::serves()}):
 *    the route exists on this plane and a customer's console has not withheld it, so the
 *    environment-only SAML apps and Pipes are simply absent from an organization console;
 *  - the ROW is one its own page will open ({@see ConnectorDescriptor::$opens}): an
 *    organization console sees the environment's log streams and provisioning connections
 *    reaching it, and their pages refuse it, so those rows are listed without a link;
 *  - an organization the page is about TRAVELS with the link — `?organization=` on a list
 *    that narrows by it, and on a create form, which then opens locked to it.
 */
abstract readonly class ConnectorsPage extends ConsoleController
{
    /** Whether this console offers the type at all. */
    protected function offers(ConnectorDescriptor $descriptor): bool
    {
        return $this->scope->serves($descriptor->indexRoute);
    }

    /** The type's list, narrowed to the organization when the list can be. */
    protected function manageHref(ConnectorDescriptor $descriptor, ?string $organizationId): string
    {
        return $this->withQuery(
            $this->url($descriptor->indexRoute),
            $descriptor->organizationFilter ? $this->organizationQuery($descriptor->indexRoute, $organizationId) : [],
        );
    }

    /** Where a new one is started, with one of the type's options already chosen. */
    protected function setupHref(ConnectorDescriptor $descriptor, ?string $organizationId, ?string $option = null): ?string
    {
        if (! $this->scope->serves($descriptor->createRoute)) {
            return null;
        }

        $query = $this->organizationQuery($descriptor->createRoute, $organizationId);

        if ($option !== null && $descriptor->optionParameter !== null) {
            $query[$descriptor->optionParameter] = $option;
        }

        return $this->withQuery($this->url($descriptor->createRoute), $query);
    }

    /** One connection's own page, or null where that page would refuse it. */
    protected function rowHref(ConnectorDescriptor $descriptor, ConnectionSummary $row): ?string
    {
        $environment = $this->scope->plane() === ConsolePlane::Environment;
        $own = $environment ? null : $this->scope->requireOrganizationId();

        $opens = match ($descriptor->opens) {
            ConnectorDescriptor::OPENS_OWN => $environment || $row->organizationId === $own,
            ConnectorDescriptor::OPENS_PLANE => $row->organizationId === $own,
            default => true,
        };

        if (! $opens) {
            return null;
        }

        if ($descriptor->showRoute !== null) {
            return $this->scope->serves($descriptor->showRoute) ? $this->url($descriptor->showRoute, $row->id) : null;
        }

        if ($descriptor->rowParameter !== null && $this->offers($descriptor)) {
            return $this->withQuery($this->url($descriptor->indexRoute), [$descriptor->rowParameter => $row->id]);
        }

        return null;
    }

    protected function docsHref(ConnectorDescriptor $descriptor): ?string
    {
        $docs = app(DocsLinks::class);

        if ($descriptor->helpTopic !== null) {
            return $docs->url($descriptor->helpTopic);
        }

        return $descriptor->docsPath === null ? null : $docs->page($descriptor->docsPath);
    }

    /**
     * `?organization=` for a link about one organization — only on the environment plane,
     * and only where the destination is not already that organization's own address.
     *
     * @return array<string, string>
     */
    private function organizationQuery(string $route, ?string $organizationId): array
    {
        if ($organizationId === null || $this->scope->plane() !== ConsolePlane::Environment) {
            return [];
        }

        return str_starts_with($this->routeName($route), 'environment.organizations.')
            ? []
            : [OrganizationFilter::PARAMETER => $organizationId];
    }

    /** @param  array<string, string>  $query */
    protected function withQuery(string $url, array $query): string
    {
        return $query === [] ? $url : $url.(str_contains($url, '?') ? '&' : '?').http_build_query($query);
    }
}
