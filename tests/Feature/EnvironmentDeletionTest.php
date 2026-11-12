<?php

declare(strict_types=1);

use App\Platform\Environments\EnvironmentErasure;
use App\Platform\Environments\EnvironmentNotErasable;
use App\Platform\Environments\ErasedTables;
use App\Platform\OrganizationActivity;
use App\Platform\Realm\FlipSchema;
use App\Platform\Sudo;
use Cbox\Id\Kernel\Audit\Contracts\AuditLog;
use Cbox\Id\Kernel\Audit\Enums\ActorType;
use Cbox\Id\Kernel\Audit\Models\AuditEntry;
use Cbox\Id\Kernel\Audit\ValueObjects\AuditEvent;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentResolver;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Platform\Contracts\OrganizationApiKeys;
use Cbox\Id\Platform\Contracts\Projects;
use Cbox\Id\Platform\Models\Project;
use Cbox\Id\Platform\PlatformRoot;
use Cbox\Id\Platform\TenantProvisioner;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

/*
 * DELETING AN ENVIRONMENT. It removes the environment's people, apps, keys, sessions and
 * settings, so the tests are about two things: that EVERYTHING of the environment goes and
 * nothing of any other does, and that only the right person, having confirmed, can ask.
 */

beforeEach(function (): void {
    installedDeployment();
});

/**
 * An account with a second environment, "Staging", to delete.
 *
 * @return array{organization: object, ownerId: string, project: Project, kept: Environment, staging: Environment}
 */
function accountWithStaging(): array
{
    ['organization' => $organization, 'subjectId' => $ownerId, 'project' => $project, 'environment' => $kept] = provisionAccount();
    $staging = app(TenantProvisioner::class)->addEnvironment($project, 'Staging');

    return ['organization' => $organization, 'ownerId' => $ownerId, 'project' => $project, 'kept' => $kept, 'staging' => $staging];
}

/** @return list<AuditEntry> */
function environmentDeletions(string $organizationId): array
{
    return array_values(app(OrganizationActivity::class)->recent($organizationId)
        ->filter(static fn (AuditEntry $entry): bool => $entry->action === 'organization.environment_deleted')
        ->all());
}

/**
 * One made-up row in `$table` for the environment: every column that must have a value
 * gets one of its type, and a foreign key points at the made-up row of its parent.
 *
 * @param  array<string, array<string, mixed>>  $made  the rows already made, by table
 * @return array<string, mixed>|null the row, or null when the table would not take one
 */
function syntheticRow(string $table, string $environmentColumn, string $environmentId, array $made): ?array
{
    $row = [];

    foreach (Schema::getColumns($table) as $column) {
        $name = $column['name'];

        if ($name === $environmentColumn) {
            $row[$name] = $environmentId;

            continue;
        }

        if ($column['nullable'] || $column['default'] !== null || $column['auto_increment']) {
            continue;
        }

        $type = strtolower($column['type_name']);
        preg_match('/\((\d+)\)/', $column['type'], $length);
        $max = isset($length[1]) ? (int) $length[1] : 26;

        $row[$name] = match (true) {
            str_contains($type, 'json') => '{}',
            str_contains($type, 'bool') => false,
            $type === 'uuid' => (string) Str::uuid(),
            str_contains($type, 'int') => in_array($type, ['int2', 'smallint', 'tinyint'], true) ? random_int(1, 100) : random_int(1, 2_000_000_000),
            str_contains($type, 'time') || str_contains($type, 'date') => now(),
            in_array($type, ['numeric', 'decimal', 'float', 'double', 'real', 'float4', 'float8'], true) => 1,
            default => substr(strtolower((string) Str::ulid()), -min($max, 26)),
        };
    }

    foreach (Schema::getForeignKeys($table) as $foreign) {
        $parent = $made[$foreign['foreign_table']] ?? null;

        foreach ($foreign['columns'] as $position => $column) {
            $references = $foreign['foreign_columns'][$position] ?? null;

            if ($parent === null || $references === null || ! array_key_exists($references, $parent)) {
                return null;
            }

            $row[$column] = $parent[$references];
        }
    }

    try {
        DB::transaction(static fn () => DB::table($table)->insert($row));
    } catch (Throwable) {
        return null;
    }

    return $row;
}

/**
 * Fill every table that names an environment with a row for this one, the kept trail
 * excepted (a made-up row there would be a broken chain).
 *
 * @return array{filled: list<string>, refused: list<string>}
 */
function fillEnvironment(string $environmentId): array
{
    $schema = new FlipSchema;
    $tables = array_values(array_filter(
        $schema->tables(),
        static fn (string $table): bool => $schema->environmentColumn($table) !== null && ! in_array($table, ErasedTables::kept(), true),
    ));

    // Parents before the tables whose foreign keys point at them.
    usort($tables, static fn (string $a, string $b): int => count(Schema::getForeignKeys($a)) <=> count(Schema::getForeignKeys($b)));

    $made = [];
    $refused = [];

    foreach ($tables as $table) {
        $row = syntheticRow($table, (string) $schema->environmentColumn($table), $environmentId, $made);

        if ($row === null) {
            $refused[] = $table;
        } else {
            $made[$table] = $row;
        }
    }

    return ['filled' => array_keys($made), 'refused' => $refused];
}

/** @return array<string, int> rows per table that name the environment in their environment column */
function rowsOf(string $environmentId): array
{
    $schema = new FlipSchema;
    $rows = [];

    foreach ($schema->tables() as $table) {
        $column = $schema->environmentColumn($table);

        if ($column !== null) {
            $rows[$table] = DB::table($table)->where($column, $environmentId)->count();
        }
    }

    return $rows;
}

it('has a decision for every table that names an environment, and none for a table that is gone', function (): void {
    $schema = new FlipSchema;
    $decided = ErasedTables::all();

    $inSchema = array_values(array_filter($schema->tables(), static fn (string $table): bool => $schema->environmentColumn($table) !== null));

    // A migration that adds a table with `environment_id` fails here until somebody decides
    // what deleting an environment does with it.
    expect(array_values(array_diff($inSchema, array_keys($decided))))->toBe([])
        ->and(array_values(array_diff(array_keys($decided), $inSchema)))->toBe([]);

    foreach (ErasedTables::CHILDREN as $table => $parents) {
        expect($schema->has($table))->toBeTrue("{$table} is not a table")
            ->and($schema->environmentColumn($table))->toBeNull("{$table} has an environment column and belongs in the list itself");

        foreach ($parents as $column => [$parentTable, $parentColumn]) {
            expect($schema->hasColumn($table, $column))->toBeTrue("{$table}.{$column}")
                ->and($schema->hasColumn($parentTable, $parentColumn))->toBeTrue("{$parentTable}.{$parentColumn}")
                ->and($decided[$parentTable]['decision'] ?? null)->toBe(ErasedTables::ERASE);
        }
    }
});

it('removes every row of the environment, in every table, and nothing of another environment', function (): void {
    ['kept' => $kept, 'staging' => $staging] = accountWithStaging();

    $filled = fillEnvironment($staging->id);
    fillEnvironment($kept->id);

    // The fixture is only worth something if it reaches nearly every table: the ones that
    // would not take a made-up row are named, so a new one is noticed.
    expect($filled['refused'])->toBe([]);

    // The platform's trail of the environment, written the real way.
    app(EnvironmentContext::class)->runAs($staging, fn () => app(AuditLog::class)->record(
        new AuditEvent(action: 'user.created', actorType: ActorType::System, actorId: 'fixture', targetType: 'user', targetId: 'usr_1'),
    ));

    $before = rowsOf($staging->id);
    $others = rowsOf($kept->id);

    foreach ($filled['filled'] as $table) {
        expect($before[$table])->toBeGreaterThan(0, "{$table} was not filled");
    }

    $removed = app(EnvironmentErasure::class)->erase($staging);

    $after = rowsOf($staging->id);

    foreach (ErasedTables::all() as $table => $entry) {
        if ($entry['decision'] === ErasedTables::ERASE) {
            expect($after[$table])->toBe(0, "{$table} still holds rows of the deleted environment");
        } else {
            expect($after[$table])->toBe($before[$table], "{$table} is the kept trail and lost rows");
        }
    }

    expect(Environment::query()->whereKey($staging->id)->exists())->toBeFalse()
        ->and(Environment::query()->whereKey($kept->id)->exists())->toBeTrue()
        ->and(rowsOf($kept->id))->toBe($others)
        ->and($removed['users'] ?? 0)->toBeGreaterThan(0)
        ->and($after['audit_logs'])->toBeGreaterThan(0);

    // Nothing but the kept trail names the environment anywhere, in any column.
    $schema = new FlipSchema;
    $naming = [];

    foreach ($schema->tables() as $table) {
        if (in_array($table, ErasedTables::kept(), true)) {
            continue;
        }

        $columns = $schema->searchable($table);

        foreach ([...$columns['exact'], ...$columns['inside']] as $column) {
            if (DB::table($table)->whereRaw('cast('.DB::getSchemaBuilder()->getConnection()->getQueryGrammar()->wrap($column).' as text) like ?', ['%'.$staging->id.'%'])->exists()) {
                $naming[] = "{$table}.{$column}";
            }
        }
    }

    expect($naming)->toBe([]);

    // The kept chains still verify.
    $this->artisan('audit-chain:verify')->assertSuccessful();
});

it('removes children that have no environment column of their own', function (): void {
    ['staging' => $staging, 'kept' => $kept] = accountWithStaging();

    $role = fn (Environment $environment): string => (string) DB::table('roles')->where('environment_id', $environment->id)->value('id');
    $permission = fn (Environment $environment): string => (string) DB::table('permissions')->where('environment_id', $environment->id)->value('id');

    fillEnvironment($staging->id);
    fillEnvironment($kept->id);

    DB::table('role_permission')->insert([
        ['role_id' => $role($staging), 'permission_id' => $permission($staging)],
        ['role_id' => $role($kept), 'permission_id' => $permission($kept)],
    ]);

    $ofKept = $role($kept);
    $ofStaging = $role($staging);

    app(EnvironmentErasure::class)->erase($staging);

    expect(DB::table('role_permission')->where('role_id', $ofStaging)->exists())->toBeFalse()
        ->and(DB::table('role_permission')->where('role_id', $ofKept)->exists())->toBeTrue();
});

it('stops answering on the environment\'s address', function (): void {
    ['staging' => $staging] = accountWithStaging();
    $staging->forceFill(['domain' => 'login.staging.example', 'domain_verified_at' => now()])->save();

    expect(app(EnvironmentResolver::class)->resolveForHost('login.staging.example')?->environmentKey())->toBe($staging->id);

    app(EnvironmentErasure::class)->erase($staging);

    expect(app(EnvironmentResolver::class)->resolveForHost('login.staging.example'))->toBeNull();
});

it('never deletes the platform\'s own environment, a reserved address or one the configuration names', function (): void {
    ['staging' => $staging] = accountWithStaging();
    $erasure = app(EnvironmentErasure::class);
    $root = Environment::query()->where('is_default', true)->firstOrFail();

    expect($erasure->protection($root))->toBe('default')
        ->and($erasure->protection($staging))->toBeNull()
        ->and(fn () => $erasure->erase($root))->toThrow(EnvironmentNotErasable::class);

    config(['cbox-id.reserved_environment_slugs_extra' => [$staging->slug]]);
    expect($erasure->protection($staging))->toBe('reserved');
    config(['cbox-id.reserved_environment_slugs_extra' => []]);

    foreach (['cbox-id.cbox_environment' => $staging->slug, 'signals-data.environments' => "other, {$staging->id}", 'service-access.identity_environment' => $staging->id] as $key => $value) {
        $was = config($key);
        config([$key => $value]);

        expect($erasure->protection($staging))->toBe('configured')
            ->and($erasure->configuredAs($staging))->toBe($key)
            ->and(fn () => $erasure->erase($staging))->toThrow(EnvironmentNotErasable::class);

        config([$key => $was]);
    }

    expect(Environment::query()->whereKey($staging->id)->exists())->toBeTrue()
        ->and(Environment::query()->whereKey($root->id)->exists())->toBeTrue();
});

it('deletes nothing while a table of the environment has no decision', function (): void {
    ['staging' => $staging] = accountWithStaging();

    Schema::create('a_feature_added_later', function (Blueprint $table): void {
        $table->id();
        $table->string('environment_id', 26);
    });

    try {
        DB::table('a_feature_added_later')->insert(['environment_id' => $staging->id]);
        $users = DB::table('users')->where('environment_id', $staging->id)->count();

        expect(fn () => app(EnvironmentErasure::class)->erase($staging))
            ->toThrow(EnvironmentNotErasable::class, 'a_feature_added_later');

        expect(Environment::query()->whereKey($staging->id)->exists())->toBeTrue()
            ->and(DB::table('users')->where('environment_id', $staging->id)->count())->toBe($users);
    } finally {
        Schema::dropIfExists('a_feature_added_later');
    }
});

it('deletes an environment from the project page, recorded with what went, and then the empty project', function (): void {
    ['organization' => $organization, 'ownerId' => $ownerId, 'staging' => $staging] = accountWithStaging();
    $isms = app(Projects::class)->createForOrganization($organization->id, 'Cbox ISMS');
    $only = app(TenantProvisioner::class)->addEnvironment($isms, 'ISMS Production');
    $people = DB::table('users')->where('environment_id', $only->id)->count();
    signInAsMember($ownerId);
    app(Sudo::class)->confirm();

    $this->delete(route('orgs.projects.environments.destroy', [$isms->id, $only->id]), ['name' => 'ISMS Production'])
        ->assertRedirect(route('orgs.projects.show', $isms->id))
        ->assertSessionHasNoErrors();

    expect(Environment::query()->whereKey($only->id)->exists())->toBeFalse()
        ->and(Environment::query()->whereKey($staging->id)->exists())->toBeTrue();

    $entries = environmentDeletions($organization->id);

    expect($entries)->toHaveCount(1)
        ->and($entries[0]->actor_id)->toBe($ownerId)
        ->and($entries[0]->target_id)->toBe($only->id)
        ->and($entries[0]->context)->toMatchArray(['name' => 'ISMS Production', 'project_id' => $isms->id, 'people' => $people]);

    // The project is empty now, which is what lets it go.
    signInAsMember($ownerId);
    $this->delete(route('orgs.projects.destroy', $isms->id))->assertRedirect(route('orgs.projects'));

    expect(Project::query()->whereKey($isms->id)->exists())->toBeFalse();
});

it('asks for a fresh sign-in before deleting an environment', function (): void {
    ['ownerId' => $ownerId, 'project' => $project, 'staging' => $staging] = accountWithStaging();
    signInAsMember($ownerId);

    $this->from(route('orgs.projects.show', $project->id))
        ->delete(route('orgs.projects.environments.destroy', [$project->id, $staging->id]), ['name' => 'Staging'])
        ->assertRedirect(route('sudo'));

    expect(Environment::query()->whereKey($staging->id)->exists())->toBeTrue();
});

it('refuses a name that is not the environment\'s', function (string $typed): void {
    ['ownerId' => $ownerId, 'project' => $project, 'staging' => $staging] = accountWithStaging();
    signInAsMember($ownerId);
    app(Sudo::class)->confirm();

    $this->delete(route('orgs.projects.environments.destroy', [$project->id, $staging->id]), ['name' => $typed])
        ->assertSessionHasErrors('environment');

    expect(Environment::query()->whereKey($staging->id)->exists())->toBeTrue();
})->with(['another name' => ['Production'], 'another case' => ['staging'], 'nothing' => ['']]);

it('refuses an environment the configuration names, in the console\'s words', function (): void {
    ['ownerId' => $ownerId, 'project' => $project, 'staging' => $staging] = accountWithStaging();
    config(['service-access.identity_environment' => $staging->id]);
    signInAsMember($ownerId);
    app(Sudo::class)->confirm();

    $this->delete(route('orgs.projects.environments.destroy', [$project->id, $staging->id]), ['name' => 'Staging'])
        ->assertSessionHasErrors(['environment' => __('console.environment_delete_protected_configured', ['key' => 'service-access.identity_environment'])]);

    expect(Environment::query()->whereKey($staging->id)->exists())->toBeTrue();
});

it('refuses a member who may not manage environments, or who reaches only some of them', function (string $role, bool $scoped): void {
    ['organization' => $organization, 'project' => $project, 'kept' => $kept, 'staging' => $staging] = accountWithStaging();
    [$member, $memberId] = memberWithRole($organization->id, MembershipRole::from($role), "{$role}@acme.example");
    grantNativeProductRoles($member, [$role]);

    if ($scoped) {
        app(PlatformRoot::class)->run(fn () => app(Memberships::class)->setEnvironmentAccess($organization->id, $memberId, all: false, environmentIds: [$staging->id]));
    }

    signInAsMember($memberId);
    app(Sudo::class)->confirm();

    $this->delete(route('orgs.projects.environments.destroy', [$project->id, $staging->id]), ['name' => 'Staging'])
        ->assertForbidden();

    expect(Environment::query()->whereKey($staging->id)->exists())->toBeTrue()
        ->and(Environment::query()->whereKey($kept->id)->exists())->toBeTrue();
})->with([
    'a viewer' => ['viewer', false],
    'a developer limited to staging' => ['developer', true],
]);

it('does not find another organization\'s environment, or one of another project', function (): void {
    ['organization' => $organization, 'ownerId' => $ownerId, 'project' => $project, 'staging' => $staging] = accountWithStaging();
    $rival = provisionAccount('owner@rival.example');
    $other = app(Projects::class)->createForOrganization($organization->id, 'Other');
    signInAsMember($ownerId);
    app(Sudo::class)->confirm();

    // Somebody else's environment, under a project of ours.
    $this->delete(route('orgs.projects.environments.destroy', [$project->id, $rival['environment']->id]), ['name' => $rival['environment']->name])
        ->assertNotFound();

    // Somebody else's project.
    signInAsMember($ownerId);
    app(Sudo::class)->confirm();
    $this->delete(route('orgs.projects.environments.destroy', [$rival['project']->id, $rival['environment']->id]), ['name' => $rival['environment']->name])
        ->assertNotFound();

    // Ours, but not in the project the address names.
    signInAsMember($ownerId);
    app(Sudo::class)->confirm();
    $this->delete(route('orgs.projects.environments.destroy', [$other->id, $staging->id]), ['name' => 'Staging'])
        ->assertNotFound();

    expect(Environment::query()->whereKey($rival['environment']->id)->exists())->toBeTrue()
        ->and(Environment::query()->whereKey($staging->id)->exists())->toBeTrue();
})->group('security');

it('tells the project page what deleting each environment removes, only to somebody who may', function (): void {
    ['organization' => $organization, 'ownerId' => $ownerId, 'project' => $project, 'staging' => $staging] = accountWithStaging();
    $people = DB::table('users')->where('environment_id', $staging->id)->count();
    $apps = DB::table('oauth_clients')->where('environment_id', $staging->id)->count();
    signInAsMember($ownerId);

    $this->get(route('orgs.projects.show', $project->id))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('environments.1.name', 'Staging')
            ->where('environments.1.delete.people', $people)
            ->where('environments.1.delete.apps', $apps)
            ->where('project.deletable', false));

    [$viewer, $viewerId] = memberWithRole($organization->id, MembershipRole::Viewer, 'viewer@acme.example');
    grantNativeProductRoles($viewer, ['viewer']);
    signInAsMember($viewerId);

    $this->get(route('orgs.projects.show', $project->id))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('environments.1.delete', null));
});

it('deletes an environment through the organization API only when it is named', function (): void {
    ['organization' => $organization, 'staging' => $staging] = accountWithStaging();
    $key = app(OrganizationApiKeys::class)->issue($organization->id, 'Ops', MembershipRole::Admin, null, ['workspace:read', 'environments:write'])->plaintext;

    $this->withToken($key)->deleteJson("/api/v1/workspace/environments/{$staging->id}")->assertUnprocessable();
    $this->withToken($key)->deleteJson("/api/v1/workspace/environments/{$staging->id}", ['name' => 'Production'])
        ->assertUnprocessable()->assertJsonPath('error', 'name_mismatch');

    expect(Environment::query()->whereKey($staging->id)->exists())->toBeTrue();

    $this->withToken($key)->deleteJson("/api/v1/workspace/environments/{$staging->id}", ['name' => 'Staging'])->assertNoContent();

    expect(Environment::query()->whereKey($staging->id)->exists())->toBeFalse()
        ->and(environmentDeletions($organization->id)[0]->actor_type)->toBe(ActorType::Service);

    $this->withToken($key)->deleteJson("/api/v1/workspace/environments/{$staging->id}", ['name' => 'Staging'])->assertNotFound();
});
