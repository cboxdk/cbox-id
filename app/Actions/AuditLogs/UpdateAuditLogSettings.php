<?php

declare(strict_types=1);

namespace App\Actions\AuditLogs;

use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\ConsoleGate;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;
use App\Platform\AuditLogs\AuditLogPolicy;
use App\Platform\AuditLogs\AuditLogTrail;

/**
 * Change how long this environment keeps audit events, and whether an action with no
 * schema is refused.
 *
 * DESTRUCTIVE: shortening the retention deletes every event older than the new window at
 * the next daily prune, for every organization in the environment, and nothing brings them
 * back. Turning strict mode on stops recording every action that has no schema yet. Both
 * knobs are one resource, and one action, because both answer "what does this environment
 * keep" — a retention-only endpoint would be this with one field.
 */
#[AsAction(
    name: 'audit_logs.settings.update',
    summary: 'Change this environment\'s audit-log retention (days, 1–3650) and strict mode. Shortening retention deletes older events at the next daily prune — irreversibly.',
    scope: 'audit_logs:manage',
    danger: Danger::Destructive,
    schema: 'AuditLogSettings',
    tag: 'App audit logs',
    rest: ['PATCH', '/audit-logs/settings'],
    consoleRoutes: ['environment.audit-logs.settings.update'],
    consoleGate: ConsoleGate::EnvironmentAdmin,
)]
final readonly class UpdateAuditLogSettings implements Action
{
    public function __construct(
        private AuditLogPolicy $policy,
        private AuditLogTrail $trail,
    ) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::integer('retention_days')->min(AuditLogPolicy::MIN_RETENTION_DAYS)->max(AuditLogPolicy::MAX_RETENTION_DAYS)
                ->describe('Keep events this many days after they arrive.'),
            Field::boolean('strict_schemas')->describe('Refuse events whose action has no schema.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $days = $context->input['retention_days'] ?? null;

        $changes = $this->policy->update(
            is_numeric($days) ? (int) $days : null,
            $context->has('strict_schemas') ? $context->boolean('strict_schemas') : null,
        );

        if ($changes !== []) {
            $this->trail->record(AuditLogTrail::SETTINGS_UPDATED, 'audit_log_settings', null, null, $context->actor(), [
                'changes' => $changes,
            ]);
        }

        return ActionResult::item($this->policy, ShowAuditLogSettings::present($this->policy));
    }
}
