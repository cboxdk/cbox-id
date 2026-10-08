<?php

declare(strict_types=1);

namespace App\Platform\AuditLogs;

use Cbox\Id\Kernel\Audit\Contracts\AuditLog;
use Cbox\Id\Kernel\Audit\ValueObjects\AuditActor;
use Cbox\Id\Kernel\Audit\ValueObjects\AuditEvent;

/**
 * What changes ABOUT audit logs leaves on the platform's own trail: a schema defined,
 * changed or deleted, the retention changed, an export asked for or downloaded.
 *
 * Not the events themselves. An app's audit events are the customer's records, kept in
 * their own chain ({@see AuditLogChains}); a line on our trail per event would double the
 * volume to say nothing the event does not.
 */
final readonly class AuditLogTrail
{
    public const string SCHEMA_CREATED = 'audit_log_schema.created';

    public const string SCHEMA_UPDATED = 'audit_log_schema.updated';

    public const string SCHEMA_DELETED = 'audit_log_schema.deleted';

    public const string SETTINGS_UPDATED = 'audit_log_settings.updated';

    public const string EXPORT_CREATED = 'audit_log_export.created';

    /** The Admin Portal's direct download, by somebody with no account here. */
    public const string EXPORT_DOWNLOADED = 'audit_log_export.downloaded';

    public function __construct(private AuditLog $log) {}

    /**
     * @param  array<string, mixed>  $context
     */
    public function record(string $action, string $targetType, ?string $targetId, ?string $organizationId, AuditActor $actor, array $context = []): void
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
