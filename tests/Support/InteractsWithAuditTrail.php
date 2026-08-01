<?php

declare(strict_types=1);

namespace Tests\Support;

use Cbox\Id\Kernel\Audit\Contracts\AuditLog;
use Cbox\Id\Kernel\Audit\ValueObjects\AuditEvent;

/**
 * Seeds entries through the REAL hash-chained {@see AuditLog}, so a test exercises the
 * same trail the compliance module reads and exports — chain links, checkpoints and
 * all — rather than rows inserted behind the primitive's back.
 *
 * Came in with the compliance module, whose Testbench base owned it. The module's
 * tests now run against the app's own suite, and the helper is app-wide because the
 * audit trail is: anything asserting on it wants entries recorded the honest way.
 *
 * A null `$organizationId` records against the SYSTEM trail, which is a separate chain
 * from any organization's — several compliance tests turn on that distinction.
 */
trait InteractsWithAuditTrail
{
    protected function recordAudit(
        string $action,
        ?string $organizationId = null,
        ?string $actorId = null,
        ?string $targetId = null,
        string $targetType = 'user',
    ): void {
        $this->app->make(AuditLog::class)->record(new AuditEvent(
            action: $action,
            actorId: $actorId,
            organizationId: $organizationId,
            targetType: $targetId === null ? null : $targetType,
            targetId: $targetId,
        ));
    }
}
