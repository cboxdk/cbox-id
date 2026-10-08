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
 * Define the shape one action's events must have: the target types they may name and what
 * their metadata may hold. From then on an event of that action that does not fit is
 * refused at the door — the point of a schema is that the log a customer's admin reads
 * is consistent, and that is decided when the event arrives, not when somebody reads it.
 *
 * The environment's own setting: an organization's administrator reads the events and
 * never redefines what an app is allowed to say.
 */
#[AsAction(
    name: 'audit_logs.schemas.create',
    summary: 'Define the schema one audit-log action\'s events must match — allowed target types and metadata schemas (a subset of JSON Schema). Events of that action are then validated on arrival.',
    scope: 'audit_logs:manage',
    danger: Danger::Write,
    schema: 'AuditLogSchema',
    tag: 'App audit logs',
    rest: ['POST', '/audit-logs/schemas'],
    status: 201,
    consoleRoutes: ['environment.audit-logs.schemas.store'],
    consoleGate: ConsoleGate::EnvironmentAdmin,
)]
final readonly class CreateAuditLogSchema implements Action
{
    public function __construct(private AuditLogTrail $trail) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('action')->required()->max(190)->describe('The action this schema is for, e.g. `invoice.voided`.'),
            ...AuditLogSchemaFields::body(),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $action = AuditLogSchemaFields::action($context->string('action'));

        if (AuditLogSchema::query()->where('action', $action)->exists()) {
            throw new ActionRefused('schema_exists', "`{$action}` already has a schema. Replace it with audit_logs.schemas.update.", 409, 'action');
        }

        $body = AuditLogSchemaFields::checked($context);

        $schema = new AuditLogSchema;
        $schema->forceFill(['action' => $action, 'version' => 1, ...$body])->save();

        $this->trail->record(AuditLogTrail::SCHEMA_CREATED, 'audit_log_schema', $schema->id, null, $context->actor(), [
            'action' => $action,
            'version' => 1,
        ]);

        return ActionResult::item($schema, AuditLogSchemaFields::present($schema));
    }
}
