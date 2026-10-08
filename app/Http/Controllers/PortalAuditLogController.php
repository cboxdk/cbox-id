<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AuditLogs\AuditLogEvent;
use App\Platform\AdminPortal;
use App\Platform\AuditLogs\AuditLogCsv;
use App\Platform\AuditLogs\AuditLogFilters;
use App\Platform\AuditLogs\AuditLogQuery;
use App\Platform\AuditLogs\AuditLogTrail;
use App\Platform\Enums\PortalIntent;
use App\Platform\Portal\PortalProgress;
use Cbox\Id\Kernel\Audit\ValueObjects\AuditActor;
use Cbox\Id\Organization\Contracts\Organizations;
use Illuminate\Http\Request;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * THE ADMIN PORTAL'S AUDIT LOGS — an organization's IT or security admin, with no account
 * here, reading the audit events the app sent about their organization, and taking a CSV.
 *
 * Under a link that covers `audit_logs`, and while the organization is entitled to it —
 * asked on every request, like every other portal step. THE ORGANIZATION IS THE PORTAL
 * SESSION'S, never a parameter: the filters a person can set narrow their own
 * organization's events and cannot name another's.
 *
 * Read-only. The export is a GET streaming the CSV straight down, rather than the queued
 * export the API offers: the portal session is the credential and lasts minutes, and a
 * signed URL handed to somebody holding a one-time link is one more thing to leak. It is
 * bounded (`cbox-id.audit_logs.portal_export_limit` rows, newest first) and recorded on the
 * platform's trail, because it is how the organization's records leave.
 */
final readonly class PortalAuditLogController extends PageController
{
    private const int PER_PAGE = 50;

    public function index(Request $request, AdminPortal $portal): Response
    {
        $organizationId = $this->boundOrganization($portal);
        $filters = $this->filters($request, $organizationId);
        $cursor = $request->string('after')->toString();
        $cursor = $cursor !== '' && AuditLogQuery::validCursor($cursor) ? $cursor : null;

        $page = AuditLogQuery::page($filters, self::PER_PAGE, $cursor);

        // The checklist's tick for this intent: there is nothing to configure, only to read.
        session()->put(PortalProgress::AUDIT_LOGS_VIEWED, true);

        return $this->page('portal/audit-logs', __('portal.audit_logs.title'), [
            'organizationName' => app(Organizations::class)->find($organizationId)?->name,
            'events' => array_map(self::row(...), $page['events']),
            'filters' => [
                'action' => $request->string('action')->toString(),
                'actor' => $request->string('actor')->toString(),
                'target' => $request->string('target')->toString(),
                'from' => $request->string('from')->toString(),
                'to' => $request->string('to')->toString(),
            ],
            'nextHref' => $page['next_cursor'] === null ? null : $request->fullUrlWithQuery(['after' => $page['next_cursor']]),
            'firstHref' => $cursor === null ? null : $request->fullUrlWithQuery(['after' => null]),
            'exportHref' => route('portal.audit-logs.export', array_filter($request->only(['action', 'actor', 'target', 'from', 'to']), is_string(...))),
            'exportLimit' => self::exportLimit(),
            'finishHref' => route('portal.finish'),
            // The way back to the checklist, when the link covers more than this page.
            'homeHref' => $portal->usableIntents() === [PortalIntent::AuditLogs] ? null : route('portal.setup'),
        ]);
    }

    public function export(Request $request, AdminPortal $portal, AuditLogTrail $trail): StreamedResponse
    {
        $organizationId = $this->boundOrganization($portal);
        $filters = $this->filters($request, $organizationId);
        $limit = self::exportLimit();

        $trail->record(AuditLogTrail::EXPORT_DOWNLOADED, 'admin_portal_link', $portal->currentLink()?->id, $organizationId, AuditActor::system(), [
            'via' => 'admin_portal',
            'filters' => array_filter($filters->toArray(), static fn (mixed $value): bool => $value !== null && $value !== []),
        ]);

        return response()->streamDownload(static function () use ($filters, $limit): void {
            $handle = fopen('php://output', 'w');

            if ($handle !== false) {
                AuditLogCsv::write($handle, AuditLogQuery::each($filters, $limit));
                fclose($handle);
            }
        }, 'audit-logs-'.now()->format('Y-m-d').'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * The organization this portal session reads, if its link covers audit logs and the
     * organization is still entitled to them — re-asked on every request.
     */
    private function boundOrganization(AdminPortal $portal): string
    {
        abort_unless($portal->sessionValid() && $portal->canConfigure(PortalIntent::AuditLogs), 403);

        $organizationId = $portal->boundOrgId();

        abort_if($organizationId === null, 403);

        return $organizationId;
    }

    /** The most rows the portal's direct download writes, newest first. */
    private static function exportLimit(): int
    {
        $limit = config('cbox-id.audit_logs.portal_export_limit', 50_000);

        return is_int($limit) && $limit > 0 ? $limit : 50_000;
    }

    private function filters(Request $request, string $organizationId): AuditLogFilters
    {
        $action = $request->string('action')->toString();
        $from = $request->string('from')->toString();
        $to = $request->string('to')->toString();
        $day = '/^\d{4}-\d{2}-\d{2}$/';

        return AuditLogFilters::from([
            'actions' => $action === '' ? [] : [$action],
            'actor_id' => $request->string('actor')->toString(),
            'target_id' => $request->string('target')->toString(),
            'range_start' => preg_match($day, $from) === 1 ? $from.'T00:00:00Z' : null,
            'range_end' => preg_match($day, $to) === 1 ? date('Y-m-d', (int) strtotime($to.' +1 day')).'T00:00:00Z' : null,
        ], $organizationId);
    }

    /**
     * @return array<string, mixed>
     */
    private static function row(AuditLogEvent $event): array
    {
        $metadata = [];

        foreach ($event->metadata ?? [] as $key => $value) {
            $metadata[] = [(string) $key, match (true) {
                is_bool($value) => $value ? 'true' : 'false',
                is_scalar($value) => (string) $value,
                default => 'null',
            }];
        }

        return [
            'id' => $event->id,
            'occurredAt' => $event->occurredAtIso(),
            'action' => $event->action,
            'actor' => [
                'id' => $event->actor_id,
                'type' => $event->actor_type,
                'name' => $event->actor_name,
            ],
            'targets' => array_map(static fn (array $target): array => [
                'id' => is_scalar($target['id'] ?? null) ? (string) $target['id'] : '',
                'type' => is_scalar($target['type'] ?? null) ? (string) $target['type'] : '',
                'name' => isset($target['name']) && is_string($target['name']) ? $target['name'] : null,
            ], $event->targets),
            'location' => $event->location,
            'metadata' => $metadata,
        ];
    }
}
