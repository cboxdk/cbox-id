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
use App\Platform\AuditLogs\AuditLogExports;
use App\Platform\AuditLogs\AuditLogTrail;

/**
 * Ask for a CSV of audit events — the same filters as the list — written on the queue.
 * Poll `audit_logs.exports.get` until it is `ready`; its `url` then downloads the file
 * for a few minutes.
 *
 * A scope of its own (`audit_logs:export`): it hands out what the list would, held to the
 * same reach ({@see AuditLogReach}) — an organization's administrator exports their own
 * organization's events and nothing else — but in one file, which is a different thing
 * to grant a key than reading a page. On the trail for the same reason.
 *
 * `url` is in `redact`: an export that finished before this answer was written carries
 * one, and a signed download link has no business in the idempotency store.
 */
#[AsAction(
    name: 'audit_logs.exports.create',
    summary: 'Start a CSV export of audit events (same filters as the list). It is written on the queue: poll audit_logs.exports.get until state is ready, then download from its short-lived url.',
    scope: 'audit_logs:export',
    danger: Danger::Write,
    schema: 'AuditLogExport',
    tag: 'App audit logs',
    rest: ['POST', '/audit-logs/exports'],
    status: 201,
    consoleRoutes: ['audit-logs.exports.store', 'environment.audit-logs.exports.store'],
    consoleGate: ConsoleGate::Administer,
    redact: ['url'],
)]
final readonly class CreateAuditLogExport implements Action
{
    public function __construct(
        private AuditLogExports $exports,
        private AuditLogTrail $trail,
    ) {}

    public static function input(): InputSchema
    {
        return InputSchema::of(AuditLogFilterFields::all());
    }

    public function handle(ActionContext $context): ActionResult
    {
        $filters = AuditLogReach::filters($context);
        $actor = $context->actor();

        $export = $this->exports->request($filters, (string) ($actor->id ?? $context->principal->id()));

        $this->trail->record(AuditLogTrail::EXPORT_CREATED, 'audit_log_export', $export->id, $filters->organizationId, $actor, [
            'filters' => array_filter($filters->toArray(), static fn (mixed $value): bool => $value !== null && $value !== []),
        ]);

        return ActionResult::item($export, $this->exports->present($export));
    }
}
