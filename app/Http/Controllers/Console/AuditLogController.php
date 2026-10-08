<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Actions\AuditLogs\AuditLogSchemaFields;
use App\Actions\AuditLogs\CreateAuditLogExport;
use App\Actions\AuditLogs\CreateAuditLogSchema;
use App\Actions\AuditLogs\DeleteAuditLogSchema;
use App\Actions\AuditLogs\UpdateAuditLogSchema;
use App\Actions\AuditLogs\UpdateAuditLogSettings;
use App\Http\Props\Shared\HelpProps;
use App\Models\AuditLogs\AuditLogEvent;
use App\Models\AuditLogs\AuditLogExport;
use App\Models\AuditLogs\AuditLogSchema;
use App\Platform\AuditLogs\AuditLogExports;
use App\Platform\AuditLogs\AuditLogFilters;
use App\Platform\AuditLogs\AuditLogPolicy;
use App\Platform\AuditLogs\AuditLogQuery;
use App\Platform\Console\ConsolePlane;
use App\Platform\Help\HelpTopic;
use Cbox\Id\Organization\Models\Organization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;
use JsonException;

/**
 * CONSOLE › AUDIT LOGS — the audit events an app built on this environment sends about its
 * own customers (`POST /api/v1/audit-logs/events`), as the people who need to read them see
 * them.
 *
 * One page, three places, the rows bounded by WHERE it is drawn rather than by a field:
 *
 *  - the organization console (`audit-logs`): the customer's own administrators reading
 *    their organization's events — the "who did that?" an Admin Portal link answers for
 *    somebody with no account;
 *  - an organization's page in the environment console
 *    (`environment.organizations.audit-logs`): the same, for the app's own team;
 *  - the environment console (`environment.audit-logs`): every organization's, with an
 *    Organization chip — and, on its own pages, the schemas events are checked against
 *    and the retention they are kept under, which are the environment's to decide.
 *
 * Every write is an action (`App\Actions\AuditLogs\*`): the export, the schemas, the
 * settings — the same the management API and MCP run, with the same trail line.
 */
final readonly class AuditLogController extends ConsoleController
{
    private const int PER_PAGE = 50;

    /** Action input name => this form's field. */
    private const array FIELDS = [
        'action' => 'action',
        'metadata' => 'metadata',
        'actor_metadata' => 'actorMetadata',
        'targets' => 'targets',
    ];

    public function index(Request $request, AuditLogExports $exports): Response
    {
        $this->scope->assertMayAdminister();

        $filter = $this->organizationFilter();
        $filters = $this->filters($request, $filter->id);
        $cursor = $request->string('after')->toString();
        $cursor = $cursor !== '' && AuditLogQuery::validCursor($cursor) ? $cursor : null;

        // A filter naming no organization of this environment is an empty list, never the
        // environment's whole one.
        $page = $filter->unknown
            ? ['events' => [], 'has_more' => false, 'next_cursor' => null]
            : AuditLogQuery::page($filters, self::PER_PAGE, $cursor);

        $environmentWide = $filter->id === null && ! $filter->unknown;
        $names = $environmentWide ? $this->organizationNames($page['events']) : [];

        $recentExports = AuditLogExport::query()
            ->when($filter->id !== null, static fn ($query) => $query->where('organization_id', $filter->id))
            ->when($environmentWide, static fn ($query) => $query->whereNull('organization_id'))
            ->latest()
            ->limit(5)
            ->get();

        return $this->page('console/audit-logs/index', 'App audit logs', [
            'help' => HelpProps::for(HelpTopic::AuditLogs),
            'events' => array_map(fn (AuditLogEvent $event): array => $this->row($event, $names), $page['events']),
            'filters' => [
                'action' => $request->string('action')->toString(),
                'actor' => $request->string('actor')->toString(),
                'target' => $request->string('target')->toString(),
                'from' => $request->string('from')->toString(),
                'to' => $request->string('to')->toString(),
            ],
            'nextHref' => $page['next_cursor'] === null ? null : $request->fullUrlWithQuery(['after' => $page['next_cursor']]),
            'firstHref' => $cursor === null ? null : $request->fullUrlWithQuery(['after' => null]),
            'environmentWide' => $environmentWide,
            'organizationFilter' => $this->organizationFilterProps($filter),
            'exports' => $recentExports->map(static fn (AuditLogExport $export): array => [
                'id' => $export->id,
                'state' => $export->visibleState(),
                'rowCount' => $export->row_count,
                'createdAt' => $export->created_at->toIso8601String(),
                'downloadHref' => $exports->downloadUrl($export),
            ])->all(),
            'exportHref' => $this->url('audit-logs.exports.store'),
            // The environment console's export route is the environment-wide one, also from
            // an organization's own page: the organization travels with the form.
            // A filter naming no organization here exports nothing rather than everything.
            'exportOrganization' => $this->scope->plane() === ConsolePlane::Environment
                ? ($filter->unknown ? $request->string('organization')->toString() : $filter->id)
                : null,
            'schemasHref' => $this->scope->plane() === ConsolePlane::Environment ? route('environment.audit-logs.schemas') : null,
        ]);
    }

    /**
     * Export what the page shows — the same filters, through the same action the API runs.
     */
    public function export(Request $request): RedirectResponse
    {
        $this->scope->assertMayAdminister();

        $organizationId = $this->scope->plane() === ConsolePlane::Organization
            ? $this->scope->requireOrganizationId()
            : $this->scope->organizationId();

        if ($organizationId === null && $request->string('organization')->toString() !== '') {
            $organizationId = $this->postedOrganization($request);

            if ($organizationId === null) {
                return back()->withErrors(['export' => 'That organization is not in this environment.']);
            }
        }

        $result = $this->act(CreateAuditLogExport::class, array_filter([
            'organization_id' => $organizationId,
            'actions' => $request->string('action')->toString() === '' ? null : [$request->string('action')->toString()],
            'actor_id' => $request->string('actor')->toString(),
            'target_id' => $request->string('target')->toString(),
            'range_start' => self::dayStart($request->string('from')->toString()),
            'range_end' => self::dayEnd($request->string('to')->toString()),
        ], static fn (mixed $value): bool => $value !== null && $value !== ''), ['range_start' => 'from', 'range_end' => 'to'], 'export');

        return $result instanceof RedirectResponse
            ? $result
            : back()->with('status', 'Export started — it appears below when it is ready to download.');
    }

    /** The environment's schemas, the actions seen lately without one, and its settings. */
    public function schemas(AuditLogPolicy $policy): Response
    {
        $this->scope->assertMayAdministerEnvironment();

        $schemas = AuditLogSchema::query()->orderBy('action')->get();
        $defined = [];

        foreach ($schemas as $schema) {
            $defined[$schema->action] = true;
        }

        // "Seen lately" is the newest thousand events, read off the environment's time
        // index — bounded however busy the environment is, and the honest size of
        // "recently" for a list whose job is to suggest the next schema to write.
        $seen = [];

        foreach (AuditLogEvent::query()->orderByDesc('occurred_at')->limit(1000)->pluck('action') as $action) {
            if (is_string($action) && ! isset($defined[$action])) {
                $seen[$action] = ($seen[$action] ?? 0) + 1;
            }
        }

        arsort($seen);
        $unschematized = [];

        foreach (array_slice($seen, 0, 20, true) as $action => $count) {
            $unschematized[] = [
                'action' => (string) $action,
                'count' => $count,
                'createHref' => route('environment.audit-logs.schemas.create', ['action' => (string) $action]),
            ];
        }

        return $this->page('console/audit-logs/schemas', 'Audit log schemas', [
            'help' => HelpProps::for(HelpTopic::AuditLogs),
            'schemas' => $schemas->map(static fn (AuditLogSchema $schema): array => [
                'action' => $schema->action,
                'version' => $schema->version,
                'targets' => array_map(static fn (array $target): string => self::text($target['type'] ?? null), $schema->targets ?? []),
                'updatedAt' => $schema->updated_at->toIso8601String(),
                'href' => route('environment.audit-logs.schemas.edit', $schema->action),
            ])->all(),
            'unschematized' => $unschematized,
            'settings' => [
                'retentionDays' => $policy->retentionDays(),
                'strictSchemas' => $policy->strict(),
                'updateHref' => route('environment.audit-logs.settings.update'),
            ],
            'createHref' => route('environment.audit-logs.schemas.create'),
            'eventsHref' => route('environment.audit-logs'),
        ]);
    }

    public function create(Request $request): Response
    {
        $this->scope->assertMayAdministerEnvironment();

        return $this->editor(null, $request->string('action')->toString());
    }

    public function store(Request $request): RedirectResponse
    {
        $this->scope->assertMayAdministerEnvironment();

        $body = $this->body($request);

        if ($body instanceof RedirectResponse) {
            return $body;
        }

        $result = $this->act(CreateAuditLogSchema::class, ['action' => $request->string('action')->toString(), ...$body], self::FIELDS, 'form');

        return $result instanceof RedirectResponse
            ? $result
            : to_route('environment.audit-logs.schemas.edit', $request->string('action')->toString())->with('status', 'Schema saved. Events of this action are checked against it from now on.');
    }

    public function edit(string $action): Response
    {
        $this->scope->assertMayAdministerEnvironment();

        $schema = AuditLogSchema::query()->where('action', $action)->first();

        abort_if($schema === null, 404);

        return $this->editor($schema, $action);
    }

    public function update(Request $request, string $action): RedirectResponse
    {
        $this->scope->assertMayAdministerEnvironment();

        $body = $this->body($request);

        if ($body instanceof RedirectResponse) {
            return $body;
        }

        $result = $this->act(UpdateAuditLogSchema::class, ['action' => $action, ...$body], self::FIELDS, 'form');

        return $result instanceof RedirectResponse
            ? $result
            : back()->with('status', 'Schema replaced with a new version.');
    }

    public function destroy(string $action): RedirectResponse
    {
        $this->scope->assertMayAdministerEnvironment();

        $result = $this->act(DeleteAuditLogSchema::class, ['action' => $action]);

        return $result instanceof RedirectResponse
            ? $result
            : to_route('environment.audit-logs.schemas')->with('status', 'Schema deleted.');
    }

    public function settings(Request $request): RedirectResponse
    {
        $this->scope->assertMayAdministerEnvironment();

        $result = $this->act(UpdateAuditLogSettings::class, [
            'retention_days' => $request->integer('retentionDays'),
            'strict_schemas' => $request->boolean('strictSchemas'),
        ], ['retention_days' => 'retentionDays', 'strict_schemas' => 'strictSchemas'], 'retentionDays');

        return $result instanceof RedirectResponse ? $result : back()->with('status', 'Audit log settings saved.');
    }

    private function editor(?AuditLogSchema $schema, string $action): Response
    {
        $recent = $action === '' ? [] : array_values(AuditLogEvent::query()
            ->where('action', $action)
            ->orderByDesc('occurred_at')
            ->limit(10)
            ->get()
            ->all());

        $names = $this->organizationNames($recent);
        $presented = $schema === null ? null : AuditLogSchemaFields::present($schema);

        return $this->page('console/audit-logs/schema', $schema === null ? 'New audit log schema' : $schema->action, [
            'schema' => $schema === null ? null : [
                'action' => $schema->action,
                'version' => $schema->version,
                'metadata' => self::pretty($presented['metadata'] ?? null),
                'actorMetadata' => self::pretty($presented['actor_metadata'] ?? null),
                'targets' => self::pretty($presented['targets'] ?? null),
                'updateHref' => route('environment.audit-logs.schemas.update', $schema->action),
                'destroyHref' => route('environment.audit-logs.schemas.destroy', $schema->action),
            ],
            'action' => $action,
            'storeHref' => route('environment.audit-logs.schemas.store'),
            'indexHref' => route('environment.audit-logs.schemas'),
            'recent' => array_map(fn (AuditLogEvent $event): array => $this->row($event, $names), $recent),
        ]);
    }

    /**
     * The three JSON boxes, decoded — a box that is not JSON is the person's mistake to fix
     * on that box, said before any action runs.
     *
     * @return array<string, mixed>|RedirectResponse
     */
    private function body(Request $request): array|RedirectResponse
    {
        $body = [];

        foreach (['metadata' => 'metadata', 'actor_metadata' => 'actorMetadata', 'targets' => 'targets'] as $input => $field) {
            $text = trim($request->string($field)->toString());

            if ($text === '') {
                $body[$input] = null;

                continue;
            }

            try {
                $body[$input] = json_decode($text, true, 32, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                return back()->withInput()->withErrors([$field => 'This is not valid JSON.']);
            }
        }

        return $body;
    }

    /**
     * @param  array<string, string>  $names  organization id => name, for the environment-wide list
     * @return array<string, mixed>
     */
    private function row(AuditLogEvent $event, array $names): array
    {
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
                'id' => self::text($target['id'] ?? null),
                'type' => self::text($target['type'] ?? null),
                'name' => isset($target['name']) && is_string($target['name']) ? $target['name'] : null,
            ], $event->targets),
            'location' => $event->location,
            'metadata' => self::pairs($event->metadata),
            'organization' => $names[$event->organization_id] ?? null,
            'sequence' => $event->sequence,
            'hash' => $event->hash,
        ];
    }

    /**
     * @param  list<AuditLogEvent>  $events
     * @return array<string, string>
     */
    private function organizationNames(array $events): array
    {
        $ids = array_values(array_unique(array_map(static fn (AuditLogEvent $event): string => $event->organization_id, $events)));

        if ($ids === []) {
            return [];
        }

        /** @var array<string, string> */
        return Organization::query()->whereIn('id', $ids)->pluck('name', 'id')->all();
    }

    private function filters(Request $request, ?string $organizationId): AuditLogFilters
    {
        $action = $request->string('action')->toString();

        return AuditLogFilters::from([
            'actions' => $action === '' ? [] : [$action],
            'actor_id' => $request->string('actor')->toString(),
            'target_id' => $request->string('target')->toString(),
            'range_start' => self::dayStart($request->string('from')->toString()),
            'range_end' => self::dayEnd($request->string('to')->toString()),
        ], $organizationId);
    }

    /** The environment console's export names its organization in the form, checked here. */
    private function postedOrganization(Request $request): ?string
    {
        $asked = $request->string('organization')->toString();

        return $asked !== '' && $this->scope->bindOrganization($asked) ? $asked : null;
    }

    /** A day picked in the page (`2026-10-08`), as the instant it starts, UTC. */
    private static function dayStart(string $day): ?string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) === 1 ? $day.'T00:00:00Z' : null;
    }

    /** The instant after a picked day ends: the range's end is exclusive. */
    private static function dayEnd(string $day): ?string
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) !== 1) {
            return null;
        }

        $next = date_create_immutable($day.'T00:00:00Z');

        return $next === false ? null : $next->modify('+1 day')->format('Y-m-d\TH:i:s\Z');
    }

    /**
     * @param  array<string, mixed>|null  $metadata
     * @return list<array{0: string, 1: string}>
     */
    private static function pairs(?array $metadata): array
    {
        $pairs = [];

        foreach ($metadata ?? [] as $key => $value) {
            $pairs[] = [(string) $key, match (true) {
                is_bool($value) => $value ? 'true' : 'false',
                is_scalar($value) => (string) $value,
                $value === null => 'null',
                default => (string) json_encode($value),
            }];
        }

        return $pairs;
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    private static function pretty(mixed $value): string
    {
        return $value === null ? '' : (string) json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
