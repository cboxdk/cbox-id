<?php

declare(strict_types=1);

namespace App\Platform\Health;

use Cbox\Id\Console\Contracts\HealthCheck;
use Cbox\Id\Console\ValueObjects\HealthResult;
use Illuminate\Support\Facades\Route;

/**
 * Whether the two console planes still offer the same thing.
 *
 * An administrator of the root account and an administrator of a tenant IdP are meant to
 * see the same console. The difference is what root holds IN ADDITION — provisioning
 * workspaces and IdPs, and billing — not a different product.
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
        'Activity log' => ['audit', 'environment.audit'],
        'Settings' => ['settings', 'environment.settings'],
        'Appearance' => ['appearance', 'environment.appearance'],
    ];

    public function run(): array
    {
        $missing = [];
        $components = $this->componentsByRouteName();

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

        if ($missing === []) {
            return [HealthResult::ok(
                'Console parity',
                count(self::PAIRS).' capabilities reachable on both planes',
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
