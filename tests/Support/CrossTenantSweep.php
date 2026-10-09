<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\RiskDecision;
use App\Platform\Actions\ActionDefinition;
use App\Platform\Actions\ActionPlane;
use App\Platform\Actions\ActionRefused;
use App\Platform\Actions\ActionRegistry;
use App\Platform\Actions\ActionRunner;
use App\Platform\Actions\Approvals\ApprovalRequired;
use App\Platform\Actions\Principal\EnvironmentKeyPrincipal;
use App\Platform\Actions\Principal\Principal;
use App\Platform\RiskGuard;
use Cbox\Id\Directory\Models\DirectoryGroup;
use Cbox\Id\Governance\Models\CertificationItem;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\GenericEnvironment;
use Cbox\Id\OAuthServer\Contracts\BackchannelAuthentication;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\Enums\ClientType;
use Cbox\Id\OAuthServer\Models\SupportSession;
use Cbox\Id\OAuthServer\ValueObjects\NewClient;
use Cbox\Id\Organization\Contracts\CustomerApiKeys;
use Cbox\Id\Organization\ValueObjects\NewCustomerApiKey;
use Cbox\Id\Platform\Contracts\EnvironmentApiKeys;
use Cbox\Id\Platform\Contracts\ManagementScopes;
use Cbox\Id\Platform\Models\EnvironmentApiKey;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * THE CROSS-TENANT SWEEP'S FIXTURES: one "world" of every resource an environment action
 * names by id, built the way a customer builds it — through the actions themselves — so a
 * new resource is one line here rather than a hand-written test per action.
 *
 * A world is built twice or three times per test: the caller's own, and a foreign one (in
 * another environment, or in another organization of the same environment). The sweep then
 * walks the registry and sends every action an id from the foreign world, one path field at
 * a time and all of them at once, and expects the answer a stranger's id gets: 404.
 *
 * {@see self::slot()} is where a path field is mapped to a resource. A field it cannot map
 * fails the sweep by name, so an action added with a new kind of id cannot slip past it.
 */
final class CrossTenantSweep
{
    /**
     * A path field's resource, per plane, by the URL segment in front of it and the field's
     * name — or, where one segment means two things on a plane, by the action's own name
     * ({@see self::BY_ACTION}).
     *
     * @var array<string, array<string, string>>
     */
    private const array SLOTS = [
        'environment' => [
            'access-reviews:id' => 'access_review',
            'items:item_id' => 'access_review_item',
            'organizations:organization_id' => 'organization',
            'organizations:id' => 'organization',
            'api-keys:id' => 'customer_api_key',
            'apis:id' => 'api',
            'scopes:key' => 'api_scope',
            'agent-requests:request_id' => 'agent_request',
            'apps:id' => 'app',
            'secrets:secret_id' => 'app_secret',
            'exports:id' => 'audit_export',
            'schemas:action' => 'audit_schema',
            'directories:id' => 'directory',
            'frontend-keys:id' => 'frontend_key',
            'hooks:id' => 'hook',
            'invitations:invitation_id' => 'invitation',
            'keys:id' => 'environment_key',
            'log-streams:id' => 'log_stream',
            'members:user_id' => 'member',
            'roles:role_id' => 'role',
            'roles:id' => 'role',
            'domains:domain_id' => 'organization_domain',
            'permissions:id' => 'permission',
            'permissions:permission_id' => 'permission',
            'portal-links:id' => 'portal_link',
            'provisioning-targets:id' => 'provisioning_target',
            'saml-apps:id' => 'saml_app',
            'social-providers:id' => 'social_provider',
            'sod-policies:id' => 'sod_policy',
            'connections:id' => 'sso_connection',
            'domains:id' => 'sso_domain',
            'support-sessions:id' => 'support_session',
            'secrets:id' => 'vault_secret',
            'grants:client_id' => 'vault_grant',
            'users:id' => 'member',
            'environment-roles:role_id' => 'staff_role',
            'sessions:session_id' => 'session',
            'webhooks:id' => 'webhook',
            'rules:id' => 'radar_rule',
            'lists:id' => 'radar_list_entry',
            'decisions:id' => 'radar_decision',
        ],
        'workspace' => [
            'environments:environment_id' => 'environment',
            'keys:id' => 'workspace_key',
            'projects:id' => 'project',
            'members:id' => 'team_member',
            'invitations:id' => 'team_invitation',
        ],
        'account' => [
            'organizations:organization_id' => 'organization',
            'api-keys:key_id' => 'customer_api_key',
            'applications:client_id' => 'application',
            'devices:device_id' => 'device',
            'passkeys:passkey_id' => 'passkey',
            'sessions:session_id' => 'session',
            'social:provider' => 'social_provider',
        ],
        'platform' => [
            'environments:environment_id' => 'environment',
            'organizations:organization_id' => 'organization',
            'operators:operator_id' => 'operator',
            'workspaces:workspace_id' => 'workspace',
        ],
    ];

    /**
     * Where one segment names two things on a plane: action => field => slot.
     *
     * @var array<string, array<string, string>>
     */
    private const array BY_ACTION = [
        // `/keys/{id}` is a workspace key; under an environment it is that environment's.
        'keys.environment.revoke' => ['id' => 'environment_key'],
    ];

    /**
     * Slots that hold a NAME the caller chooses, not an id of something that exists apart
     * from its parent or from the caller: a scope key under an API, a grant named by the
     * client it is for under a vault secret, a social provider (`github`) or an app
     * (`client_id`) on the person's own account. The first two sit under a parent id the URL
     * already fences, and the parent is swept; the last two are keyed to the person acting,
     * so there is nobody else's to name. The name itself is never swapped for a foreign one.
     *
     * @var list<string>
     */
    public const array NAMES = ['api_scope', 'vault_grant', 'social_provider', 'application'];

    /**
     * Every scope an environment action asks for: the sweep's key holds all of them, so a
     * refusal is about the id and never about the key.
     *
     * @return list<string>
     */
    public static function environmentScopes(): array
    {
        $known = app(ManagementScopes::class);
        $scopes = [];

        foreach (app(ActionRegistry::class)->forPlane(ActionPlane::Environment) as $action) {
            if ($known->knows($action->scope)) {
                $scopes[$action->scope] = true;
            }
        }

        return array_keys($scopes);
    }

    public static function key(string $environmentId, string $name): EnvironmentApiKey
    {
        return app(EnvironmentApiKeys::class)->issue($environmentId, $name, self::environmentScopes())->key;
    }

    /**
     * Every action on $plane that names something by id in its URL.
     *
     * @return list<ActionDefinition>
     */
    public static function idTakingActions(ActionPlane $plane = ActionPlane::Environment): array
    {
        return array_values(array_filter(
            app(ActionRegistry::class)->forPlane($plane),
            static fn (ActionDefinition $action): bool => $action->input()->pathFields() !== [],
        ));
    }

    /**
     * The world slot a path field names, by the URL segment in front of it.
     */
    public static function slot(ActionDefinition $action, string $field): string
    {
        if (isset(self::BY_ACTION[$action->name][$field])) {
            return self::BY_ACTION[$action->name][$field];
        }

        if (! preg_match('#/([^/{}]+)/\{'.preg_quote($field, '#').'\}#', $action->path, $match)) {
            throw new \LogicException("{$action->name}: cannot find the segment in front of {{$field}}.");
        }

        $key = $match[1].':'.$field;

        $plane = $action->plane->value;

        if (! array_key_exists($key, self::SLOTS[$plane] ?? [])) {
            throw new \LogicException("{$action->name}: the sweep does not know what {{$field}} after /{$match[1]}/ names on the {$plane} plane. Map `{$key}` in ".self::class.'::SLOTS.');
        }

        return self::SLOTS[$plane][$key];
    }

    /**
     * Build a world of every resource in $environmentId, the organization-owned ones under one
     * new organization, through the actions a customer would use.
     *
     * @return array<string, string>
     */
    public static function world(string $environmentId, string $label): array
    {
        $context = app(EnvironmentContext::class);
        $previous = $context->current();
        $context->set(GenericEnvironment::of($environmentId));

        try {
            $principal = new EnvironmentKeyPrincipal(self::key($environmentId, 'Sweep '.$label));
            $run = static fn (string $action, array $input): array => self::payload(app(ActionRunner::class)->run(app(ActionRegistry::class)->named($action), $principal, $input)->payload);
            $slug = Str::lower($label).'-'.Str::lower(Str::random(5));
            $w = [];

            $w['organization'] = $run('organizations.create', ['name' => 'Sweep '.$label, 'slug' => 'sweep-'.$slug])['id'];
            $w['member'] = $run('users.create', ['email' => "member-{$slug}@sweep.example", 'name' => 'Member '.$label])['id'];
            $run('members.add', ['organization_id' => $w['organization'], 'user_id' => $w['member'], 'role' => 'member']);
            // Domain verification needs no plan, so the link mints on any environment.
            $w['portal_link'] = $run('organizations.portal_links.create', ['organization_id' => $w['organization'], 'intents' => ['domain_verification']])['id'];

            $w['permission'] = $run('permissions.create', ['name' => "sweep:{$slug}", 'organization_id' => $w['organization']])['id'];
            $w['role'] = $run('roles.create', ['name' => 'Sweep role '.$label, 'organization_id' => $w['organization'], 'permissions' => [$w['permission']]])['id'];
            $second = $run('roles.create', ['name' => 'Sweep checker '.$label, 'organization_id' => $w['organization']])['id'];
            $w['staff_role'] = $run('roles.create', ['name' => 'Sweep staff '.$label])['id'];
            $run('members.roles.grant', ['organization_id' => $w['organization'], 'user_id' => $w['member'], 'role_id' => $w['role']]);
            $run('users.environment_roles.grant', ['id' => $w['member'], 'role_id' => $w['staff_role']]);

            $w['invitation'] = $run('invitations.send', ['organization_id' => $w['organization'], 'email' => "invitee-{$slug}@sweep.example"])['id'];
            $w['organization_domain'] = $run('organizations.domains.add', ['organization_id' => $w['organization'], 'domain' => "org-{$slug}.sweep.example"])['id'];

            $w['api_scope'] = "sweep:{$slug}";
            $w['api'] = $run('apis.create', ['identifier' => "https://api-{$slug}.sweep.example", 'name' => 'Sweep API '.$label, 'scopes' => [['key' => $w['api_scope'], 'description' => 'Sweep']]])['id'];

            $app = $run('apps.create', ['name' => 'Sweep app '.$label, 'type' => 'service', 'organization_id' => $w['organization']]);
            $w['app'] = $app['id'];
            $w['app_client_id'] = $app['client_id'];
            $w['app_secret'] = $run('apps.secrets.list', ['id' => $w['app']])[0]['id'];

            $w['webhook'] = $run('webhooks.create', ['url' => "https://hooks-{$slug}.sweep.example/in", 'event_types' => ['user.created'], 'organization_id' => $w['organization']])['id'];
            $w['hook'] = $run('hooks.create', ['hook_point' => 'token_minting', 'url' => "https://hook-{$slug}.sweep.example/mint", 'organization_id' => $w['organization']])['id'];
            $w['log_stream'] = $run('log_streams.create', ['name' => 'Sweep SIEM '.$label, 'destination' => 'generic_json', 'endpoint_url' => "https://siem-{$slug}.sweep.example", 'auth' => 'none', 'organization_id' => $w['organization']])['id'];
            $w['directory'] = $run('directories.create', ['organization_id' => $w['organization'], 'name' => 'Sweep SCIM '.$label])['id'];
            $group = new DirectoryGroup;
            $group->forceFill(['directory_id' => $w['directory'], 'display_name' => 'Sweep group '.$label])->save();
            $w['directory_group'] = (string) $group->getKey();
            $w['provisioning_target'] = $run('provisioning.targets.create', ['organization_id' => $w['organization'], 'name' => 'Sweep target '.$label, 'base_url' => "https://scim-{$slug}.sweep.example/v2", 'auth_scheme' => 'bearer', 'secret' => 'tok-sweep'])['id'];
            $w['sod_policy'] = $run('sod_policies.create', ['organization_id' => $w['organization'], 'name' => 'Sweep SoD '.$label, 'role_ids' => [$w['role'], $second]])['id'];
            $w['sso_connection'] = $run('sso.connections.create', ['organization_id' => $w['organization'], 'name' => 'Sweep IdP '.$label, 'type' => 'saml', 'pending_idp' => true])['id'];
            $w['sso_domain'] = $run('sso.domains.create', ['organization_id' => $w['organization'], 'domain' => "sso-{$slug}.sweep.example"])['id'];
            $w['vault_secret'] = $run('token_vault.secrets.create', ['organization_id' => $w['organization'], 'name' => 'Sweep vault '.$label, 'provider' => 'github', 'secret' => 'ghp-sweep'])['id'];
            $w['vault_grant'] = 'sweep-agent-'.$slug;
            $run('token_vault.grants.create', ['id' => $w['vault_secret'], 'organization_id' => $w['organization'], 'client_id' => $w['vault_grant']]);
            $w['saml_app'] = $run('saml_apps.create', ['entity_id' => "https://sp-{$slug}.sweep.example", 'acs_url' => "https://sp-{$slug}.sweep.example/acs", 'organization_id' => $w['organization']])['id'];
            $w['social_provider'] = $run('signin.social.set', ['organization_id' => $w['organization'], 'provider' => 'github', 'client_id' => 'gh-'.$slug, 'client_secret' => 'gh-secret'])['id'];
            $w['frontend_key'] = $run('frontend_keys.create', ['name' => 'Sweep site '.$label, 'mode' => 'test', 'origins' => ["https://site-{$slug}.sweep.example"]])['id'];
            $w['environment_key'] = $run('keys.create', ['name' => 'Sweep worker '.$label, 'scopes' => ['users:read']])['id'];
            $w['audit_schema'] = "sweep_{$slug}.done";
            $run('audit_logs.schemas.create', ['action' => $w['audit_schema']]);
            $w['audit_export'] = $run('audit_logs.exports.create', ['organization_id' => $w['organization']])['id'];
            $w['radar_rule'] = $run('radar.rules.create', ['name' => 'Sweep rule '.$label, 'action' => 'challenge', 'conditions' => [['field' => 'country', 'operator' => 'in', 'values' => ['DK']]]])['id'];
            $w['radar_list_entry'] = $run('radar.lists.add', ['list' => 'deny', 'kind' => 'email_domain', 'value' => "radar-{$slug}.sweep.example"])['id'];

            $w['access_review'] = $run('access_reviews.create', ['name' => 'Sweep review '.$label, 'covers' => 'organization', 'organization_id' => $w['organization']])['id'];
            $w['access_review_item'] = (string) CertificationItem::query()->where('campaign_id', $w['access_review'])->value('id');

            // Not created by an action anybody calls with a key: a person signing in, a
            // member minting their own app key, an agent asking a person to approve.
            $run('apps.settings.api_key_prefix', ['id' => $w['app'], 'prefix' => 'sw'.substr((string) preg_replace('/[^a-z0-9]/', '', $slug), 0, 12).'_test']);
            $w['session'] = app(SessionManager::class)->start($w['member'], $w['organization'], ['pwd'])->id;
            $w['customer_api_key'] = (string) app(CustomerApiKeys::class)->issue(new NewCustomerApiKey(
                organizationId: $w['organization'],
                userId: $w['member'],
                clientId: $w['app_client_id'],
                name: 'Sweep key '.$label,
            ))->key->id;
            $w['agent_request'] = self::agentRequest($w['member']);
            $w['support_session'] = self::supportSession($w['member'], $w['organization'], $w['app_client_id']);
            // A Radar decision is written by a sign-in attempt, not by an action: one scored
            // attempt on this environment's host.
            app(RiskGuard::class)->assess(Request::create('/login', 'POST', server: ['REMOTE_ADDR' => '198.51.100.9']), 'login', "member-{$slug}@sweep.example");
            $w['radar_decision'] = (string) RiskDecision::query()->where('environment_id', $environmentId)->orderByDesc('id')->value('id');

            return $w;
        } finally {
            $previous === null ? $context->set(GenericEnvironment::of('env_test')) : $context->set($previous);
        }
    }

    /**
     * What a door answers for an action run by $principal with $input: the HTTP status the
     * REST door would send for the outcome. A held approval is reported as 202.
     *
     * @param  array<string, mixed>  $input
     */
    public static function status(ActionDefinition $action, Principal $principal, array $input): int
    {
        try {
            $result = app(ActionRunner::class)->run($action, $principal, $input);

            return $result->status ?? ($result->payload === null ? 204 : $action->status);
        } catch (ActionRefused $refused) {
            return $refused->status;
        } catch (ApprovalRequired) {
            return 202;
        } catch (AuthorizationException) {
            return 403;
        } catch (ValidationException) {
            return 422;
        } catch (ModelNotFoundException) {
            return 404;
        } catch (HttpExceptionInterface $http) {
            return $http->getStatusCode();
        } catch (Throwable $other) {
            return 500;
        }
    }

    /**
     * @return array<mixed>
     */
    private static function payload(?array $payload): array
    {
        return $payload ?? [];
    }

    /** A support session an app's backend opened to act as the member — started, not ended. */
    private static function supportSession(string $userId, string $organizationId, string $clientId): string
    {
        $session = new SupportSession;
        $session->forceFill([
            'actor_id' => 'sweep-support-agent',
            'actor_kind' => 'staff',
            'target_user_id' => $userId,
            'organization_id' => $organizationId,
            'client_id' => $clientId,
            'scopes' => ['openid'],
            'reason' => 'Sweep',
            'expires_at' => now()->addHour(),
        ])->save();

        return (string) $session->getKey();
    }

    /** A pending CIBA request: an agent asking the member to approve something. */
    private static function agentRequest(string $subjectId): string
    {
        $client = app(ClientRegistry::class)->register(new NewClient(name: 'Sweep agent', type: ClientType::Confidential, redirectUris: [], scopes: ['openid']));

        return app(BackchannelAuthentication::class)->request($client->client, ['openid'], $subjectId, bindingMessage: 'Sweep')->requestId;
    }
}
