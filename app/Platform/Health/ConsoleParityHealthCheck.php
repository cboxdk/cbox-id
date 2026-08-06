<?php

declare(strict_types=1);

namespace App\Platform\Health;

use App\Platform\Console\ConsolePages;
use App\Platform\Console\ConsolePlane;
use Cbox\Id\Console\Contracts\HealthCheck;
use Cbox\Id\Console\ValueObjects\HealthResult;
use Illuminate\Support\Facades\Route;

/**
 * Whether the two console planes still offer the same thing.
 *
 * An administrator of the root account and an administrator of a tenant IdP are meant to
 * see the same console. The difference is what root holds IN ADDITION — provisioning
 * accounts and IdPs, and billing — not a different product. Both halves of that sentence
 * are measured here now: the pairs below say what must be the SAME, and
 * {@see ORGANIZATION_ONLY} says what must be the difference.
 *
 * They drifted anyway, because the console was built twice: thirteen capabilities with an
 * organization-plane component and an environment-plane component each, and no way to
 * notice when one grew a feature the other did not. Directories offered Google Workspace
 * and Entra on one plane and SCIM alone on the other. Connections offered domain
 * verification on one and not the other. A social sign-in page shipped on a plane whose
 * owner could not reach it.
 *
 * Grepping found all of that. Nothing was watching for it, so it accumulated for months.
 * This is the thing that watches.
 *
 * It watches two lists, because the console has two kinds of page. The thirteen core
 * capabilities are PAIRED BY HAND below — they were built twice under different names, so
 * inferring the pairing from the names would excuse the very case worth catching. Module
 * pages are read from {@see ConsolePages}, where a module states which planes it serves;
 * there the pairing is not in doubt and what needs measuring is whether the declaration
 * and the routes agree. All six modules declared a page and routed one plane, and this
 * check did not cover them — which is why the doctor said "13 capabilities reachable on
 * both planes" while an environment administrator had no analytics, compliance,
 * connectors, devices, risk or branding at all.
 */
class ConsoleParityHealthCheck implements HealthCheck
{
    /**
     * Capabilities that must exist on both planes, as route-name pairs.
     *
     * Listed rather than discovered: the two planes name their routes differently
     * (`connections` and `environment.connections`), and inferring the pairing from the
     * names would quietly excuse any capability whose names did not match — which is
     * exactly the case worth catching.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const PAIRS = [
        'Applications' => ['clients', 'environment.clients'],
        'Single sign-on' => ['connections', 'environment.connections'],
        'Social sign-in' => ['social-providers', 'environment.social-providers'],
        'Directories' => ['directories', 'environment.directories'],
        'Outbound sync' => ['provisioning', 'environment.provisioning'],
        'Roles' => ['roles', 'environment.roles'],
        'Access reviews' => ['governance', 'environment.governance'],
        'Conflict rules' => ['sod-policies', 'environment.sod-policies'],
        'Webhooks' => ['webhooks', 'environment.webhooks'],
        'Inline hooks' => ['hooks', 'environment.hooks'],
        // The last pair to be merged, and the one where the drift was a hole rather than
        // a missing feature: the organization plane's page was behind a step-up and the
        // environment plane's three were behind nothing, so the same rotate/grant/revoke
        // actions asked a tenant admin for a password and the administrator of every
        // tenant for none. Listed here so the two can never again be gated differently
        // without the doctor saying so.
        'Token vault' => ['vault', 'environment.vault'],
        'Activity log' => ['audit', 'environment.audit'],
        'Settings' => ['settings', 'environment.settings'],
        'Appearance' => ['appearance', 'environment.appearance'],
        // The pair whose drift was not a feature on one plane and a hole on the other: it
        // was a whole plane missing. The environment console could write the baseline and
        // the organization console could write nothing, while both sign-in doors enforced
        // the per-organization policy on every attempt. Listed here so the organization
        // half cannot quietly disappear again.
        'Sign-in rules' => ['auth-policy', 'environment.auth-policy'],
    ];

    /**
     * The capabilities that are the difference, and must therefore exist on exactly ONE
     * plane.
     *
     * The pairs above measure sameness. This measures the sentence in this class's own
     * docblock — "the difference is what root holds IN ADDITION" — which until now nothing
     * checked, so the difference could have drifted in either direction without a word:
     * an Identity platform page routed on the environment plane would offer a tenant
     * administrator somebody else's billing, and one that stopped being routed on the
     * organization plane would take an account's own projects away with nothing saying so.
     *
     * Named without a prefix on purpose. These are ordinary organization-console routes —
     * they came from a console of their own and are not one any more — and inventing a
     * prefix for them here is how a list like this ends up describing a plane that no
     * longer exists.
     *
     * @var list<string>
     */
    private const ORGANIZATION_ONLY = [
        'projects',
        'members',
        'api-keys',
        'environment-keys',
        'environment-domains',
        'activity',
        'billing',
        'organization-settings',
    ];

    public function __construct(private readonly ConsolePages $pages) {}

    public function run(): array
    {
        $missing = [];
        $components = $this->componentsByRouteName();

        foreach (self::ORGANIZATION_ONLY as $route) {
            if (! Route::has($route)) {
                $missing[] = $route.' — the organization console no longer serves it';
            }

            if (Route::has('environment.'.$route)) {
                $missing[] = $route.' — routed on the environment plane, which administers'
                    .' ONE environment on behalf of an account and has no business offering'
                    .' that account\'s own projects, keys or billing';
            }
        }

        foreach (self::PAIRS as $capability => [$organizationRoute, $environmentRoute]) {
            $onOrganization = Route::has($organizationRoute);
            $onEnvironment = Route::has($environmentRoute);

            if ($onOrganization !== $onEnvironment) {
                $missing[] = $capability.' — reachable only on the '
                    .($onOrganization ? 'organization' : 'environment').' plane';

                continue;

            }

            if (! $onOrganization) {
                continue;
            }

            // Both routes exist — but do they answer with the SAME page?
            //
            // Checking only that a route exists on each plane is the weaker half of the
            // question, and it is the half that was never the problem: every drifted
            // capability had a route on both planes. What it had was two components, and
            // they grew apart with nothing watching. Comparing the handlers is what turns
            // this from "is it reachable" into "is it the same product".
            $onOrganizationComponent = $components[$organizationRoute] ?? null;
            $onEnvironmentComponent = $components[$environmentRoute] ?? null;

            if ($onOrganizationComponent === null || $onEnvironmentComponent === null) {
                // Registered somewhere this scan cannot see — a module, or a shape it does
                // not parse. Reported rather than assumed equal, because a check that
                // guesses "fine" when it cannot tell is worse than one that says so.
                $missing[] = $capability.' — registration not readable from routes/web.php';

                continue;
            }

            if ($onOrganizationComponent !== $onEnvironmentComponent) {
                $missing[] = $capability.' — a separate component on each plane';
            }
        }

        $both = 0;
        $singlePlane = 0;

        foreach ($this->pages->all() as $page) {
            $onOrganization = Route::has($page->routeOn(ConsolePlane::Organization));
            $onEnvironment = Route::has($page->routeOn(ConsolePlane::Environment));

            foreach (ConsolePlane::cases() as $plane) {
                $reachable = $plane === ConsolePlane::Environment ? $onEnvironment : $onOrganization;

                // Declared for a plane and not routed there is the failure this whole
                // check exists for, and the modules are where it lived: all six declared
                // a page and routed the organization plane alone. The rail drops such a
                // page rather than rendering a link that 500s the console — which is the
                // right call there and precisely why it has to be loud HERE, or a module
                // is simply absent on a plane with nothing anywhere saying so.
                if ($page->serves($plane) && ! $reachable) {
                    $missing[] = $page->label.' — declared for the '.$plane->value
                        .' plane, but no route is registered there';
                }

                // The other direction: a page routed on a plane it never declared is not
                // in that rail, so it is reachable by URL and by nothing else — the
                // unlinked page the module route files already warn about.
                if (! $page->serves($plane) && $reachable) {
                    $missing[] = $page->label.' — routed on the '.$plane->value
                        .' plane but not declared there, so nothing links to it';
                }
            }

            $page->only === null ? $both++ : $singlePlane++;
        }

        if ($missing === []) {
            return [HealthResult::ok(
                'Console parity',
                sprintf(
                    '%d capabilities and %d module pages reachable on both planes (%d declared single-plane); %d Identity platform pages on the organization plane alone',
                    count(self::PAIRS),
                    $both,
                    $singlePlane,
                    count(self::ORGANIZATION_ONLY),
                ),
            )];
        }

        return [HealthResult::fail(
            'Console planes have drifted',
            'An administrator sees a different product depending which door they came through: '
            .implode('; ', $missing).'.',
        )];
    }

    /**
     * Which Volt component each route name serves, read from the registration itself.
     *
     * Not from the router: a Volt route carries its component inside a closure, so the
     * action holds nothing to compare and every route looks different from every other.
     * The registration in `routes/web.php` is the authoritative statement of what serves
     * what, and reading it is the same source-scan approach the index-width and
     * verified-email gates already use here.
     *
     * @return array<string, string> route name => component
     */
    private function componentsByRouteName(): array
    {
        $source = (string) file_get_contents(base_path('routes/web.php'));
        $components = [];

        // Volt::route('<path>', '<component>')…->name('<name>') — the chain may carry
        // middleware between the two, so this spans to the statement's end rather than
        // assuming one line.
        preg_match_all(
            "/Volt::route\(\s*'[^']*'\s*,\s*'([^']+)'\s*\)(.*?);/s",
            $source,
            $matches,
            PREG_SET_ORDER,
        );

        foreach ($matches as [, $component, $chain]) {
            if (preg_match("/->name\('([^']+)'\)/", $chain, $named) === 1) {
                $components[$named[1]] = $component;
            }
        }

        return $components;
    }
}
