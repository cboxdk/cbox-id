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
use App\Platform\AuditLogs\AuditLogTrail;

/**
 * Remove an action's schema. Its events are accepted as sent from then on — or, in strict
 * mode, REFUSED, because strict mode accepts only actions that have one. Destructive for
 * that reason: deleting a schema in a strict environment stops an app's events of that
 * action from being recorded at all.
 */
#[AsAction(
    name: 'audit_logs.schemas.delete',
    summary: 'Delete an audit-log action\'s schema. Its events are then accepted unchecked — or refused, if the environment is in strict mode.',
    scope: 'audit_logs:manage',
    danger: Danger::Destructive,
    tag: 'App audit logs',
    rest: ['DELETE', '/audit-logs/schemas/{action}'],
    status: 204,
    consoleRoutes: ['environment.audit-logs.schemas.destroy'],
    consoleGate: ConsoleGate::EnvironmentAdmin,
)]
final readonly class DeleteAuditLogSchema implements Action
{
    public function __construct(private AuditLogTrail $trail) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('action')->inPath()->max(190)->describe('The action whose schema to delete.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $schema = AuditLogSchema::query()->where('action', $context->string('action'))->first()
            ?? throw ActionRefused::notFound('audit log schema');

        $schema->delete();

        $this->trail->record(AuditLogTrail::SCHEMA_DELETED, 'audit_log_schema', $schema->id, null, $context->actor(), [
            'action' => $schema->action,
            'version' => $schema->version,
        ]);

        return ActionResult::none($schema);
    }
}
