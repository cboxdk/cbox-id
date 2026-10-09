<?php

declare(strict_types=1);

namespace App\Platform\Fga;

use Cbox\Id\Kernel\Audit\Contracts\AuditLog;
use Cbox\Id\Kernel\Audit\ValueObjects\AuditActor;
use Cbox\Id\Kernel\Audit\ValueObjects\AuditEvent;

/**
 * What changes an environment's fine-grained authorization model leaves on the audit log:
 * the schema replaced, tuples written, tuples deleted — each with the revision it
 * produced, so "who granted this, and when?" is answered by the trail.
 *
 * Checks and list queries are reads and leave nothing: an app asks them on every request,
 * and a line each would bury every change under its own traffic.
 *
 * A batch names its tuples (at most 100) rather than only counting them: a grant that is
 * not on the trail by name cannot be traced back to who wrote it.
 */
final readonly class FgaTrail
{
    public const string SCHEMA_UPDATED = 'fga.schema.updated';

    public const string TUPLES_WRITTEN = 'fga.tuples.written';

    public const string TUPLES_DELETED = 'fga.tuples.deleted';

    public function __construct(private AuditLog $log) {}

    /**
     * @param  array<string, mixed>  $context
     */
    public function record(string $action, AuditActor $actor, array $context): void
    {
        $this->log->record(new AuditEvent(
            action: $action,
            actorType: $actor->type,
            actorId: $actor->id,
            organizationId: null,
            targetType: 'fga_model',
            targetId: null,
            context: $context,
        ));
    }
}
