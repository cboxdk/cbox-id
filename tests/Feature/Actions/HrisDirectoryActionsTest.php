<?php

declare(strict_types=1);

use App\Actions\Directories\DirectoryFields;
use App\Platform\Actions\ActionTrail;
use App\Platform\AdminPortal;
use App\Platform\CurrentUser;
use App\Platform\Enums\PortalIntent;
use App\Platform\Enums\PortalScope;
use App\Platform\PlatformAuth;
use Cbox\Id\Directory\Contracts\Directories;
use Cbox\Id\Directory\Contracts\PullDirectories;
use Cbox\Id\Directory\Enums\DirectoryProvider;
use Cbox\Id\Directory\Enums\DirectorySyncStatus;
use Cbox\Id\Directory\Jobs\SyncPullDirectory;
use Cbox\Id\Directory\Models\Directory;
use Cbox\Id\Directory\Models\DirectoryGroup;
use Cbox\Id\Directory\Models\DirectoryUser;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Kernel\Audit\Enums\ActorType;
use Cbox\Id\Kernel\Audit\Models\AuditEntry;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\GenericEnvironment;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Cbox\Id\Platform\Contracts\EnvironmentApiKeys;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/*
|--------------------------------------------------------------------------
| HR-system directory sync, as actions: connect, pull now, pace, new credentials.
|--------------------------------------------------------------------------
|
| The same action from the management API, MCP, the console and the Admin Portal, with
| BambooHR's API replayed by Http::fake. The credentials are checked with the HR system
| before anything is stored, sealed, and never returned anywhere — not in an answer, not in
| a tool result, not on the trail, not in the session after a refusal.
*/

const HRIS_SCOPES = ['directory_sync:read', 'directory_sync:write'];

const HRIS_SECRET = 'bamboo-secret-key-do-not-echo';

/** @return array{0: string, 1: string} */
function hrisKey(array $scopes = HRIS_SCOPES, string $environmentId = 'env_test'): array
{
    $issued = app(EnvironmentApiKeys::class)->issue($environmentId, 'HR worker', $scopes);

    return [$issued->plaintext, (string) $issued->key->id];
}

function hrisOrg(string $slug = 'acme-hris'): string
{
    return app(Organizations::class)->create(new NewOrganization('Acme', $slug.'-'.Str::lower(Str::random(4))))->id;
}

/** BambooHR answering as documented: two active people, one leaver, two departments. */
function hrisFakeBamboo(int $status = 200): void
{
    // A fresh fake each time: stubs added to an existing fake rank BEHIND the earlier ones.
    Http::swap(new HttpFactory);

    Http::fake([
        'acme.bamboohr.com/api/v1/employees?*filter%5Bstatus%5D=active*' => Http::response(['data' => [
            ['employeeId' => '1', 'firstName' => 'Ada', 'lastName' => 'L', 'status' => 'Active', 'workEmail' => 'ada@acme.test', 'departmentId' => '45', 'departmentName' => 'Engineering', 'reportsToId' => '2', 'jobTitleName' => 'Engineer'],
            ['employeeId' => '2', 'firstName' => 'Grace', 'lastName' => 'H', 'status' => 'Active', 'workEmail' => 'grace@acme.test', 'departmentId' => '45', 'departmentName' => 'Engineering'],
        ], 'meta' => ['page' => ['nextCursor' => null]]], $status),
        'acme.bamboohr.com/api/v1/employees?*filter%5Bstatus%5D=inactive*' => Http::response(['data' => [
            ['employeeId' => '9', 'status' => 'Inactive', 'workEmail' => 'gone@acme.test', 'terminationDate' => '2020-01-31'],
        ], 'meta' => ['page' => ['nextCursor' => null]]]),
        'acme.bamboohr.com/api/v1/employees?*' => Http::response(['data' => [], 'meta' => ['page' => ['nextCursor' => null]]], $status),
        'acme.bamboohr.com/api/v1/meta/lists' => Http::response([['alias' => 'department', 'options' => [['id' => 45, 'name' => 'Engineering', 'archived' => 'no'], ['id' => 46, 'name' => 'Finance', 'archived' => 'no']]]]),
    ]);
}

function hrisBody(string $org, array $changes = []): array
{
    return [
        'organization_id' => $org,
        'provider' => 'bamboohr',
        'credentials' => ['subdomain' => 'https://acme.bamboohr.com/', 'api_key' => HRIS_SECRET],
        'custom_attributes' => ['location'],
        ...$changes,
    ];
}

/**
 * An organization's administrator, signed in to its own console.
 *
 * @return array{0: string, 1: string} subject id, organization id
 */
function hrisOrgAdmin(string $slug = 'acme-hris-console'): array
{
    $subject = app(Subjects::class)->create("admin@{$slug}.test", 'HR Admin', 'supersecret123');
    app(Subjects::class)->markEmailVerified($subject->id, (string) $subject->email);
    $subject = app(Subjects::class)->find($subject->id) ?? $subject;

    $org = app(Organizations::class)->create(new NewOrganization('Acme', $slug));
    app(Memberships::class)->add($org->id, $subject->id, MembershipRole::Owner);
    $session = app(SessionManager::class)->start($subject->id, $org->id, ['pwd']);
    app(CurrentUser::class)->set($subject, $session, $org, MembershipRole::Owner);
    session([PlatformAuth::SESSION_KEY => $session->id]);

    return [$subject->id, $org->id];
}

function hrisPortal(string $organizationId): void
{
    $token = app(AdminPortal::class)->generate($organizationId, PortalScope::of([PortalIntent::Dsync]), 'sub_minter');

    expect(app(AdminPortal::class)->redeem($token))->not->toBeNull();
}

function hrisPortalWrite(string $from, string $url, array $data = []): TestResponse
{
    return inertiaRequest(fn (): TestResponse => test()->from($from)->post($url, $data));
}

beforeEach(function (): void {
    Sleep::fake();
});

it('connects BambooHR over the API: verified first, sealed, never echoed, and the first sync queued', function (): void {
    Queue::fake();
    hrisFakeBamboo();
    [$key, $keyId] = hrisKey();
    $org = hrisOrg();

    $response = $this->withToken($key)->postJson('/api/v1/directories/hris', hrisBody($org))
        ->assertCreated()
        ->assertJsonPath('data.provider', 'bamboohr')
        ->assertJsonPath('data.pull', true)
        ->assertJsonPath('data.hris', true)
        ->assertJsonPath('data.name', 'BambooHR')
        ->assertJsonPath('data.sync_interval_minutes', 60)
        ->assertJsonPath('data.custom_attributes', ['location'])
        ->assertJsonPath('data.scim_base_url', null);

    expect($response->getContent())->not->toContain(HRIS_SECRET);

    $directory = Directory::query()->whereKey($response->json('data.id'))->sole();
    $sealed = (string) DB::table('directories')->where('id', $directory->id)->value('credentials');

    expect($directory->provider)->toBe(DirectoryProvider::BambooHr)
        ->and($sealed)->not->toBe('')->not->toContain(HRIS_SECRET)
        ->and(json_encode(AuditEntry::query()->get()->toArray()))->not->toContain(HRIS_SECRET);

    // Probed with the key, against the subdomain the pasted address reduced to.
    Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://acme.bamboohr.com/api/v1/employees')
        && $request->hasHeader('Authorization', 'Basic '.base64_encode(HRIS_SECRET.':x')));

    Queue::assertPushed(SyncPullDirectory::class, fn (SyncPullDirectory $job): bool => $job->directoryId === $directory->id && ! str_contains(serialize($job), HRIS_SECRET));

    $trail = AuditEntry::query()->where('action', 'directory.connected')->sole();
    expect($trail->actor_type)->toBe(ActorType::Service)->and($trail->actor_id)->toBe($keyId)
        ->and($trail->context['provider'])->toBe('bamboohr');
});

it('runs the first sync and reports it on the directory', function (): void {
    hrisFakeBamboo();
    [$key] = hrisKey();
    $org = hrisOrg();

    $id = $this->withToken($key)->postJson('/api/v1/directories/hris', hrisBody($org))->assertCreated()->json('data.id');

    $read = $this->withToken($key)->getJson("/api/v1/directories/{$id}")->assertOk()
        ->assertJsonPath('data.last_sync_status', 'succeeded')
        ->assertJsonPath('data.last_sync_stats.mode', 'full')
        ->assertJsonPath('data.last_sync_stats.provisioned', 2)
        ->assertJsonPath('data.last_sync_stats.skipped', 1);

    expect($read->getContent())->not->toContain(HRIS_SECRET)
        ->and($read->json('data.next_sync_at'))->toBeString()
        ->and(DirectoryUser::query()->where('directory_id', $id)->where('active', true)->count())->toBe(2)
        ->and(DirectoryGroup::query()->where('directory_id', $id)->pluck('display_name')->sort()->values()->all())->toBe(['Engineering', 'Finance']);
});

it('refuses credentials it cannot use, and stores nothing', function (): void {
    [$key] = hrisKey();
    $org = hrisOrg();

    hrisFakeBamboo(401);

    $this->withToken($key)->postJson('/api/v1/directories/hris', hrisBody($org))
        ->assertUnprocessable()
        ->assertJsonPath('error', 'credentials_rejected');

    $this->withToken($key)->postJson('/api/v1/directories/hris', hrisBody($org, ['credentials' => ['subdomain' => 'acme']]))
        ->assertUnprocessable()
        ->assertJsonPath('error', 'incomplete_credentials');

    $this->withToken($key)->postJson('/api/v1/directories/hris', hrisBody($org, ['credentials' => ['subdomain' => 'evil.example/x?', 'api_key' => 'k']]))
        ->assertUnprocessable()
        ->assertJsonPath('error', 'incomplete_credentials');

    $this->withToken($key)->postJson('/api/v1/directories/hris', hrisBody($org, ['provider' => 'google_workspace']))
        ->assertUnprocessable();

    expect(Directory::query()->count())->toBe(0);
});

it('pulls on demand, paces the schedule, and passes HR fields through', function (): void {
    Queue::fake();
    hrisFakeBamboo();
    [$key] = hrisKey();
    $org = hrisOrg();
    // Registered directly: connecting queues a first sync, and a queued sync of the same
    // directory is unique — a second press while it waits is the same job.
    $id = app(Directories::class)->registerPull($org, 'BambooHR', DirectoryProvider::BambooHr, ['subdomain' => 'acme', 'api_key' => HRIS_SECRET])->id;
    app(PullDirectories::class)->setHrisOptions(Directory::query()->findOrFail($id), ['location']);
    $scim = $this->withToken($key)->postJson('/api/v1/directories', ['organization_id' => $org, 'name' => 'Okta'])->json('data.id');

    $this->withToken($key)->postJson("/api/v1/directories/{$id}/sync", ['full' => true])->assertStatus(202);
    Queue::assertPushed(SyncPullDirectory::class, fn (SyncPullDirectory $job): bool => $job->directoryId === $id && $job->full);

    $this->withToken($key)->postJson("/api/v1/directories/{$scim}/sync")->assertUnprocessable()->assertJsonPath('error', 'not_pull');

    $this->withToken($key)->patchJson("/api/v1/directories/{$id}/sync-settings", ['sync_interval_minutes' => 30, 'custom_attributes' => ['location', 'costCenter']])
        ->assertOk()
        ->assertJsonPath('data.sync_interval_minutes', 30)
        ->assertJsonPath('data.custom_attributes', ['location', 'costCenter']);

    $this->withToken($key)->patchJson("/api/v1/directories/{$id}/sync-settings", ['sync_interval_minutes' => 5])->assertUnprocessable();
    $this->withToken($key)->patchJson("/api/v1/directories/{$scim}/sync-settings", ['sync_interval_minutes' => 30])->assertUnprocessable()->assertJsonPath('error', 'not_pull');

    expect(AuditEntry::query()->where('action', 'directory.sync_requested')->count())->toBe(1)
        ->and(AuditEntry::query()->where('action', 'directory.sync_configured')->count())->toBe(1);
});

it('replaces the credentials only once the new ones work', function (): void {
    Queue::fake();
    hrisFakeBamboo();
    [$key] = hrisKey();
    $org = hrisOrg();
    $id = $this->withToken($key)->postJson('/api/v1/directories/hris', hrisBody($org))->json('data.id');
    $before = DB::table('directories')->where('id', $id)->value('credentials');

    Http::swap(new HttpFactory);
    Http::fake(['acme.bamboohr.com/*' => Http::response([], 403)]);

    $this->withToken($key)->putJson("/api/v1/directories/{$id}/credentials", ['credentials' => ['subdomain' => 'acme', 'api_key' => 'wrong']])
        ->assertUnprocessable()->assertJsonPath('error', 'credentials_rejected');
    expect(DB::table('directories')->where('id', $id)->value('credentials'))->toBe($before);

    hrisFakeBamboo();

    $response = $this->withToken($key)->putJson("/api/v1/directories/{$id}/credentials", ['credentials' => ['subdomain' => 'acme', 'api_key' => 'rotated-key-xyz']])
        ->assertOk();

    expect($response->getContent())->not->toContain('rotated-key-xyz')
        ->and(DB::table('directories')->where('id', $id)->value('credentials'))->not->toBe($before)->not->toContain('rotated-key-xyz')
        ->and(AuditEntry::query()->where('action', 'directory.credentials_replaced')->sole()->context)->not->toHaveKey('credentials');
});

it('answers 404 for another tenant\'s directory on every HR action, and refuses a reader', function (): void {
    Queue::fake();
    [$key] = hrisKey();
    [$reader] = hrisKey(['directory_sync:read']);
    $org = hrisOrg();

    $elsewhere = app(EnvironmentContext::class)->runAs(GenericEnvironment::of('env_other'), function (): string {
        $org = app(Organizations::class)->create(new NewOrganization('Globex', 'globex-'.Str::lower(Str::random(4))));

        return app(Directories::class)->registerPull($org->id, 'BambooHR', DirectoryProvider::BambooHr, ['subdomain' => 'globex', 'api_key' => 'theirs'])->id;
    });

    $this->withToken($key)->postJson("/api/v1/directories/{$elsewhere}/sync")->assertNotFound();
    $this->withToken($key)->patchJson("/api/v1/directories/{$elsewhere}/sync-settings", ['sync_interval_minutes' => 30])->assertNotFound();
    $this->withToken($key)->putJson("/api/v1/directories/{$elsewhere}/credentials", ['credentials' => ['subdomain' => 'acme', 'api_key' => 'x']])->assertNotFound();
    $this->withToken($key)->getJson("/api/v1/directories/{$elsewhere}")->assertNotFound();

    $this->withToken($reader)->postJson('/api/v1/directories/hris', hrisBody($org))->assertForbidden();

    Queue::assertNothingPushed();
    expect(Directory::query()->where('organization_id', $org)->count())->toBe(0);
})->group('security');

it('connects an HR system as an MCP tool without the key ever coming back', function (): void {
    Queue::fake();
    hrisFakeBamboo();
    [$key] = hrisKey();
    $org = hrisOrg();

    $result = mcpCall($key, 'directories_hris_connect', hrisBody($org));

    expect(json_encode($result))->not->toContain(HRIS_SECRET)
        ->and(Directory::query()->where('organization_id', $org)->sole()->provider)->toBe(DirectoryProvider::BambooHr);
});

it('connects from the console, keeps the secrets out of the session on a refusal, and shows the sync', function (): void {
    Queue::fake();
    [, $orgId] = hrisOrgAdmin();
    confirmConsoleStepUp();

    $create = $this->get(route('directories.create'))->assertOk()->inertiaProps('providers');
    $bamboo = collect($create)->firstWhere('value', 'bamboohr');

    expect($bamboo['hris'])->toBeTrue()
        ->and(array_column($bamboo['setup']['credentials'], 'key'))->toBe(['subdomain', 'api_key'])
        ->and(collect($bamboo['setup']['credentials'])->firstWhere('key', 'api_key')['secret'])->toBeTrue();

    hrisFakeBamboo(401);
    $this->from(route('directories.create'))->post(route('directories.hris'), [
        'provider' => 'bamboohr', 'credentials' => ['subdomain' => 'acme', 'api_key' => HRIS_SECRET],
    ])->assertSessionHasErrors('credentials');

    expect(json_encode(session()->get('_old_input')))->not->toContain(HRIS_SECRET);

    hrisFakeBamboo();
    $this->from(route('directories.create'))->post(route('directories.hris'), [
        'provider' => 'bamboohr', 'credentials' => ['subdomain' => 'acme', 'api_key' => HRIS_SECRET], 'customAttributes' => "location\ncostCenter",
    ])->assertSessionHasNoErrors();

    $directory = Directory::query()->where('organization_id', $orgId)->sole();
    expect(DirectoryFields::hrisOptions($directory)['custom_attributes'])->toBe(['location', 'costCenter']);

    $show = (array) $this->get(route('directories.show', $directory->id))->assertOk()->inertiaProps();
    expect($show['directory']['hris'])->toBeTrue()
        ->and($show['directory']['sync']['intervalMinutes'])->toBe(60)
        ->and(json_encode($show))->not->toContain(HRIS_SECRET);

    $this->from(route('directories.show', $directory->id))->post(route('directories.sync', $directory->id))->assertSessionHasNoErrors();
    Queue::assertPushed(SyncPullDirectory::class, fn (SyncPullDirectory $job): bool => $job->directoryId === $directory->id);
    expect(AuditEntry::query()->where('action', 'directory.sync_requested')->count())->toBe(1);
});

it('lets the customer\'s HR admin connect their HR system from the Admin Portal', function (): void {
    Queue::fake();
    installedDeployment();
    $org = hrisOrg();
    hrisPortal($org);

    $props = (array) $this->get(route('portal.hris', ['provider' => 'personio']))->assertOk()->inertiaProps();

    expect(array_column($props['guides'], 'key'))->toBe(['workday', 'bamboohr', 'rippling', 'hibob', 'personio'])
        ->and($props['provider'])->toBe('personio')
        ->and(collect($props['guides'])->firstWhere('key', 'personio')['steps'])->not->toBeEmpty();

    hrisFakeBamboo(401);
    hrisPortalWrite(route('portal.hris'), route('portal.hris.store'), [
        'provider' => 'bamboohr', 'credentials' => ['subdomain' => 'acme', 'api_key' => HRIS_SECRET],
    ])->assertSessionHasErrors('credentials');

    expect(json_encode(session()->get('_old_input')))->not->toContain(HRIS_SECRET);

    hrisFakeBamboo();
    hrisPortalWrite(route('portal.hris'), route('portal.hris.store'), [
        'provider' => 'bamboohr', 'credentials' => ['subdomain' => 'acme', 'api_key' => HRIS_SECRET],
    ])->assertSessionHasNoErrors();

    $directory = Directory::query()->where('organization_id', $org)->sole();
    $trail = AuditEntry::query()->where('action', 'directory.connected')->sole();

    expect($directory->provider)->toBe(DirectoryProvider::BambooHr)
        ->and($trail->context[ActionTrail::VIA])->toBe('portal');

    $listed = (array) $this->get(route('portal.hris'))->assertOk()->inertiaProps('directories');
    expect($listed[0]['provider'])->toBe('bamboohr')->and(json_encode($listed))->not->toContain(HRIS_SECRET);

    hrisPortalWrite(route('portal.hris'), route('portal.hris.sync', $directory->id))->assertSessionHasNoErrors();
    Queue::assertPushed(SyncPullDirectory::class);
});

it('keeps the HR-system page behind the Directory Sync intent', function (): void {
    installedDeployment();
    $org = hrisOrg();
    $token = app(AdminPortal::class)->generate($org, PortalScope::of([PortalIntent::LogStreams]), 'sub_minter');
    app(AdminPortal::class)->redeem($token);

    $this->get(route('portal.hris'))->assertNotFound();
    hrisPortalWrite(route('portal.setup'), route('portal.hris.store'), ['provider' => 'bamboohr'])->assertNotFound();
});

it('marks a run partial on the directory, by employee id and never by email', function (): void {
    hrisFakeBamboo();
    [$key] = hrisKey();
    $org = hrisOrg();

    // Somebody already signed up with Grace's work address.
    app(Subjects::class)->create('grace@acme.test', 'Grace');

    $id = $this->withToken($key)->postJson('/api/v1/directories/hris', hrisBody($org))->json('data.id');

    $read = $this->withToken($key)->getJson("/api/v1/directories/{$id}")->assertOk()
        ->assertJsonPath('data.last_sync_status', DirectorySyncStatus::Partial->value)
        ->assertJsonPath('data.last_sync_stats.failed', 1)
        ->assertJsonPath('data.last_sync_stats.failures.0.external_id', '2');

    expect($read->getContent())->not->toContain('grace@acme.test');
});
