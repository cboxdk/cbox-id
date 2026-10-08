<?php

declare(strict_types=1);

namespace App\Actions\AuditLogs;

use App\Models\AuditLogs\AuditLogExport;
use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionRefused;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\ConsoleGate;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;
use App\Platform\AuditLogs\AuditLogExports;

/**
 * Where an export is: `pending`, `ready` (with a signed `url` that works for a few
 * minutes — ask again for a fresh one), `failed`, or `expired`.
 *
 * Held to the reader's reach: a confined reader sees only their organization's exports,
 * and an export of another's — or of the whole environment — is not found.
 */
#[AsAction(
    name: 'audit_logs.exports.get',
    summary: 'Read an audit-log export\'s state; once ready it carries a signed download url valid for a few minutes (read it again for a fresh one).',
    scope: 'audit_logs:read',
    danger: Danger::Read,
    schema: 'AuditLogExport',
    tag: 'App audit logs',
    rest: ['GET', '/audit-logs/exports/{id}'],
    consoleGate: ConsoleGate::Administer,
)]
final readonly class ShowAuditLogExport implements Action
{
    public function __construct(private AuditLogExports $exports) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('id')->inPath()->max(64)->describe('The export id.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $confinedTo = $context->principal->confinedToOrganization();

        $export = AuditLogExport::query()
            ->whereKey($context->string('id'))
            ->when($confinedTo !== null, static fn ($query) => $query->where('organization_id', $confinedTo))
            ->first() ?? throw ActionRefused::notFound('export');

        return ActionResult::item($export, $this->exports->present($export));
    }
}
