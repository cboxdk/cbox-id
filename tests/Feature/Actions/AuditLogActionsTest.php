<?php

declare(strict_types=1);

use App\Actions\AuditLogs\CreateAuditLogEvents;
use App\Actions\AuditLogs\CreateAuditLogExport;
use App\Actions\AuditLogs\ListAuditLogEvents;
use App\Models\AdminPortalLink;
use App\Models\AuditLogs\AuditLogEvent;
use App\Models\AuditLogs\AuditLogExport;
use App\Models\AuditLogs\AuditLogSchema;
use App\Platform\Actions\ActionRefused;
use App\Platform\Actions\ActionRegistry;
use App\Platform\Actions\ActionRunner;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Idempotency\IdempotencyRecord;
use App\Platform\Actions\Principal\DelegatedTokenPrincipal;
use App\Platform\AdminPortal;
use App\Platform\AuditLogs\AuditLogChains;
use App\Platform\AuditLogs\Jobs\GenerateAuditLogExport;
use App\Platform\Enums\PortalIntent;
use App\Platform\Enums\PortalScope;
use App\Platform\EnvironmentApiContext;
use App\Platform\OAuth\ValueObjects\OrganizationChoice;
use App\Platform\PlatformAuth;
use Cbox\Id\AuditStreaming\Models\AuditStreamDelivery;
use Cbox\Id\Kernel\Audit\Models\AuditEntry;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\GenericEnvironment;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Cbox\Id\Platform\Contracts\EnvironmentApiKeys;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Support\FakeDelegatedTokens;

/*
|--------------------------------------------------------------------------
| Audit Logs — the events an app sends about its own customers.
|--------------------------------------------------------------------------
|
| Sent in batches, checked against an action's schema, chained per organization, read and
| exported by whoever may see that organization, pruned to the environment's retention —
| and never, from any door, another organization's.
*/

const AUDIT_ALL = ['audit_logs:write', 'audit_logs:read', 'audit_logs:export', 'audit_logs:manage', 'log_streams:write'];

/** @param  list<string>  $scopes */
function auditKey(array $scopes = AUDIT_ALL): string
{
    return app(EnvironmentApiKeys::class)->issue('env_test', 'Audit backend', $scopes)->plaintext;
}

function auditOrg(string $slug): string
{
    return app(Organizations::class)->create(new NewOrganization(ucfirst($slug), $slug))->id;
}

/**
 * @param  array<string, mixed>  $changes
 * @return array<string, mixed>
 */
function auditEvent(string $organizationId, array $changes = []): array
{
    return [
        'organization_id' => $organizationId,
        'action' => 'invoice.voided',
        'occurred_at' => Carbon::now('UTC')->subMinutes(5)->format('Y-m-d\TH:i:s.v\Z'),
        'actor' => ['id' => 'usr_ada', 'type' => 'user', 'name' => 'Ada'],
        'targets' => [['id' => 'inv_123', 'type' => 'invoice', 'name' => 'INV-123']],
        'context' => ['location' => '203.0.113.7', 'user_agent' => 'Mozilla/5.0'],
        'metadata' => ['currency' => 'EUR', 'total' => 12.5],
        ...$changes,
    ];
}

/** @param  list<array<string, mixed>>  $events */
function sendAuditEvents(string $key, array $events, ?string $idempotencyKey = null): TestResponse
{
    $request = test()->withToken($key);

    if ($idempotencyKey !== null) {
        $request = $request->withHeader('Idempotency-Key', $idempotencyKey);
    }

    return $request->postJson('/api/v1/audit-logs/events', ['events' => $events]);
}

it('records a batch, checks it against the action\'s schema, and lists it newest first', function (): void {
    $key = auditKey();
    $acme = auditOrg('acme-audit');

    $this->withToken($key)->postJson('/api/v1/audit-logs/schemas', [
        'action' => 'invoice.voided',
        'targets' => [['type' => 'invoice']],
        'metadata' => [
            'type' => 'object',
            'properties' => [
                'currency' => ['type' => 'string', 'enum' => ['EUR', 'DKK']],
                'total' => ['type' => 'number', 'minimum' => 0],
            ],
            'required' => ['currency'],
            'additionalProperties' => false,
        ],
    ])->assertCreated()->assertJsonPath('data.version', 1);

    // One bad event refuses the whole batch, and says where.
    sendAuditEvents($key, [
        auditEvent($acme),
        auditEvent($acme, ['metadata' => ['currency' => 'USD']]),
        auditEvent($acme, ['targets' => [['id' => 'u1', 'type' => 'user']]]),
    ])->assertUnprocessable()
        ->assertJsonPath('error', 'invalid_event')
        ->assertJsonFragment(['message' => '`currency` must be one of the values the schema lists. `user` is not a target type this action\'s schema allows (invoice).']);

    expect(AuditLogEvent::query()->count())->toBe(0);

    $first = sendAuditEvents($key, [
        auditEvent($acme, ['occurred_at' => Carbon::now('UTC')->subHour()->format('Y-m-d\TH:i:s.v\Z')]),
        auditEvent($acme, ['action' => 'report.exported', 'metadata' => ['rows' => 40]]),
    ])->assertCreated();

    [$older, $newer] = $first->json('data.events');

    expect($older['sequence'])->toBe(1)
        ->and($older['prev_hash'])->toBe(AuditLogChains::GENESIS)
        ->and($newer['sequence'])->toBe(2)
        ->and($newer['prev_hash'])->toBe($older['hash'])
        ->and($older['schema_version'])->toBe(1)
        ->and($newer['schema_version'])->toBeNull()
        ->and($older['metadata'])->toBe(['currency' => 'EUR', 'total' => 12.5]);

    $this->withToken($key)->getJson("/api/v1/audit-logs/events?organization_id={$acme}")->assertOk()
        ->assertJsonPath('data.0.id', $newer['id'])
        ->assertJsonPath('data.1.id', $older['id'])
        ->assertJsonPath('meta.has_more', false);

    $this->withToken($key)->getJson("/api/v1/audit-logs/events?organization_id={$acme}&actions[]=invoice.voided")->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $older['id']);
    $this->withToken($key)->getJson('/api/v1/audit-logs/events?target_id=inv_123&actor_id=usr_ada')->assertOk()
        ->assertJsonCount(2, 'data');

    // A cursor walks the list a page at a time.
    $page = $this->withToken($key)->getJson("/api/v1/audit-logs/events?organization_id={$acme}&limit=1")->assertOk();
    expect($page->json('meta.has_more'))->toBeTrue();
    $this->withToken($key)->getJson("/api/v1/audit-logs/events?organization_id={$acme}&limit=1&after=".$page->json('meta.next_cursor'))->assertOk()
        ->assertJsonPath('data.0.id', $older['id'])
        ->assertJsonPath('meta.has_more', false);

    // The chain holds, and nothing per event lands on the platform's own trail.
    $this->withToken($key)->getJson("/api/v1/audit-logs/verify?organization_id={$acme}")->assertOk()
        ->assertJsonPath('data.valid', true)
        ->assertJsonPath('data.verified_count', 2);
    expect(AuditEntry::query()->where('action', 'audit_log_schema.created')->count())->toBe(1)
        ->and(AuditEntry::query()->where('action', 'like', 'invoice.%')->count())->toBe(0);
});

it('refuses an action with no schema in strict mode, a future time and nested metadata', function (): void {
    $key = auditKey();
    $acme = auditOrg('acme-strict');

    sendAuditEvents($key, [auditEvent($acme, ['occurred_at' => Carbon::now('UTC')->addHour()->format('Y-m-d\TH:i:s\Z')])])
        ->assertUnprocessable()->assertJsonPath('error', 'invalid_event');
    sendAuditEvents($key, [auditEvent($acme, ['metadata' => ['nested' => ['a' => 1]]])])
        ->assertUnprocessable()->assertJsonPath('error', 'invalid_event');
    sendAuditEvents($key, [auditEvent($acme, ['occurred_at' => '2026-10-01 12:00'])])
        ->assertUnprocessable()->assertJsonPath('error', 'invalid_event');

    $this->withToken($key)->patchJson('/api/v1/audit-logs/settings', ['strict_schemas' => true, 'retention_days' => 90])->assertOk()
        ->assertJsonPath('data.strict_schemas', true)
        ->assertJsonPath('data.retention_days', 90);

    sendAuditEvents($key, [auditEvent($acme)])->assertUnprocessable()->assertJsonPath('error', 'invalid_event');

    expect(AuditLogEvent::query()->count())->toBe(0)
        ->and(AuditEntry::query()->where('action', 'audit_log_settings.updated')->sole()->context['changes']['retention_days'])
        ->toEqual(['from' => 365, 'to' => 90]);
});

it('refuses a schema keyword it would not enforce', function (): void {
    $key = auditKey();

    $this->withToken($key)->postJson('/api/v1/audit-logs/schemas', [
        'action' => 'invoice.voided',
        'metadata' => ['properties' => ['lines' => ['type' => 'array', 'items' => ['type' => 'string']]]],
    ])->assertUnprocessable()->assertJsonPath('error', 'invalid_schema');

    expect(AuditLogSchema::query()->count())->toBe(0);
});

it('replays an idempotent batch instead of recording it twice', function (): void {
    $key = auditKey();
    $acme = auditOrg('acme-replay');

    $event = auditEvent($acme);

    $first = sendAuditEvents($key, [$event], 'batch-1')->assertCreated();
    $again = sendAuditEvents($key, [$event], 'batch-1')->assertCreated();

    expect($again->headers->get('Idempotent-Replayed'))->toBe('true')
        ->and($again->json('data.events.0.id'))->toBe($first->json('data.events.0.id'))
        ->and(AuditLogEvent::query()->count())->toBe(1)
        ->and(IdempotencyRecord::query()->count())->toBe(1);
});

it('never shows or takes one organization\'s events through another\'s reach', function (): void {
    $key = auditKey();
    $acme = auditOrg('acme-iso');
    $globex = auditOrg('globex-iso');

    sendAuditEvents($key, [auditEvent($acme), auditEvent($globex, ['action' => 'globex.secret'])])->assertCreated();

    $this->withToken($key)->getJson("/api/v1/audit-logs/events?organization_id={$acme}")->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.organization_id', $acme);

    // A token one of Acme's admins signed in for is held to Acme, whatever it names.
    FakeDelegatedTokens::install();
    $runner = app(ActionRunner::class);
    $token = new DelegatedTokenPrincipal(
        subjectId: 'sub_ada',
        personName: 'Ada',
        clientId: 'claude-code',
        clientName: 'Claude Code',
        environmentId: 'env_test',
        scopes: ['audit_logs:read', 'audit_logs:write', 'audit_logs:export'],
        organization: new OrganizationChoice($acme, 'Acme', MembershipRole::Admin),
        customerConsole: false,
    );
    app(EnvironmentApiContext::class)->setDelegated($token);

    $own = $runner->run(ListAuditLogEvents::class, $token, []);
    expect(array_column($own->payload ?? [], 'organization_id'))->toBe([$acme]);

    // Another organization named outright answers as an unknown one: nothing confirms it.
    expect(fn () => $runner->run(ListAuditLogEvents::class, $token, ['organization_id' => $globex]))
        ->toThrow(ActionRefused::class, 'No organization with that organization_id exists in this environment.')
        ->and(fn () => $runner->run(CreateAuditLogEvents::class, $token, ['events' => [auditEvent($globex)]]))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => $runner->run(CreateAuditLogExport::class, $token, ['organization_id' => $globex]))
        ->toThrow(ActionRefused::class, 'No organization with that organization_id exists in this environment.');

    // Another environment's key sees none of it, and cannot write into this one's organizations.
    $foreign = app(EnvironmentContext::class)->runAs(GenericEnvironment::of('env_other'), fn (): string => app(EnvironmentApiKeys::class)->issue('env_other', 'Other', AUDIT_ALL)->plaintext);
    $this->withToken($foreign)->getJson('/api/v1/audit-logs/events')->assertUnauthorized();

    sendAuditEvents($key, [auditEvent('org_missing')])->assertUnprocessable()->assertJsonPath('error', 'organization_not_found');
})->group('security');

it('exports to CSV on the queue and hands the file out through a signed URL', function (): void {
    Storage::fake('local');
    Queue::fake();
    $key = auditKey();
    $acme = auditOrg('acme-export');
    $globex = auditOrg('globex-export');

    sendAuditEvents($key, [
        auditEvent($acme, ['actor' => ['id' => 'usr_eve', 'type' => 'user', 'name' => '=HYPERLINK("x")']]),
        auditEvent($globex),
    ])->assertCreated();

    $export = $this->withToken($key)->postJson('/api/v1/audit-logs/exports', ['organization_id' => $acme])
        ->assertCreated()
        ->assertJsonPath('data.state', 'pending')
        ->assertJsonPath('data.url', null)
        ->json('data');

    Queue::assertPushed(GenerateAuditLogExport::class, static fn (GenerateAuditLogExport $job): bool => $job->exportId === $export['id']);

    // What a worker does — with no environment of its own.
    app(EnvironmentContext::class)->set(null);
    (new GenerateAuditLogExport($export['id']))->handle(app(EnvironmentContext::class));
    app(EnvironmentContext::class)->set(GenericEnvironment::of('env_test'));

    $ready = $this->withToken($key)->getJson("/api/v1/audit-logs/exports/{$export['id']}")->assertOk()
        ->assertJsonPath('data.state', 'ready')
        ->assertJsonPath('data.row_count', 1)
        ->json('data');

    $csv = $this->get($ready['url'])->assertOk()->streamedContent();

    expect($csv)->toContain('invoice.voided')
        ->and($csv)->toContain($acme)
        ->and($csv)->not->toContain($globex)
        // A formula is written as text, never as something a spreadsheet would run.
        ->and($csv)->toContain("'=HYPERLINK");

    // The URL is the credential: without its signature, nothing.
    $this->get(route('audit-logs.exports.download', $export['id']))->assertForbidden();

    expect(AuditEntry::query()->where('action', 'audit_log_export.created')->sole()->organization_id)->toBe($acme);
});

/*
 * The worker writes the CSV and a web process streams it — on Kubernetes, two pods with two
 * disks. With the export disk left at `local` the web pod had no file, `readStream()` answered
 * null, and the download was a 200 with an empty CSV for an export that said `ready`.
 */
it('refuses a ready export whose file is not on the export disk, rather than handing out an empty CSV', function (): void {
    Storage::fake('local');
    Queue::fake();
    Log::spy();
    $key = auditKey();
    $acme = auditOrg('acme-missing-file');

    sendAuditEvents($key, [auditEvent($acme)])->assertCreated();

    $export = $this->withToken($key)->postJson('/api/v1/audit-logs/exports', ['organization_id' => $acme])
        ->assertCreated()
        ->json('data');

    // The worker — another pod — writes the file to ITS disk.
    app(EnvironmentContext::class)->set(null);
    (new GenerateAuditLogExport($export['id']))->handle(app(EnvironmentContext::class));
    app(EnvironmentContext::class)->set(GenericEnvironment::of('env_test'));

    $ready = $this->withToken($key)->getJson("/api/v1/audit-logs/exports/{$export['id']}")->assertOk()
        ->assertJsonPath('data.state', 'ready')
        ->json('data');

    // The web pod that answers the download never had it.
    Storage::disk('local')->delete((string) AuditLogExport::query()->findOrFail($export['id'])->path);

    $this->get($ready['url'])->assertNotFound();

    Log::shouldHaveReceived('error')->withArgs(
        static fn (string $message, array $context): bool => str_contains($message, 'missing from the export disk') && $context['export'] === $export['id'],
    )->once();
});

it('prunes past the retention from the front of each chain, which still verifies', function (): void {
    $key = auditKey();
    $acme = auditOrg('acme-prune');

    Carbon::setTestNow(Carbon::now()->subDays(40));
    sendAuditEvents($key, [auditEvent($acme), auditEvent($acme)])->assertCreated();
    Carbon::setTestNow();
    sendAuditEvents($key, [auditEvent($acme)])->assertCreated();

    $this->withToken($key)->patchJson('/api/v1/audit-logs/settings', ['retention_days' => 30])->assertOk();

    $this->artisan('audit-logs:prune')->assertSuccessful();

    expect(AuditLogEvent::query()->pluck('sequence')->all())->toBe([3])
        ->and(app(AuditLogChains::class)->verify($acme)->valid)->toBeTrue();

    // A kept event changed afterwards no longer verifies.
    DB::table('app_audit_events')->update(['metadata' => json_encode(['currency' => 'DKK', 'total' => 12.5])]);

    $verification = app(AuditLogChains::class)->verify($acme);

    expect($verification->valid)->toBeFalse()
        ->and($verification->reason)->toBe('hash')
        ->and($verification->brokenAtSequence)->toBe(3);
});

it('carries an organization\'s events to the log streams that organization owns', function (): void {
    $key = auditKey();
    $acme = auditOrg('acme-stream');

    $this->withToken($key)->postJson('/api/v1/log-streams', [
        'name' => 'Acme SIEM',
        'destination' => 'generic_json',
        'endpoint_url' => 'https://siem.acme.example/collector',
        'auth' => 'hmac',
        'organization_id' => $acme,
    ])->assertCreated();
    $this->withToken($key)->postJson('/api/v1/log-streams', [
        'name' => 'Operator SIEM',
        'destination' => 'generic_json',
        'endpoint_url' => 'https://siem.operator.example/collector',
        'auth' => 'hmac',
        'environment_wide' => true,
    ])->assertCreated();

    $before = AuditStreamDelivery::query()->count();
    sendAuditEvents($key, [auditEvent($acme), auditEvent($acme)])->assertCreated();

    // Two events, to Acme's own stream only — the environment's stream is the platform's trail.
    expect(AuditStreamDelivery::query()->count() - $before)->toBe(2);
});

it('opens the organization\'s audit logs in the Admin Portal only under a link that covers them', function (): void {
    installedDeployment();
    $acme = gateAdmin('acme-portal-logs');
    $globex = auditOrg('globex-portal-logs');
    $key = auditKey();

    sendAuditEvents($key, [auditEvent($acme, ['action' => 'acme.only']), auditEvent($globex, ['action' => 'globex.only'])])->assertCreated();

    // A link for SSO opens no audit logs.
    $sso = app(AdminPortal::class)->generate($acme, PortalScope::only(PortalIntent::Sso), 'sub_creator');
    $this->post(route('portal.enter.store', $sso))->assertRedirect(route('portal.setup'));
    $this->get(route('portal.audit-logs'))->assertForbidden();
    $this->get(route('portal.audit-logs.export'))->assertForbidden();

    // A link for the audit logs lands on them, with this organization's events alone.
    $logs = app(AdminPortal::class)->generate($acme, PortalScope::only(PortalIntent::AuditLogs), 'sub_creator');
    $this->post(route('portal.enter.store', $logs))->assertRedirect(route('portal.setup'));
    $this->get(route('portal.setup'))->assertRedirect(route('portal.audit-logs'));

    $props = $this->get(route('portal.audit-logs'))->assertOk()->viewData('page')['props'];

    expect(array_column($props['events'], 'action'))->toBe(['acme.only']);

    $csv = $this->get(route('portal.audit-logs.export'))->assertOk()->streamedContent();

    expect($csv)->toContain('acme.only')->and($csv)->not->toContain('globex.only')
        ->and(AuditEntry::query()->where('action', 'audit_log_export.downloaded')->sole()->organization_id)->toBe($acme)
        ->and(AdminPortalLink::query()->get()->filter(static fn (AdminPortalLink $link): bool => $link->intents === ['audit_logs'])->count())->toBe(1);
})->group('security');

it('mints an audit-logs portal link over the API', function (): void {
    $key = app(EnvironmentApiKeys::class)->issue('env_test', 'Portal', ['portal_links:write'])->plaintext;
    $acme = auditOrg('acme-portal-api');

    $this->withToken($key)->postJson("/api/v1/organizations/{$acme}/portal-links", ['intents' => ['audit_logs']])
        ->assertCreated()
        ->assertJsonPath('data.intents', ['audit_logs']);
});

it('shows an organization administrator their own organization\'s events in the console, and exports them', function (): void {
    Queue::fake();
    [, $acme] = integrationAdminForAuditLogs();
    $globex = auditOrg('globex-console');
    $key = auditKey();

    sendAuditEvents($key, [auditEvent($acme, ['action' => 'acme.only']), auditEvent($globex, ['action' => 'globex.only'])])->assertCreated();

    $props = $this->get(route('audit-logs'))->assertOk()->viewData('page')['props'];

    expect(array_column($props['events'], 'action'))->toBe(['acme.only']);

    $this->from(route('audit-logs'))->post(route('audit-logs.exports.store'))->assertRedirect(route('audit-logs'))->assertSessionHasNoErrors();

    expect(AuditLogExport::query()->sole()->organization_id)->toBe($acme);
})->group('security');

it('lets an environment administrator define a schema and set retention from the console', function (): void {
    craftedEnvAdmin();

    $this->from(route('environment.audit-logs.schemas.create'))->post(route('environment.audit-logs.schemas.store'), [
        'action' => 'invoice.voided',
        'metadata' => '{"properties": {"currency": {"type": "string"}}, "required": ["currency"]}',
        'actorMetadata' => '',
        'targets' => '[{"type": "invoice"}]',
    ])->assertRedirect(route('environment.audit-logs.schemas.edit', 'invoice.voided'));

    $this->from(route('environment.audit-logs.schemas.edit', 'invoice.voided'))->put(route('environment.audit-logs.schemas.update', 'invoice.voided'), [
        'metadata' => 'not json',
    ])->assertSessionHasErrors('metadata');

    $this->from(route('environment.audit-logs.schemas'))->patch(route('environment.audit-logs.settings.update'), [
        'retentionDays' => 30,
        'strictSchemas' => true,
    ])->assertSessionHasNoErrors();

    $this->get(route('environment.audit-logs.schemas'))->assertOk();
    $this->get(route('environment.audit-logs'))->assertOk();

    expect(AuditLogSchema::query()->sole()->version)->toBe(1)
        ->and(AuditEntry::query()->where('action', 'audit_log_schema.created')->sole()->actor_id)->not->toBeNull();

    $this->delete(route('environment.audit-logs.schemas.destroy', 'invoice.voided'))->assertRedirect(route('environment.audit-logs.schemas'));

    expect(AuditLogSchema::query()->count())->toBe(0);
});

it('offers every audit-log action as an MCP tool, with the danger and scope it declares', function (): void {
    $registry = app(ActionRegistry::class);

    expect($registry->named('audit_logs.events.create')->danger)->toBe(Danger::Write)
        ->and($registry->named('audit_logs.events.list')->scope)->toBe('audit_logs:read')
        ->and($registry->named('audit_logs.schemas.delete')->danger)->toBe(Danger::Destructive)
        ->and($registry->named('audit_logs.settings.update')->danger)->toBe(Danger::Destructive);

    $tools = mcpTools(auditKey());

    expect($tools)->toHaveKeys([
        'audit_logs_events_create',
        'audit_logs_events_list',
        'audit_logs_exports_create',
        'audit_logs_exports_get',
        'audit_logs_schemas_create',
        'audit_logs_settings_update',
        'audit_logs_verify',
    ]);
});

/**
 * An organization's owner signed in to its own console; returns [subject id, organization id].
 *
 * @return array{0: string, 1: string}
 */
function integrationAdminForAuditLogs(): array
{
    installedDeployment();
    $organizationId = gateAdmin('acme-console-logs');

    return [(string) session(PlatformAuth::SESSION_KEY), $organizationId];
}
