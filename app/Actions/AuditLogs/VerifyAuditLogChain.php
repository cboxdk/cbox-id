<?php

declare(strict_types=1);

namespace App\Actions\AuditLogs;

use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionRefused;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\ConsoleGate;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;
use App\Platform\AuditLogs\AuditLogChains;

/**
 * Re-hash an organization's audit-event chain and say whether it still holds — the check
 * behind calling these events tamper-evident.
 *
 * Bounded per call (`limit`, at most 10,000 events) so a long chain is verified in steps:
 * an answer with `complete: false` continues from `last_sequence + 1`. What it proves and
 * what it does not is said in {@see AuditLogChains}.
 */
#[AsAction(
    name: 'audit_logs.verify',
    summary: 'Re-hash one organization\'s audit-event chain (from the oldest event retention kept, or from_sequence) and report whether every event is unchanged and in place.',
    scope: 'audit_logs:read',
    danger: Danger::Read,
    schema: 'AuditLogVerification',
    tag: 'App audit logs',
    rest: ['GET', '/audit-logs/verify'],
    consoleGate: ConsoleGate::Administer,
)]
final readonly class VerifyAuditLogChain implements Action
{
    public function __construct(private AuditLogChains $chains) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('organization_id')->max(64)->describe('The organization whose chain to verify. An organization\'s own administrator may leave it out.'),
            Field::integer('from_sequence')->min(1)->describe('Start here rather than at the oldest event kept.'),
            Field::integer('limit')->min(1)->max(10000)->describe('Verify at most this many events. Default 10,000.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $organizationId = AuditLogReach::organization($context);

        if ($organizationId === null) {
            throw ActionRefused::because('organization_required', 'A chain is one organization\'s: send organization_id.', 'organization_id');
        }

        $from = $context->input['from_sequence'] ?? null;
        $limit = $context->input['limit'] ?? 10_000;

        $verification = $this->chains->verify(
            $organizationId,
            is_numeric($from) ? (int) $from : null,
            is_numeric($limit) ? (int) $limit : 10_000,
        );

        return ActionResult::item($verification, $verification->toArray($organizationId));
    }
}
