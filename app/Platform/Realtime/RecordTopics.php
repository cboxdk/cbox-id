<?php

declare(strict_types=1);

namespace App\Platform\Realtime;

use App\Platform\Actions\Approvals\ActionApprovalRequest;
use Cbox\Id\AccessControl\Models\EnvironmentRoleAssignment;
use Cbox\Id\AccessControl\Models\Role;
use Cbox\Id\AccessControl\Models\RoleAssignment;
use Cbox\Id\Devices\Models\Device;
use Cbox\Id\Identity\Models\IdentityLink;
use Cbox\Id\Identity\Models\MfaFactor;
use Cbox\Id\Identity\Models\Session;
use Cbox\Id\Identity\Models\User;
use Cbox\Id\Identity\Models\WebAuthnCredential;
use Cbox\Id\Kernel\Audit\Models\AuditEntry;
use Cbox\Id\OAuthServer\Models\AccessToken;
use Cbox\Id\OAuthServer\Models\BackchannelAuthRequest;
use Cbox\Id\OAuthServer\Models\Client;
use Cbox\Id\OAuthServer\Models\RefreshToken;
use Cbox\Id\OAuthServer\Models\ServiceAccount;
use Cbox\Id\Organization\Models\CustomerApiKey;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Organization\Models\Invitation;
use Cbox\Id\Organization\Models\Membership;
use Cbox\Id\Organization\Models\Organization;
use Cbox\Id\Organization\Models\UserApiToken;
use Cbox\Id\Platform\Models\EnvironmentApiKey;
use Cbox\Id\Platform\Models\OrganizationApiKey;
use Cbox\Id\Platform\Models\Project;

/**
 * WHICH RECORDS ARE LIVE: the models a page may be showing, the topic each belongs to and
 * the short type a page is told it was.
 *
 * Observed rather than re-emitted: these are the rows laravel-id and the app already write
 * on every path that changes them — the sign-in, the enrolment, the revocation, the
 * membership change, the CIBA decision — so a new write path is live without anybody
 * remembering to announce it. The audit trail is in the list too ({@see AuditTopics}),
 * which covers the bulk changes no model event sees (`user.sessions_revoked`).
 *
 * A topic is a word a page subscribes to, not a table: `credentials` is passkeys,
 * authenticator apps and linked sign-ins together, because the Security page shows them
 * together.
 */
final class RecordTopics
{
    /**
     * Columns whose change alone is not news to anybody looking at a page: activity
     * stamps, counters and rotation bookkeeping. A row that only touched these is not
     * published, which is what keeps a session's heartbeat or a CIBA poll off the socket.
     */
    public const array QUIET = [
        'updated_at', 'last_active_at', 'last_used_at', 'last_seen_at', 'last_polled_at',
        'sign_count', 'last_used_step', 'last_sequence', 'consecutive_failures',
        'last_success_at', 'last_error', 'circuit_opened_at', 'token_rotated_at',
        'token_encrypted', 'dpop_jkt', 'app_version', 'os_version', 'consumed_at',
        'successor_token', 'expires_at', 'country_code', 'city', 'last_login_at',
    ];

    /**
     * @var array<class-string, array{topic: string, type: string, events?: list<string>}>
     */
    public const array MODELS = [
        // People and where they are signed in.
        User::class => ['topic' => 'users', 'type' => 'user'],
        Session::class => ['topic' => 'sessions', 'type' => 'session'],

        // How a person signs in and approves: trusted devices (the Authenticator),
        // passkeys, authenticator apps, linked accounts.
        Device::class => ['topic' => 'devices', 'type' => 'device'],
        WebAuthnCredential::class => ['topic' => 'credentials', 'type' => 'passkey'],
        MfaFactor::class => ['topic' => 'credentials', 'type' => 'mfa_factor'],
        IdentityLink::class => ['topic' => 'credentials', 'type' => 'linked_account'],

        // What can act as a person. Access tokens are minted on every request an app
        // makes; only their revocation is news.
        RefreshToken::class => ['topic' => 'tokens', 'type' => 'grant'],
        AccessToken::class => ['topic' => 'tokens', 'type' => 'access_token', 'events' => ['updated', 'deleted']],
        UserApiToken::class => ['topic' => 'api_keys', 'type' => 'api_key'],
        CustomerApiKey::class => ['topic' => 'api_keys', 'type' => 'api_key'],

        // Approvals waiting for a person: a CIBA request (an agent asking), an action held
        // for approval.
        BackchannelAuthRequest::class => ['topic' => 'approvals', 'type' => 'ciba_request'],
        ActionApprovalRequest::class => ['topic' => 'approvals', 'type' => 'approval'],

        // Organizations, who is in them and what they may do.
        Organization::class => ['topic' => 'organizations', 'type' => 'organization'],
        Membership::class => ['topic' => 'members', 'type' => 'membership'],
        Invitation::class => ['topic' => 'members', 'type' => 'invitation'],
        Role::class => ['topic' => 'roles', 'type' => 'role'],
        RoleAssignment::class => ['topic' => 'roles', 'type' => 'role_assignment'],
        EnvironmentRoleAssignment::class => ['topic' => 'roles', 'type' => 'role_assignment'],

        // Apps (OAuth clients) and the keys that call the API.
        Client::class => ['topic' => 'apps', 'type' => 'app'],
        ServiceAccount::class => ['topic' => 'apps', 'type' => 'service_account'],
        EnvironmentApiKey::class => ['topic' => 'keys', 'type' => 'key'],
        OrganizationApiKey::class => ['topic' => 'keys', 'type' => 'key'],

        // An organization's projects and environments (the workspace console).
        Project::class => ['topic' => 'projects', 'type' => 'project'],
        Environment::class => ['topic' => 'projects', 'type' => 'environment'],

        // Every audited act.
        AuditEntry::class => ['topic' => 'audit', 'type' => 'audit_entry', 'events' => ['created']],
    ];

    /** Every topic a page may subscribe to. */
    public const array TOPICS = [
        'users', 'sessions', 'devices', 'credentials', 'tokens', 'api_keys', 'approvals',
        'organizations', 'members', 'roles', 'apps', 'keys', 'projects', 'audit',
    ];
}
