<?php

declare(strict_types=1);

namespace Cbox\Id\RiskPlus\Http\Controllers;

use App\Http\Controllers\Console\ConsoleController;
use Cbox\Id\RiskPlus\Models\RiskEvent;
use Cbox\Id\RiskPlus\Queries\OrganizationRiskEvents;
use Inertia\Response;

/**
 * CONSOLE › RISK EVENTS — every flagged sign-in, newest first. One page, both planes.
 *
 * Risk events are recorded per ENVIRONMENT and carry an email rather than an organization,
 * so the two planes see genuinely different things here and both are right: an environment
 * administrator sees the whole feed, which is what a feed of credential stuffing against
 * the environment IS; an organization admin sees only the events whose address belongs to
 * one of their own members.
 *
 * The route's middleware does not gate this page by ROLE — it carries a session gate and
 * the feature flag, and neither is a role check. The navigation hides the area from a plain
 * member, which is styling; the URL is typeable, and this page lists flagged sign-ins with
 * the address in the clear.
 */
final readonly class RiskEventsController extends ConsoleController
{
    /** A screenful of the most recent. The feed is unbounded; a page is not. */
    private const LIMIT = 50;

    public function __invoke(OrganizationRiskEvents $forOrganization): Response
    {
        $this->scope->assertMayAdminister();

        /*
         * Confined to addresses belonging to the acting organization's members, through
         * {@see OrganizationRiskEvents} — which is where the reasoning for matching on the
         * address, and for fetching the membership ids through the contract rather than as a
         * subquery, is written down.
         *
         * THE SUBQUERY IS THE PART THAT MATTERED. `Membership` is `TenantOwned` and its scope
         * denies by default with no tenant in context, which nothing in this console ever
         * sets — so the filter matched nothing, the page said "No elevated risk events yet"
         * to an organization under credential stuffing, and it did that silently.
         *
         * Null is reachable only from the environment plane — the scope refuses rather than
         * answering null on the other one — and there it means the environment's own whole
         * feed, still bounded by RiskEvent's environment scope.
         */
        $organizationId = $this->scope->organizationId();

        $query = $organizationId === null
            ? RiskEvent::query()
            : $forOrganization->query($organizationId);

        $events = $query->latest('created_at')->limit(self::LIMIT)->get();

        return $this->page('risk-plus::events', 'Risk events', [
            'events' => $events->map(static fn (RiskEvent $event): array => [
                'id' => $event->id,
                'when' => $event->created_at->diffForHumans(),
                'action' => $event->action,
                'outcome' => str_replace('_', ' ', $event->outcome),
                'score' => (int) round($event->score),
                'reasons' => array_values(array_filter($event->reasons, 'is_string')),
            ])->all(),
            'wholeEnvironment' => $organizationId === null,
        ]);
    }
}
