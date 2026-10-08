<?php

declare(strict_types=1);

namespace App\Actions\AuditLogs;

use App\Http\Resources\Environment\AuditLogEventResource;
use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionRefused;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\ConsoleGate;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;
use App\Platform\AuditLogs\AuditLogQuery;

/**
 * Read audit events, newest first, a keyset page at a time ({@see AuditLogQuery}).
 *
 * The environment's authority reads any organization's or all of them; an organization's
 * own administrator — or a token one signed in for — reads theirs alone
 * ({@see AuditLogReach}). Every filter is index-backed, and `after` is the `next_cursor`
 * of the page before: opaque, and only meaningful with the same filters.
 */
#[AsAction(
    name: 'audit_logs.events.list',
    summary: 'Read audit events newest first, for one organization or the whole environment, filtered by action, actor, target and time range; pass `next_cursor` as `after` to page.',
    scope: 'audit_logs:read',
    danger: Danger::Read,
    schema: 'AuditLogEvent',
    tag: 'App audit logs',
    rest: ['GET', '/audit-logs/events'],
    consoleGate: ConsoleGate::Administer,
)]
final class ListAuditLogEvents implements Action
{
    public static function input(): InputSchema
    {
        return InputSchema::of([
            ...AuditLogFilterFields::all(),
            Field::string('order')->oneOf(['desc', 'asc'])->describe('`desc` (default) for newest first, `asc` for oldest first.'),
            Field::integer('limit')->describe('Events per page, 1–100. Default 50.'),
            Field::string('after')->max(200)->describe('The `next_cursor` of the previous page.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $filters = AuditLogReach::filters($context);
        $asked = $context->input['limit'] ?? 50;
        $limit = min(100, max(1, is_numeric($asked) ? (int) $asked : 50));
        $cursor = $context->nullableString('after');

        if ($cursor !== null && ! AuditLogQuery::validCursor($cursor)) {
            throw ActionRefused::because('invalid_cursor', 'after must be a next_cursor this list returned.', 'after');
        }

        $page = AuditLogQuery::page($filters, $limit, $cursor, $context->string('order') !== 'asc');

        return ActionResult::page($page['events'], array_map(AuditLogEventResource::from(...), $page['events']), [
            'limit' => $limit,
            'has_more' => $page['has_more'],
            'next_cursor' => $page['next_cursor'],
        ]);
    }
}
