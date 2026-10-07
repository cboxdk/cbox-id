<?php

declare(strict_types=1);

namespace App\Platform\Enterprise;

use Cbox\Id\Federation\Contracts\Connections;
use Cbox\Id\Governance\Contracts\SegregationOfDuties;
use Cbox\Id\Kernel\Audit\Contracts\AuditLog;
use Cbox\Id\Kernel\Audit\ValueObjects\AuditActor;
use Cbox\Id\Kernel\Audit\ValueObjects\AuditEvent;
use Cbox\Id\TokenVault\Contracts\SecretVault;

/**
 * The one shape a change to an organization's ENTERPRISE PLUMBING takes in the audit
 * trail: its SSO connections, the directories that sync its people in, the SCIM targets
 * that sync them out, and the rules that govern who may hold what.
 *
 * Most of these recorded nothing before they became actions. A connection was edited,
 * disabled and deleted as a bare model write; a directory's bearer token was re-minted
 * with nothing to say who asked; an outbound target that sends every person's data to a
 * URL somebody typed was registered silently. With a management key able to do the same,
 * each is now on the trail as whoever did it — the key over the API, the person in the
 * console, because both run the same action and the action records it.
 *
 * What the framework already records is left to it: {@see Connections::activate()} writes
 * `connection.activated`, the {@see SecretVault} writes every `vault.*`, and
 * {@see SegregationOfDuties} writes `sod.policy_defined` and the switches. What is never in
 * a context is a secret — a client secret, a certificate, a signing key, a bearer token.
 */
final readonly class EnterpriseAudit
{
    public const string SSO_CONNECTION_CREATED = 'sso_connection.created';

    public const string SSO_CONNECTION_UPDATED = 'sso_connection.updated';

    public const string SSO_CONNECTION_DISABLED = 'sso_connection.disabled';

    public const string SSO_CONNECTION_DELETED = 'sso_connection.deleted';

    public const string SSO_REQUIRED = 'sso_connection.sso_required';

    public const string DIRECTORY_REGISTERED = 'directory.registered';

    public const string DIRECTORY_CONNECTED = 'directory.connected';

    public const string DIRECTORY_RENAMED = 'directory.renamed';

    public const string DIRECTORY_TOKEN_ROTATED = 'directory.token_rotated';

    public const string DIRECTORY_PAUSED = 'directory.paused';

    public const string DIRECTORY_RESUMED = 'directory.resumed';

    public const string DIRECTORY_DELETED = 'directory.deleted';

    public const string DIRECTORY_GROUP_MAPPED = 'directory.group_role_mapped';

    public const string DIRECTORY_GROUP_UNMAPPED = 'directory.group_role_unmapped';

    public const string PROVISIONING_REGISTERED = 'provisioning_connection.registered';

    public const string PROVISIONING_PAUSED = 'provisioning_connection.paused';

    public const string PROVISIONING_RESUMED = 'provisioning_connection.resumed';

    public const string PROVISIONING_DELETED = 'provisioning_connection.deleted';

    public const string SOD_POLICY_DELETED = 'sod.policy_deleted';

    public function __construct(private AuditLog $log) {}

    /**
     * @param  array<string, mixed>  $context
     */
    public function record(string $action, AuditActor $actor, ?string $organizationId, string $targetType, ?string $targetId, array $context = []): void
    {
        $this->log->record(new AuditEvent(
            action: $action,
            actorType: $actor->type,
            actorId: $actor->id,
            organizationId: $organizationId,
            targetType: $targetType,
            targetId: $targetId,
            context: $context,
        ));
    }
}
