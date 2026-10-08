<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Http\Props\Console\AgentRowProps;
use App\Http\Props\Shared\HelpProps;
use App\Mcp\McpProtectedResources;
use App\Platform\Actions\ActionDefinition;
use App\Platform\Actions\Danger;
use App\Platform\Agents\ActionApprovalInbox;
use App\Platform\Agents\AgentRoster;
use App\Platform\Agents\AgentScope;
use App\Platform\Agents\AgentScopes;
use App\Platform\Console\Vocabulary;
use App\Platform\Enums\KeyLifetime;
use App\Platform\EnvironmentAdminAuth;
use App\Platform\Help\HelpTopic;
use App\Platform\OAuth\RootDelegatedAccess;
use App\Platform\OAuth\RootMcpOAuth;
use Cbox\Id\OAuthServer\Contracts\ProtectedResources;
use Cbox\Id\OAuthServer\ValueObjects\ProtectedResource;
use Cbox\Id\Platform\Models\EnvironmentApiKey;
use Illuminate\Http\Request;
use Inertia\Response;

/**
 * AI AGENTS › AGENTS and › CONNECT — where a person hands an AI agent (Claude Code, Cursor,
 * an internal bot) access to this environment, and sees every agent that has it.
 *
 * AN AGENT IS A MANAGEMENT KEY, and this is the environment console's one page for them.
 * The management-keys tab that used to sit under Developers › API keys listed the same
 * keys with fewer facts and no way to rotate one or to say which of its actions wait for
 * approval, so two pages would have disagreed about the same credential; that URL now
 * lands here ({@see EnvironmentKeyController::index()}). A backend worker's key is listed
 * here too — it is software acting on the environment, which is all "agent" means.
 *
 * NO WRITE PATH OF ITS OWN. Minting, rotating and revoking are the `keys.create`,
 * `keys.rotate` and `keys.revoke` actions — the ones the API and MCP run — reached through
 * {@see EnvironmentKeyController}, which already holds the step-up and the reachability
 * check for them. These pages only read.
 */
final readonly class AgentController extends ConsoleController
{
    /** The presets the create page knows; anything else in the URL is ignored. */
    private const array PRESETS = ['read-only', 'support', 'full-admin'];

    public function index(Request $request, AgentRoster $roster, ActionApprovalInbox $inbox): Response
    {
        $auth = $this->environmentAdmin();
        $environmentId = (string) $auth->environmentId();
        $showAll = $request->boolean('all');

        $roster = $roster->for(
            $environmentId,
            $showAll,
            fn (EnvironmentApiKey $key): string => route('environment.keys.rotate', $key->id),
            fn (EnvironmentApiKey $key): string => route('environment.keys.destroy', $key->id),
        );

        $me = $auth->subjectId();

        return $this->page('environment/agents/index', Vocabulary::AGENTS, [
            'help' => HelpProps::for(HelpTopic::Agents),
            'agents' => array_map(static fn (AgentRowProps $row): array => $row->toArray(), $roster['rows']),
            'inactiveCount' => $roster['inactive'],
            'showAll' => $showAll,
            'environmentId' => $environmentId,
            'waitingForYou' => $me === null ? 0 : $inbox->waitingFor($me, $environmentId),
            'urls' => [
                'create' => route('environment.agents.create'),
                'connect' => route('environment.agent-connect'),
                'approvals' => route('environment.approvals'),
                'index' => route('environment.agents'),
            ],
        ]);
    }

    /**
     * The create flow, as a page of its own rather than a dialog: it is behind the step-up
     * (a key is a credential, shown once), it is linked to with a preset from Connect, and
     * once the key exists it becomes the place the key is shown and wired up.
     */
    public function create(Request $request, AgentScopes $scopes): Response
    {
        $auth = $this->environmentAdmin();
        $preset = $request->string('preset')->toString();

        return $this->page('environment/agents/create', 'New agent', [
            'help' => HelpProps::for(HelpTopic::Agents),
            'environmentId' => (string) $auth->environmentId(),
            'scopes' => array_map(static fn (AgentScope $scope): array => $scope->toArray(), $scopes->catalogue()),
            'actions' => array_map(static fn (ActionDefinition $action): array => [
                'name' => $action->name,
                'summary' => $action->summary,
                'danger' => $action->danger->value,
            ], $scopes->actions()),
            'approvalLevels' => [
                ['value' => 'none', 'label' => 'None', 'hint' => 'The agent acts on its own, within its scopes.'],
                ['value' => Danger::Critical->value, 'label' => 'Critical actions', 'hint' => 'Minting keys, rotating secrets, changing how people sign in.'],
                ['value' => Danger::Destructive->value, 'label' => 'Destructive and above', 'hint' => 'Also anything that deletes or revokes.'],
                ['value' => Danger::Write->value, 'label' => 'Every change', 'hint' => 'The agent reads freely and asks before it writes anything.'],
            ],
            // Agents are usually for a piece of work, so the default is a lifetime rather
            // than forever — and the console offers the presets an agent is minted with.
            'lifetimes' => array_map(
                static fn (KeyLifetime $lifetime): array => ['value' => $lifetime->value, 'label' => $lifetime->label()],
                [KeyLifetime::Days30, KeyLifetime::Days90, KeyLifetime::Custom, KeyLifetime::Never],
            ),
            'defaults' => [
                'name' => mb_substr(trim($request->string('name')->toString()), 0, 120),
                'preset' => in_array($preset, self::PRESETS, true) ? $preset : null,
                'lifetime' => KeyLifetime::Days90->value,
            ],
            'mcpUrl' => $this->mcp()?->identifier,
            'urls' => [
                'store' => route('environment.keys.store'),
                'index' => route('environment.agents'),
                'connect' => route('environment.agent-connect'),
            ],
        ]);
    }

    /**
     * How to point an MCP client at this environment: the URL, and the snippet for each
     * client people actually use, with a key minted for it one click away.
     */
    public function connect(): Response
    {
        $this->environmentAdmin();

        $mcp = $this->mcp();
        $origin = $mcp === null ? rtrim(url('/'), '/') : self::origin($mcp->identifier);

        return $this->page('environment/agents/connect', 'Connect', [
            'help' => HelpProps::for(HelpTopic::ConnectAgent),
            'mcpUrl' => $mcp->identifier ?? $origin.McpProtectedResources::PATH,
            'metadataUrl' => $mcp?->metadataUrl() ?? $origin.ProtectedResource::WELL_KNOWN.McpProtectedResources::PATH,
            'restBaseUrl' => $origin.'/api/v1',
            'openApiUrl' => $origin.'/api/v1/environment/openapi.yaml',
            /*
             * SIGNING IN WITH AN ACCOUNT, rather than pasting a key, is what an MCP client
             * does when the resource lets a self-registered client be issued a token for
             * it ({@see McpProtectedResources::mcp()}, `api.mcp.dynamic_clients`). Here it
             * signs in a person of THIS environment — one of its organizations' own
             * administrators — so the page says so; the people who run the workspace sign
             * in at the platform root instead (below).
             */
            'oauthAvailable' => $mcp !== null && $mcp->dynamicClients,
            /*
             * ONE CONNECTION FOR THE WHOLE WORKSPACE: the platform root's `/mcp`, where the
             * workspace's own people sign in — the workspace, every environment of it they
             * administer (named per call), their account and, for an operator, the
             * deployment ({@see RootDelegatedAccess}). Null where there is no root apart
             * from this host to point at.
             */
            'workspace' => $this->workspaceConnection(),
            'urls' => [
                'createAgent' => route('environment.agents.create', ['preset' => 'read-only', 'name' => 'Claude Code']),
                'agents' => route('environment.agents'),
            ],
        ]);
    }

    /**
     * Holding the environment console IS the authority here: its session resolves only for
     * a workspace member who may manage environments and reaches this one.
     */
    private function environmentAdmin(): EnvironmentAdminAuth
    {
        $auth = app(EnvironmentAdminAuth::class);

        abort_if($auth->membership() === null || $auth->environmentId() === null, 403);

        return $auth;
    }

    /**
     * The platform root's `/mcp` and its issuer — what `cbox login` and an agent holding a
     * workspace key point at — or null when no root resource can be named.
     *
     * `oauth` says whether an MCP client may sign the person in there itself — the command
     * that needs nothing but the URL, offered first when it works ({@see RootMcpOAuth}).
     *
     * @return array{mcpUrl: string, issuer: string, restBaseUrl: string, oauth: bool}|null
     */
    private function workspaceConnection(): ?array
    {
        $resource = app(RootDelegatedAccess::class)->resource();

        if ($resource === null) {
            return null;
        }

        $origin = self::origin($resource->identifier);

        return [
            'mcpUrl' => $resource->identifier,
            'issuer' => $origin,
            'restBaseUrl' => $origin.'/api/v1',
            'oauth' => RootMcpOAuth::enabled() && $resource->dynamicClients,
        ];
    }

    /** This environment's `/mcp` resource, as the RFC 9728 document describes it. */
    private function mcp(): ?ProtectedResource
    {
        return app(ProtectedResources::class)->forMetadataPath(ProtectedResource::WELL_KNOWN.McpProtectedResources::PATH);
    }

    private static function origin(string $url): string
    {
        $parts = parse_url($url);
        $scheme = is_array($parts) && isset($parts['scheme']) ? $parts['scheme'] : 'https';
        $host = is_array($parts) && isset($parts['host']) ? $parts['host'] : '';
        $port = is_array($parts) && isset($parts['port']) ? ':'.$parts['port'] : '';

        return $scheme.'://'.$host.$port;
    }
}
