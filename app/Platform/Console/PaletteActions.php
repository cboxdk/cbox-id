<?php

declare(strict_types=1);

namespace App\Platform\Console;

use App\Http\Props\Shell\NavAreaProps;
use App\Platform\Actions\ActionDefinition;
use App\Platform\Actions\ActionRegistry;
use App\Platform\Connect\ActionSnippets;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Lang;

/**
 * ⌘K's ACTIONS — "Create app", "Rotate app secret…" — read from the action registry, so a
 * new action with a console form is offered in the palette without anyone listing it.
 *
 * Each is a DEEP LINK, never a run: the palette takes a person to the form, where the
 * console's own step-up, confirmation and explanations are. "Create" lands on the create
 * page; a verb that needs a record first ("Rotate…") lands on the list to pick one from —
 * hence the ellipsis.
 *
 * OFFERED ONLY WHERE THE RAIL OFFERS THE PAGE. An action whose list page this console does
 * not show — an entitlement it lacks, a customer's narrowed console — is not offered,
 * because a palette entry that 404s is exactly the dead affordance that taught people to
 * stop pressing ⌘K the last time.
 */
final readonly class PaletteActions
{
    /** The verbs offered, and how each is said. */
    private const array VERBS = ['create' => 'Create', 'rotate' => 'Rotate', 'invite' => 'Invite'];

    /**
     * Where the derived name reads wrong.
     *
     * @var array<string, string>
     */
    private const array LABELS = [
        'access_reviews.create' => 'console.create_access_review_f04ebe8b',
        'account.api_keys.create' => 'console.create_account_api_key_afd6ec9c',
        'apis.create' => 'console.create_api_9d61ac02',
        'apps.create' => 'console.create_app_814eb4f6',
        'apps.secrets.rotate' => 'console.rotate_app_secret_f08360e3',
        'audit_logs.exports.create' => 'console.create_audit_log_export_443525b5',
        'audit_logs.schemas.create' => 'console.create_audit_log_schema_eba768d3',
        'directories.create' => 'console.create_directory_e6e7bc2e',
        'directories.token.rotate' => 'console.rotate_directory_token_378ec512',
        'environments.create' => 'console.create_environment_24b8cf62',
        'feature_flags.create' => 'console.create_feature_flag_6958ae43',
        'frontend_keys.create' => 'console.create_frontend_key_b0b9cfef',
        'hooks.create' => 'console.create_hook_866fbe24',
        'keys.create' => 'console.create_agent_key_fcab7a18',
        'keys.environment.create' => 'console.create_environment_key_b8b68a1a',
        'keys.rotate' => 'console.rotate_key_3094b236',
        'keys.workspace.create' => 'console.create_workspace_key_1329c0b9',
        'log_streams.create' => 'console.create_log_stream_b4bcbeb8',
        'organizations.create' => 'console.create_organization_f14e1b22',
        'organizations.portal_links.create' => 'console.create_organization_portal_link_d3f1698e',
        'permissions.create' => 'console.create_permission_3b39b0e2',
        'pipes.create' => 'console.create_pipe_e47abb38',
        'pipes.grants.create' => 'console.create_pipe_grant_306608e0',
        'platform.environments.create' => 'console.create_platform_environment_ac2413e5',
        'platform.operators.create' => 'console.create_platform_operator_8f20652f',
        'platform.organizations.create' => 'console.create_platform_organization_ea2254ab',
        'platform.workspaces.create' => 'console.create_platform_workspace_ccefdc7b',
        'projects.create' => 'console.create_project_fa67e4d0',
        'provisioning.targets.create' => 'console.create_provisioning_target_1908989f',
        'radar.rules.create' => 'console.create_radar_rule_95fbef1c',
        'roles.create' => 'console.create_role_221163d6',
        'saml_apps.create' => 'console.create_saml_app_6f128172',
        'sod_policies.create' => 'console.create_role_conflict_rule_4a91d976',
        'sso.connections.create' => 'console.create_sso_connection_b7e1820f',
        'sso.domains.create' => 'console.create_sso_domain_dec424a4',
        'team.invite' => 'console.invite_a_teammate_377bf819',
        'token_vault.grants.create' => 'console.create_token_vault_grant_8cf47369',
        'token_vault.secrets.create' => 'console.create_token_vault_secret_99c93722',
        'token_vault.secrets.rotate' => 'console.rotate_token_vault_secret_8fb8d982',
        'users.create' => 'console.create_user_f06da128',
        'webhooks.create' => 'console.create_webhook_4a2b33ad',
        'webhooks.secret.rotate' => 'console.rotate_webhook_signing_secret_440b7f20',
    ];

    public function __construct(
        private ActionRegistry $registry,
        private ActionSnippets $snippets,
        private Router $router,
    ) {}

    /**
     * @param  list<NavAreaProps>  $areas  The rail this person sees.
     * @return list<array{name: string, label: string, href: string}>
     */
    public function for(array $areas): array
    {
        $offered = [];

        foreach ($areas as $area) {
            foreach ($area->pages as $page) {
                $offered[$page->route] = true;
            }
        }

        $out = [];

        foreach ($this->registry->all() as $action) {
            $verb = self::VERBS[substr($action->name, (int) strrpos($action->name, '.') + 1)] ?? null;

            if ($verb === null) {
                continue;
            }

            foreach ($action->consoleRoutes as $consoleRoute) {
                $href = $this->href($consoleRoute, $offered, $verb === 'Create');

                if ($href !== null) {
                    $out[$action->name] = ['name' => $action->name, 'label' => $this->label($action), 'href' => $href];

                    break;
                }
            }
        }

        usort($out, static fn (array $a, array $b): int => strcmp($a['label'], $b['label']));

        return $out;
    }

    /**
     * The page the palette sends a person to for this write route, on THIS console — or
     * null when it is another console's, or its list is not on this person's rail.
     *
     * @param  array<string, true>  $offered
     */
    private function href(string $consoleRoute, array $offered, bool $creates): ?string
    {
        $host = $this->snippets->hostOf($consoleRoute);

        if ($host === null) {
            return null;
        }

        // The list the host belongs to: `environment.clients.show` → `environment.clients`.
        $list = isset($offered[$host]) ? $host : preg_replace('/\.(show|create|edit|settings|secrets|scopes)$/', '', $host);

        if (! is_string($list) || ! isset($offered[$list])) {
            return null;
        }

        // A create form of its own, when the list has one; the list otherwise — where a verb
        // that needs a record first picks it.
        foreach ($creates ? [$list.'.create', $host, $list] : [$list] as $candidate) {
            $route = $this->router->getRoutes()->getByName($candidate);

            if ($route instanceof Route && $route->parameterNames() === []) {
                return route($candidate);
            }
        }

        return null;
    }

    private function label(ActionDefinition $action): string
    {
        return isset(self::LABELS[$action->name])
            ? Lang::string(self::LABELS[$action->name])
            : __('console.open_action_action_6efdb0c4', ['action' => $action->name]);
    }
}
