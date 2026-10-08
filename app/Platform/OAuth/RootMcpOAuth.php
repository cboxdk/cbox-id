<?php

declare(strict_types=1);

namespace App\Platform\OAuth;

use App\Http\Controllers\OAuthConsentController;
use App\Platform\OrganizationActivity;
use App\Platform\PlaneResolver;
use Cbox\Id\Kernel\Audit\Contracts\AuditLog;
use Cbox\Id\Kernel\Audit\Enums\ActorType;
use Cbox\Id\Kernel\Audit\ValueObjects\AuditEvent;
use Cbox\Id\OAuthServer\Models\Client;
use Cbox\Id\OAuthServer\ValueObjects\ProtectedResource;
use Illuminate\Http\Request;

/**
 * MCP CLIENTS SIGNING A PERSON IN AT THE PLATFORM ROOT — the rules that hold them to the
 * one thing they are there for.
 *
 * The root's `/mcp` is one connection for a workspace's whole team ({@see RootDelegatedAccess}),
 * and since `api.mcp.root_oauth` an MCP client reaches it the way it reaches any other MCP
 * server: it is answered `401` with a pointer to the resource's metadata, registers itself
 * (RFC 7591, `mcp` profile) or presents a client ID metadata document, and sends the person
 * through `/oauth/authorize`. The root is still an identity provider for nobody's APP, and
 * these are the walls that keep it so — each enforced where the framework decides it, so a
 * path around one is a path around the framework's own rule:
 *
 *  - WHO MAY SIGN IN: a subject of the root with standing there — on a workspace's team or
 *    an operator ({@see admits()}). Anyone else is told so on the page, before a consent
 *    screen for a connection that could do nothing ({@see OAuthConsentController}).
 *  - WHAT FOR: the root's `/mcp` and nothing else. Every token such a client is issued is
 *    audienced there, a missing `resource` defaulting to it and any other refused
 *    ({@see RootMcpAudiences}) — never the issuer, never a registered API of the root
 *    environment, never somebody else's resource server.
 *  - WITH WHAT: that resource's scopes and `offline_access` ({@see RootMcpSelfRegisteredScopes}).
 *    No `openid`, so no ID token; no `profile` or `email`, because the root serves no
 *    UserInfo for them to mean anything at.
 *  - RECORDED: each registration (with the address it came from, which the framework's own
 *    `app.created` entry does not carry) and each consent, in the person's workspace trail.
 *
 * Inert on a single-tenant install, where the one host IS an identity provider for every
 * client it holds, and on a tenant's host, which is one for its own.
 */
final readonly class RootMcpOAuth
{
    /** The audit action for a client registering itself at the root. */
    public const string REGISTERED = 'mcp.client_registered';

    /** The audit action for a person allowing an MCP client at the root. */
    public const string AUTHORIZED = 'mcp.client_authorized';

    public function __construct(
        private PlaneResolver $planes,
        private RootDelegatedAccess $root,
        private OrganizationActivity $activity,
        private AuditLog $audit,
    ) {}

    /**
     * The switch: `api.mcp.root_oauth`, and self-registered clients open to `/mcp` at all
     * (`api.mcp.dynamic_clients`) — with those closed there is nothing at the root for an
     * MCP client to be signed in to.
     */
    public static function enabled(): bool
    {
        return config('api.mcp.root_oauth', true) === true
            && config('api.mcp.dynamic_clients', true) === true;
    }

    /**
     * Whether `$client` is held to these rules: a client that registered ITSELF — RFC 7591,
     * or a metadata document — being authorized by the platform root of a multi-tenant
     * deployment. Asked of the CONTEXT, not the host: narrowing is never the unsafe answer,
     * and the token endpoint and the audience resolver have no host of their own to ask.
     */
    public function governs(Client $client): bool
    {
        return $client->isDynamicallyRegistered() && $this->planes->onAccountPlane();
    }

    /** The root's `/mcp` — the only audience such a client is ever issued a token for. */
    public function resource(): ?ProtectedResource
    {
        return $this->root->resource();
    }

    /** Whether a subject of the root may finish signing an MCP client in there. */
    public function admits(string $subjectId): bool
    {
        return $this->root->holdsStanding($subjectId);
    }

    /**
     * Record a client registering itself at the root, on the system trail.
     *
     * The framework writes `app.created` for it already, as the system and with nothing
     * about where it came from. Open registration is the one write on the root anybody can
     * make, so the address it came from and the hosts it will send people to are the facts
     * worth having when someone asks why the table grew.
     */
    public function recordRegistration(Client $client, Request $request): void
    {
        $this->audit->record(new AuditEvent(
            action: self::REGISTERED,
            actorType: ActorType::Service,
            actorId: $client->client_id,
            targetType: 'client',
            targetId: $client->client_id,
            context: [
                'name' => $client->name,
                'redirect_hosts' => array_values(array_unique(array_filter(array_map(
                    static fn (string $uri): ?string => is_string($host = parse_url($uri, PHP_URL_HOST)) ? $host : null,
                    $client->redirect_uris,
                )))),
                'resource' => $this->resource()?->identifier,
            ],
            ip: $request->ip(),
        ));
    }

    /**
     * Record a person allowing an MCP client at the root — in their workspace's trail, where
     * the team sees it, or on the system trail for an operator who is on no team.
     *
     * @param  list<string>  $scopes
     */
    public function recordConsent(Client $client, string $subjectId, ?string $organizationId, array $scopes, Request $request): void
    {
        $context = [
            'client_name' => $client->name,
            'self_registered' => true,
            'document_host' => $client->isMetadataDocumentClient() ? parse_url($client->client_id, PHP_URL_HOST) : null,
            'scopes' => $scopes,
            'resource' => $this->resource()?->identifier,
            'via' => 'mcp',
        ];

        $workspace = $this->root->workspaceOf($subjectId, $organizationId);

        if ($workspace !== null) {
            $this->activity->record($workspace->id, self::AUTHORIZED, $subjectId, 'client', $client->client_id, $context, $request);

            return;
        }

        $this->audit->record(new AuditEvent(
            action: self::AUTHORIZED,
            actorType: ActorType::User,
            actorId: $subjectId,
            targetType: 'client',
            targetId: $client->client_id,
            context: $context,
            ip: $request->ip(),
        ));
    }
}
