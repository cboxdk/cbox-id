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
 * Replace an action's schema with a new version. A replacement, not a patch: what is not
 * sent is no longer required — a schema half-merged from two edits is one nobody wrote.
 *
 * Events already recorded are untouched and keep the version they were checked against;
 * the new version applies to what arrives next.
 */
#[AsAction(
    name: 'audit_logs.schemas.update',
    summary: 'Replace an audit-log action\'s schema with a new version (omitted parts are removed). Recorded events keep the version they were checked against.',
    scope: 'audit_logs:manage',
    danger: Danger::Write,
    schema: 'AuditLogSchema',
    tag: 'Audit Logs',
    rest: ['PUT', '/audit-logs/schemas/{action}'],
    consoleRoutes: ['environment.audit-logs.schemas.update'],
    consoleGate: ConsoleGate::EnvironmentAdmin,
)]
final readonly class UpdateAuditLogSchema implements Action
{
    public function __construct(private AuditLogTrail $trail) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('action')->inPath()->max(190)->describe('The action whose schema to replace.'),
            ...AuditLogSchemaFields::body(),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $schema = AuditLogSchema::query()->where('action', $context->string('action'))->first()
            ?? throw ActionRefused::notFound('audit log schema');

        $body = AuditLogSchemaFields::checked($context);
        $from = $schema->version;

        $schema->forceFill(['version' => $from + 1, ...$body])->save();

        $this->trail->record(AuditLogTrail::SCHEMA_UPDATED, 'audit_log_schema', $schema->id, null, $context->actor(), [
            'action' => $schema->action,
            'version' => ['from' => $from, 'to' => $schema->version],
        ]);

        return ActionResult::item($schema, AuditLogSchemaFields::present($schema));
    }
}
