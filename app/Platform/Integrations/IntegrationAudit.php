<?php

declare(strict_types=1);

namespace App\Platform\Integrations;

use Cbox\Id\ExternalActions\Contracts\ExternalActions;
use Cbox\Id\Kernel\Audit\Contracts\AuditLog;
use Cbox\Id\Kernel\Audit\ValueObjects\AuditActor;
use Cbox\Id\Kernel\Audit\ValueObjects\AuditEvent;
use Cbox\Id\Webhooks\Contracts\WebhookRegistry;
use Cbox\LaravelSiem\Contracts\LogStreams;

/**
 * The one shape every change to an integration takes in the audit trail.
 *
 * Webhooks, inline hooks and log streams are where this environment's data LEAVES it, or
 * where an outside endpoint gets a say in who signs in — so repointing one, pausing the
 * SIEM feed or re-keying a receiver is exactly what somebody asks about after an
 * incident. None of it was recorded: {@see WebhookRegistry} and {@see LogStreams} write
 * nothing to the trail, {@see ExternalActions} records a registration as the system and
 * nothing after it, and the console's pause, resume, re-key, edit and delete were inline
 * model writes. As actions, every change is one entry, the same shape from the console and
 * the management API, naming whoever the door says acted.
 *
 * On the owning organization's trail when an organization owns the integration, so its
 * administrators see what was done to their endpoints; the environment's own on the
 * environment trail. The target is the integration's id. Secrets are never in the context
 * — only the fact that one was issued.
 */
final readonly class IntegrationAudit
{
    public const string WEBHOOK_CREATED = 'webhook.created';

    public const string WEBHOOK_UPDATED = 'webhook.updated';

    public const string WEBHOOK_PAUSED = 'webhook.paused';

    public const string WEBHOOK_RESUMED = 'webhook.resumed';

    public const string WEBHOOK_SECRET_ROTATED = 'webhook.secret_rotated';

    public const string WEBHOOK_DELETED = 'webhook.deleted';

    public const string HOOK_CREATED = 'inline_hook.created';

    public const string HOOK_PAUSED = 'inline_hook.paused';

    public const string HOOK_ACTIVATED = 'inline_hook.activated';

    public const string HOOK_DELETED = 'inline_hook.deleted';

    public const string LOG_STREAM_CREATED = 'log_stream.created';

    public const string LOG_STREAM_UPDATED = 'log_stream.updated';

    public const string LOG_STREAM_DISABLED = 'log_stream.disabled';

    public const string LOG_STREAM_ENABLED = 'log_stream.enabled';

    public const string LOG_STREAM_DELETED = 'log_stream.deleted';

    public function __construct(private AuditLog $log) {}

    /**
     * @param  'webhook_endpoint'|'inline_hook'|'log_stream'  $targetType
     * @param  array<string, mixed>  $context
     */
    public function record(string $action, string $targetType, string $targetId, ?string $organizationId, AuditActor $actor, array $context = []): void
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
