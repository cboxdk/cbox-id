<?php

declare(strict_types=1);

namespace App\Actions\AuditLogs;

use App\Models\AuditLogs\AuditLogSchema;
use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionRefused;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\ConsoleGate;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;

/** One action's audit-log schema. */
#[AsAction(
    name: 'audit_logs.schemas.get',
    summary: 'Read the schema one audit-log action\'s events are validated against.',
    scope: 'audit_logs:read',
    danger: Danger::Read,
    schema: 'AuditLogSchema',
    tag: 'App audit logs',
    rest: ['GET', '/audit-logs/schemas/{action}'],
    consoleGate: ConsoleGate::EnvironmentAdmin,
)]
final class ShowAuditLogSchema implements Action
{
    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('action')->inPath()->max(190)->describe('The action, e.g. `invoice.voided`.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $schema = AuditLogSchema::query()->where('action', $context->string('action'))->first()
            ?? throw ActionRefused::notFound('audit log schema');

        return ActionResult::item($schema, AuditLogSchemaFields::present($schema));
    }
}
