<?php

declare(strict_types=1);

namespace App\Platform\Apis;

use Cbox\Id\Kernel\Audit\Contracts\AuditLog;
use Cbox\Id\Kernel\Audit\ValueObjects\AuditActor;
use Cbox\Id\Kernel\Audit\ValueObjects\AuditEvent;
use Cbox\Id\OAuthServer\Contracts\Apis;
use Cbox\Id\OAuthServer\Models\Api;

/**
 * The one shape an API's lifecycle takes in the audit trail.
 *
 * {@see Apis} writes nothing to the trail — unlike the client registry, which records every
 * app change itself — so the console records its own. An API decides who may be handed
 * which scope and what `aud` a token carries; registering one, deleting one, and above all
 * letting organizations' apps request a scope are the changes somebody asks about after an
 * incident, and a change nobody recorded is the one that gets asked about.
 *
 * On the owning organization's trail when an organization owns it, so its administrators
 * see what the environment did to their API; the environment's own APIs on the system
 * trail. The target is the identifier: it is what every token names, and it never changes.
 */
final readonly class ApiAudit
{
    public const CREATED = 'api.created';

    public const UPDATED = 'api.updated';

    public const SCOPE_DEFINED = 'api.scope_defined';

    public const SCOPE_REMOVED = 'api.scope_removed';

    public const DELETED = 'api.deleted';

    public function __construct(private AuditLog $log) {}

    /**
     * @param  array<string, mixed>  $context
     */
    public function record(string $action, Api $api, AuditActor $actor, array $context = []): void
    {
        $this->log->record(new AuditEvent(
            action: $action,
            actorType: $actor->type,
            actorId: $actor->id,
            organizationId: $api->organization_id,
            targetType: 'api',
            targetId: $api->identifier,
            context: ['name' => $api->name] + $context,
        ));
    }
}
