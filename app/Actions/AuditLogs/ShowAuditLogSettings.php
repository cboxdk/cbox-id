<?php

declare(strict_types=1);

namespace App\Actions\AuditLogs;

use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\ConsoleGate;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\InputSchema;
use App\Platform\AuditLogs\AuditLogPolicy;

/** How long this environment keeps audit events, and whether it refuses actions with no schema. */
#[AsAction(
    name: 'audit_logs.settings.get',
    summary: 'Read this environment\'s audit-log settings: retention in days and whether strict schemas are on.',
    scope: 'audit_logs:read',
    danger: Danger::Read,
    schema: 'AuditLogSettings',
    tag: 'App audit logs',
    rest: ['GET', '/audit-logs/settings'],
    consoleGate: ConsoleGate::EnvironmentAdmin,
)]
final readonly class ShowAuditLogSettings implements Action
{
    public function __construct(private AuditLogPolicy $policy) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        return ActionResult::item($this->policy, self::present($this->policy));
    }

    /**
     * `AuditLogSettings` in the spec.
     *
     * @return array<string, mixed>
     */
    public static function present(AuditLogPolicy $policy): array
    {
        return [
            'retention_days' => $policy->retentionDays(),
            'strict_schemas' => $policy->strict(),
        ];
    }
}
