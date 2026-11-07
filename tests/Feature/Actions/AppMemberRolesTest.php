<?php

declare(strict_types=1);

use App\Platform\Actions\ActionRefused;
use App\Platform\AppMemberRoles;
use Cbox\Id\AccessControl\Contracts\AppManifests;
use Cbox\Id\AccessControl\Contracts\Roles;
use Cbox\Id\AccessControl\Manifest\ManifestParser;
use Cbox\Id\AccessControl\Models\Role;
use Cbox\Id\AccessControl\Models\RoleAssignment;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Kernel\Audit\Models\AuditEntry;
use Cbox\Id\Kernel\Audit\ValueObjects\AuditActor;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\GenericEnvironment;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\Models\Client;
use Cbox\Id\OAuthServer\ValueObjects\NewClient;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Cbox\Id\Platform\Contracts\EnvironmentApiKeys;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    multiTenantDeployment();
    $tenant = provisionAccount();
    serveOnTestHost($tenant['environment']);
    app(EnvironmentContext::class)->set(GenericEnvironment::of($tenant['environment']->id));
    $this->key = app(EnvironmentApiKeys::class)->issue($tenant['environment']->id, 'Role management', ['roles:write'])->plaintext;
    $this->org = app(Organizations::class)->create(new NewOrganization('Customers', 'customers'));
    $this->person = app(Subjects::class)->create('reader@example.test', 'Reader')->id;
    app(Memberships::class)->add($this->org->id, $this->person, MembershipRole::Member);
    $this->client = app(ClientRegistry::class)->register(new NewClient('Sites'))->client->client_id;
    app(AppManifests::class)->sync($this->client, app(ManifestParser::class)->parse([
        'version' => '1',
        'permissions' => [['key' => 'sites:read'], ['key' => 'billing:manage']],
        'roles' => [
            ['key' => 'reader', 'name' => 'Reader', 'permissions' => ['sites:read']],
            ['key' => 'billing', 'name' => 'Billing', 'permissions' => ['billing:manage']],
            ['key' => 'support', 'name' => 'Support', 'tenant_assignable' => false, 'permissions' => ['sites:read']],
        ],
    ]));
    $this->reader = Role::query()->where('client_id', $this->client)->where('key', 'reader')->sole();
    app(Roles::class)->assign($this->org->id, $this->person, $this->reader->id);
    $this->version = hash('sha256', json_encode([$this->reader->id], JSON_THROW_ON_ERROR));
    $this->endpoint = '/api/v1/organizations/'.$this->org->id.'/members/'.$this->person.'/apps/'.$this->client.'/roles';
});

it('combines app roles and removes only this app access with an audit trail', function (): void {
    $other = app(Roles::class)->define(null, 'Other access');
    app(Roles::class)->assign($this->org->id, $this->person, $other->id);
    $this->withToken($this->key)->putJson($this->endpoint, ['roles' => ['reader', 'billing'], 'version' => $this->version])
        ->assertOk()->assertJsonCount(2, 'data');
    $ids = RoleAssignment::query()->where('user_id', $this->person)->where('role_id', '!=', $other->id)->pluck('role_id')->all();
    sort($ids);
    $version = hash('sha256', json_encode($ids, JSON_THROW_ON_ERROR));
    $this->withToken($this->key)->putJson($this->endpoint, ['roles' => [], 'version' => $version])
        ->assertOk()->assertJsonPath('data', []);
    expect(app(Memberships::class)->of($this->org->id, $this->person)->role)->toBe(MembershipRole::Member)
        ->and(RoleAssignment::query()->where('user_id', $this->person)->pluck('role_id')->all())->toBe([$other->id])
        ->and(AuditEntry::query()->where('action', 'member.app_roles_changed')->count())->toBe(2);
});

it('rejects stale updates without changing access', function (): void {
    $this->withToken($this->key)->putJson($this->endpoint, ['roles' => [], 'version' => str_repeat('0', 64)])
        ->assertStatus(409)->assertJsonPath('error', 'app_roles_changed');
    expect(RoleAssignment::query()->where('user_id', $this->person)->pluck('role_id')->all())->toBe([$this->reader->id]);
});

it('validates every role before changing access', function (array $roles): void {
    $this->withToken($this->key)->putJson($this->endpoint, ['roles' => $roles, 'version' => $this->version])
        ->assertUnprocessable();
    expect(RoleAssignment::query()->where('user_id', $this->person)->pluck('role_id')->all())->toBe([$this->reader->id])
        ->and(AuditEntry::query()->where('action', 'member.app_roles_changed')->exists())->toBeFalse();
})->with([
    'staff role' => [['billing', 'support']],
    'unknown role' => [['billing', 'missing']],
    'role id instead of manifest key' => [['billing', 'role_fake']],
]);

it('requires the full role selection instead of treating omission as revocation', function (): void {
    $this->withToken($this->key)->putJson($this->endpoint, ['version' => $this->version])->assertUnprocessable();
    expect(RoleAssignment::query()->where('user_id', $this->person)->count())->toBe(1);
});

it('cannot change another organizations member through this organization', function (): void {
    $other = app(Organizations::class)->create(new NewOrganization('Other', 'other'));
    $endpoint = str_replace($this->org->id, $other->id, $this->endpoint);
    $this->withToken($this->key)->putJson($endpoint, ['roles' => [], 'version' => $this->version])->assertNotFound();
    expect(RoleAssignment::query()->where('user_id', $this->person)->count())->toBe(1);
});

it('refuses a role declared by an app belonging to another organization', function (): void {
    $other = app(Organizations::class)->create(new NewOrganization('Other', 'other'));
    $foreign = app(ClientRegistry::class)->register(new NewClient('Private app', organizationId: $other->id))->client;
    $endpoint = str_replace($this->client, $foreign->client_id, $this->endpoint);
    $this->withToken($this->key)->putJson($endpoint, ['roles' => [], 'version' => hash('sha256', '[]')])->assertNotFound();
    expect(RoleAssignment::query()->where('user_id', $this->person)->count())->toBe(1);
});

it('does not revoke an externally managed role or partially apply a replacement', function (): void {
    RoleAssignment::query()->where('role_id', $this->reader->id)->update(['source' => 'pushed']);
    $this->withToken($this->key)->putJson($this->endpoint, ['roles' => ['billing'], 'version' => $this->version])
        ->assertUnprocessable()->assertJsonPath('error', 'role_managed_externally');
    expect(RoleAssignment::query()->where('user_id', $this->person)->pluck('role_id')->all())->toBe([$this->reader->id]);
});

it('does not let a key without role write authority change access', function (): void {
    $environment = app(EnvironmentContext::class)->requireEnvironment()->environmentKey();
    $key = app(EnvironmentApiKeys::class)->issue($environment, 'Read only', ['roles:read'])->plaintext;
    $this->withToken($key)->putJson($this->endpoint, ['roles' => [], 'version' => $this->version])->assertForbidden();
    expect(RoleAssignment::query()->where('user_id', $this->person)->count())->toBe(1);
});

it('rechecks the displayed role version after a concurrent direct grant commits', function (): void {
    $member = app(Memberships::class)->of($this->org->id, $this->person);
    $billing = Role::query()->where('client_id', $this->client)->where('key', 'billing')->sole();
    $roles = app(Roles::class);
    $replacements = app(AppMemberRoles::class);
    DB::commit();
    DB::disconnect();
    $directory = sys_get_temp_dir().'/app-role-version-'.bin2hex(random_bytes(8));
    mkdir($directory);
    $application = 'app-role-version-'.bin2hex(random_bytes(8));
    $pid = pcntl_fork();
    expect($pid)->not->toBe(-1);
    if ($pid === 0) {
        try {
            DB::select("select set_config('application_name', ?, false)", [$application]);
            $deadline = microtime(true) + 15;
            while (! file_exists($directory.'/go') && microtime(true) < $deadline) {
                usleep(1000);
            }
            $replacements->replace($member, $this->client, [], $this->version, AuditActor::operator('reviewer'));
            $result = 'replaced';
        } catch (ActionRefused $exception) {
            $result = $exception->getMessage();
        } catch (Throwable $exception) {
            $result = 'ERROR: '.$exception::class.': '.$exception->getMessage();
        }
        file_put_contents($directory.'/result', $result);
        posix_kill(posix_getpid(), SIGKILL);
    }
    try {
        DB::beginTransaction();
        $roles->assign($this->org->id, $this->person, $billing->id);
        touch($directory.'/go');
        $deadline = microtime(true) + 10;
        $blocked = false;
        do {
            DB::select('select pg_stat_clear_snapshot()');
            $waiting = DB::selectOne('select wait_event_type from pg_stat_activity where application_name = ?', [$application]);
            $blocked = $waiting !== null && $waiting->wait_event_type === 'Lock';
            if ($blocked || file_exists($directory.'/result')) {
                break;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        DB::commit();
        pcntl_waitpid($pid, $status);
        expect($blocked)->toBeTrue()
            ->and(file_get_contents($directory.'/result'))->toBe('This member\'s roles changed. Reload their access before saving.')
            ->and(RoleAssignment::query()->where('user_id', $this->person)->pluck('role_id')->all())
            ->toEqualCanonicalizing([$this->reader->id, $billing->id]);
    } finally {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        posix_kill($pid, SIGKILL);
        pcntl_waitpid($pid, $status, WNOHANG);
        foreach (glob($directory.'/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($directory);
        // The fork needed committed fixtures; rolling back a new transaction cannot
        // remove them. Rebuild this worker's test database before its next fixture.
        RefreshDatabaseState::$migrated = false;
        DB::beginTransaction();
    }
})->skip(fn (): bool => DB::connection()->getDriverName() !== 'pgsql' || ! function_exists('pcntl_fork'), 'needs PostgreSQL and pcntl');

it('does not retain committed fixtures from the preceding concurrency check', function (): void {
    $table = (new Client)->getTable();
    expect(DB::table($table)->where('name', 'Sites')->count())->toBe(1);
});
