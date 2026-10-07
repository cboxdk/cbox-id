<?php

declare(strict_types=1);

namespace App\Platform\Agents;

use App\Http\Props\Console\EnvironmentScopeProps;
use App\Platform\Actions\ActionDefinition;
use App\Platform\Actions\ActionPlane;
use App\Platform\Actions\ActionRegistry;
use App\Platform\Actions\AppManagementScopes;
use App\Platform\Actions\Danger;
use App\Platform\EnvironmentKeyScopes;
use Cbox\Id\Platform\Enums\EnvironmentApiScope;

/**
 * What each scope an agent can be given would LET IT DO, ranked by how much harm that is.
 *
 * READ FROM THE ACTION REGISTRY, not written down: a scope's risk is the highest
 * {@see Danger} among the actions that require it, so `apps:write` is Critical because
 * rotating an app's secret is, and a new action under a scope moves the scope's badge with
 * no edit here. The same registry is what an approval policy is checked against, which is
 * why a scope also says whether approvals can HOLD it ({@see self::held()}).
 *
 * A scope no action requires (a framework scope this deployment offers before any action
 * uses it) falls back to Write or Read by its name, and is marked as not held: an approval
 * policy is enforced by the action runner, and a request that never passes through it is
 * never held. The console says so beside it rather than letting a policy look wider than
 * it is.
 */
final readonly class AgentScopes
{
    /** What the resource part of a scope is called on screen. */
    private const array RESOURCES = [
        'organizations' => 'Organizations',
        'users' => 'Users',
        'members' => 'Members',
        'invitations' => 'Invitations',
        'roles' => 'Roles',
        'apps' => 'Applications',
        'apis' => 'APIs',
        'api_keys' => 'Member API keys',
        'support' => 'Support sessions',
        'keys' => 'Management keys',
        'webhooks' => 'Webhooks',
        'hooks' => 'Hooks',
        'log_streams' => 'Log streams',
        'events' => 'Events',
        'audit' => 'Audit log',
        'signin' => 'Sign-in',
        'frontend_keys' => 'Frontend keys',
        'saml_apps' => 'SAML apps',
        'branding' => 'Branding',
        'domains' => 'Domains',
    ];

    public function __construct(private ActionRegistry $registry) {}

    /**
     * Every offered scope, grouped by resource in the vocabulary's order, read before write
     * within each.
     *
     * @return list<AgentScope>
     */
    public function catalogue(): array
    {
        $actions = $this->actionsByScope();
        $scopes = [];

        foreach (EnvironmentKeyScopes::offered() as $index => $value) {
            $scopes[] = new AgentScope(
                value: $value,
                label: EnvironmentScopeProps::offered($value)->label,
                description: self::description($value),
                resource: self::resource($value),
                resourceLabel: self::RESOURCES[self::resource($value)] ?? ucfirst(str_replace('_', ' ', self::resource($value))),
                risk: $this->riskOf($value, $actions[$value] ?? []),
                held: isset($actions[$value]),
                actions: $actions[$value] ?? [],
                order: $index,
            );
        }

        // Stable within a resource: the vocabulary already pairs read with write, and the
        // risk only breaks a tie the vocabulary did not settle.
        $groups = [];

        foreach ($scopes as $scope) {
            $groups[$scope->resource][] = $scope;
        }

        $ordered = [];

        foreach ($groups as $group) {
            usort($group, static fn (AgentScope $a, AgentScope $b): int => [self::rank($a->risk), $a->order] <=> [self::rank($b->risk), $b->order]);
            array_push($ordered, ...$group);
        }

        return $ordered;
    }

    /**
     * Every scope's risk, read once — for a list that ranks many keys.
     *
     * @return array<string, Danger>
     */
    public function risks(): array
    {
        $actions = $this->actionsByScope();
        $risks = [];

        foreach ([...EnvironmentKeyScopes::offered(), ...array_keys($actions)] as $scope) {
            $risks[$scope] = $this->riskOf($scope, $actions[$scope] ?? []);
        }

        return $risks;
    }

    /**
     * The highest risk among `$scopes` — the badge on an agent's row.
     *
     * @param  list<string>  $scopes
     * @param  array<string, Danger>  $risks  from {@see self::risks()}
     */
    public static function highest(array $scopes, array $risks): Danger
    {
        $highest = Danger::Read;

        foreach ($scopes as $scope) {
            $risk = $risks[$scope] ?? (EnvironmentKeyScopes::writes($scope) ? Danger::Write : Danger::Read);

            if (self::rank($risk) > self::rank($highest)) {
                $highest = $risk;
            }
        }

        return $highest;
    }

    /**
     * The environment plane's actions, for naming specific ones an approval must hold.
     *
     * @return list<ActionDefinition>
     */
    public function actions(): array
    {
        $actions = $this->registry->forPlane(ActionPlane::Environment);
        usort($actions, static fn (ActionDefinition $a, ActionDefinition $b): int => [-self::rank($a->danger), $a->name] <=> [-self::rank($b->danger), $b->name]);

        return $actions;
    }

    public static function rank(Danger $danger): int
    {
        return match ($danger) {
            Danger::Read => 0,
            Danger::Write => 1,
            Danger::Destructive => 2,
            Danger::Critical => 3,
        };
    }

    /**
     * @param  list<ActionDefinition>  $actions
     */
    private function riskOf(string $scope, array $actions): Danger
    {
        if ($actions === []) {
            return EnvironmentKeyScopes::writes($scope) ? Danger::Write : Danger::Read;
        }

        $highest = Danger::Read;

        foreach ($actions as $action) {
            if (self::rank($action->danger) > self::rank($highest)) {
                $highest = $action->danger;
            }
        }

        return $highest;
    }

    /**
     * @return array<string, list<ActionDefinition>>
     */
    private function actionsByScope(): array
    {
        $byScope = [];

        foreach ($this->registry->forPlane(ActionPlane::Environment) as $action) {
            $byScope[$action->scope][] = $action;
        }

        return $byScope;
    }

    private static function resource(string $scope): string
    {
        $colon = strpos($scope, ':');

        return $colon === false ? $scope : substr($scope, 0, $colon);
    }

    private static function description(string $scope): ?string
    {
        return AppManagementScopes::APP_SCOPES[$scope]['description']
            ?? EnvironmentApiScope::tryFrom($scope)?->description();
    }
}
