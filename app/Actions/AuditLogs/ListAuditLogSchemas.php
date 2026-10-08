<?php

declare(strict_types=1);

namespace App\Actions\AuditLogs;

use App\Models\AuditLogs\AuditLogSchema;
use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\ConsoleGate;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\InputSchema;
use App\Platform\Actions\Paginates;

/** This environment's audit-log schemas, one per action that has one. */
#[AsAction(
    name: 'audit_logs.schemas.list',
    summary: 'List the audit-log schemas this environment validates events against, one per action.',
    scope: 'audit_logs:read',
    danger: Danger::Read,
    schema: 'AuditLogSchema',
    tag: 'App audit logs',
    rest: ['GET', '/audit-logs/schemas'],
    consoleGate: ConsoleGate::EnvironmentAdmin,
)]
final class ListAuditLogSchemas implements Action
{
    use Paginates;

    public static function input(): InputSchema
    {
        return InputSchema::of(self::pageFields());
    }

    public function handle(ActionContext $context): ActionResult
    {
        return $this->page(AuditLogSchema::query(), $context, AuditLogSchemaFields::present(...));
    }
}
