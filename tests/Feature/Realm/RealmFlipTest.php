<?php

declare(strict_types=1);

use App\Platform\Organizations\OrganizationLinks;
use App\Platform\Realm\FlipOptions;
use App\Platform\Realm\FlipSchema;
use App\Platform\Realm\FlipTables;
use App\Platform\Realm\RealmFlip;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Kernel\Audit\Contracts\AuditLog;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\GenericEnvironment;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\DatabaseEnvironmentResolver;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Cbox\Id\Platform\Contracts\PlatformOperators;
use Cbox\Id\Platform\Contracts\Projects;
use Cbox\Id\Platform\PlatformRoot;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\RealmFlipFixture;

/*
|--------------------------------------------------------------------------
| The realm flip: two realms become one
|--------------------------------------------------------------------------
|
| On a deployment shaped like the hosted service (tests/Support/RealmFlipFixture.php):
| dry run, execute, verify. The flip checks its own post-conditions inside its
| transaction; these tests check from outside that what it promised is what is there.
|
*/

/** Every row of every table, to prove a dry run changed nothing. */
function realmFlipSnapshot(): string
{
    $schema = new FlipSchema;
    $state = [];

    foreach ($schema->tables() as $table) {
        if (in_array($table, ['cache', 'cache_locks'], true)) {
            continue;
        }

        $rows = DB::table($table)->get()->map(static fn (object $row): string => json_encode($row, JSON_THROW_ON_ERROR))->all();
        sort($rows);
        $state[$table] = $rows;
    }

    return hash('sha256', json_encode($state, JSON_THROW_ON_ERROR));
}

function realmFlip(string ...$arguments): array
{
    $options = [];

    foreach ($arguments as $argument) {
        [$name, $value] = array_pad(explode('=', $argument, 2), 2, true);
        $options[$name] = $value;
    }

    $code = Artisan::call('cbox-id:realm:flip', $options);

    return [$code, Artisan::output()];
}

it('has a decision for every table that stores an environment, an organization or a person', function (): void {
    $schema = new FlipSchema;
    $decided = FlipTables::all();
    $undecided = [];

    foreach ($schema->tables() as $table) {
        if ($schema->environmentColumn($table) !== null && ! isset($decided[$table])) {
            $undecided[] = $table;
        }
    }

    expect($undecided)->toBe([]);

    // And nothing in the list names a table or a column that does not exist.
    foreach ($decided as $table => $entry) {
        expect($schema->has($table))->toBeTrue("FlipTables names a missing table: {$table}");

        foreach (['organization', 'user', 'membership', 'inside', 'one'] as $kind) {
            foreach ($entry['refs'][$kind] ?? [] as $column) {
                expect($schema->hasColumn($table, $column))->toBeTrue("FlipTables names a missing column: {$table}.{$column}");
            }
        }
    }
});

it('changes nothing on a dry run, and prints the plan, the inventories and what must follow', function (): void {
    $fixture = RealmFlipFixture::build();
    $before = realmFlipSnapshot();

    [$code, $output] = realmFlip();

    expect($code)->toBe(0, $output)
        ->and(realmFlipSnapshot())->toBe($before)
        ->and($output)->toContain('DRY RUN')
        ->toContain('--execute --confirm='.$fixture['root'])
        ->toContain('Inventory before')
        ->toContain('Inventory after')
        ->toContain('Post-conditions')
        ->toContain($fixture['rootOrganization'])
        ->toContain($fixture['realmOrganization'])
        ->toContain('Billing: the account keyed '.$fixture['rootOrganization'].' must be re-keyed to '.$fixture['realmOrganization'])
        ->toContain('CBOX_WAITLISTS_ID_ENVIRONMENT')
        ->toContain('SIGNALS_DATA_ENVIRONMENTS')
        ->toContain('AI_ASSISTANT_IDENTITY_NAMESPACE');

    expect(Environment::query()->where('is_default', true)->value('id'))->toBe($fixture['root']);
});

it('refuses to execute without the id of the environment being retired', function (): void {
    $fixture = RealmFlipFixture::build();
    $before = realmFlipSnapshot();

    [$code, $output] = realmFlip('--execute');
    expect($code)->toBe(1)->and($output)->toContain('--confirm='.$fixture['root']);

    [$code] = realmFlip('--execute', '--confirm='.$fixture['realm']);
    expect($code)->toBe(1)->and(realmFlipSnapshot())->toBe($before);
});

it('flips: dry run, execute, verify', function (): void {
    $fixture = RealmFlipFixture::build();

    [$code, $output] = realmFlip();
    expect($code)->toBe(0, $output);

    [$code, $output] = realmFlip('--execute', '--confirm='.$fixture['root']);
    expect($code)->toBe(0, $output)->and($output)->toContain('EXECUTED');

    [$code, $output] = realmFlip('--verify');
    expect($code)->toBe(0, $output)->and($output)->toContain('VERIFIED');

    // One default environment: the realm, in no project, on the host the root had.
    $root = Environment::query()->where('is_default', true)->get();
    expect($root)->toHaveCount(1)
        ->and($root[0]->id)->toBe($fixture['realm'])
        ->and($root[0]->project_id)->toBeNull()
        ->and($root[0]->domain)->toBe(RealmFlipFixture::CONSOLE_HOST)
        ->and(Environment::query()->whereKey($fixture['root'])->exists())->toBeFalse()
        ->and((new PlatformRoot)->model()?->id)->toBe($fixture['realm']);

    // Settings: the realm's own value wins, a root-only key is copied, sign-in-through is retired.
    expect($root[0]->settings)->toMatchArray(['default_locale' => 'da', 'appearance' => ['preset' => 'fixture']])
        ->and($root[0]->settings)->not->toHaveKey('sign_in_through');

    // The organization that survives is the realm's, and it owns what the root one owned.
    expect(DB::table('organizations')->where('id', $fixture['rootOrganization'])->exists())->toBeFalse()
        ->and(DB::table('projects')->pluck('organization_id')->unique()->all())->toBe([$fixture['realmOrganization']])
        ->and(DB::table('projects')->count())->toBe(2)
        ->and(DB::table('organization_api_keys')->value('organization_id'))->toBe($fixture['realmOrganization'])
        ->and(DB::table('organization_api_keys')->value('created_by_id'))->toBe($fixture['realmOwner'])
        ->and(DB::table('organization_links')->count())->toBe(0);

    // The sandbox is still a tenant environment, and it is still served.
    $sandbox = Environment::query()->findOrFail($fixture['sandbox']);
    expect($sandbox->project_id)->toBe($fixture['project'])
        ->and(app(DatabaseEnvironmentResolver::class)->forKey($fixture['sandbox']))->not->toBeNull()
        ->and(DB::table('users')->where('environment_id', $fixture['sandbox'])->count())->toBe(1);

    // The person: one account, the realm's, with the realm's passkeys. The root said owner
    // and the realm said member; they keep the higher, and the colleague is untouched.
    expect(DB::table('users')->where('id', $fixture['rootOwner'])->exists())->toBeFalse()
        ->and(DB::table('users')->where('environment_id', $fixture['realm'])->count())->toBe(3)
        ->and(DB::table('webauthn_credentials')->pluck('name')->sort()->values()->all())->toBe(['realm-1', 'realm-2'])
        ->and(DB::table('memberships')->where('id', $fixture['realmMembership'])->value('role'))->toBe('owner')
        ->and(DB::table('memberships')->where('id', $fixture['rootMembership'])->exists())->toBeFalse()
        ->and(DB::table('memberships')->where('user_id', $fixture['colleague'])->value('role'))->toBe('admin')
        ->and((bool) DB::table('memberships')->where('id', $fixture['realmMembership'])->value('all_environments'))->toBeTrue();

    // The operator is the same record, now the realm account.
    $operator = (new PlatformRoot)->run(fn () => app(PlatformOperators::class)->findBySubject($fixture['realmOwner']));
    expect($operator?->id)->toBe($fixture['operator']);

    // The pending invitation still leads into the organization.
    expect(DB::table('invitations')->where('environment_id', $fixture['realm'])->where('organization_id', $fixture['realmOrganization'])->where('invited_by', $fixture['realmOwner'])->count())->toBe(1);

    // Clients: the platform client keeps its id; the Authenticator twin is the realm's;
    // the native product registration and its roles are in the realm.
    expect(DB::table('oauth_clients')->where('client_id', $fixture['movedClient'])->value('environment_id'))->toBe($fixture['realm'])
        ->and(DB::table('oauth_clients')->where('client_id', $fixture['twinClient'])->exists())->toBeFalse()
        ->and(DB::table('oauth_clients')->where('client_id', $fixture['realmTwinClient'])->exists())->toBeTrue()
        ->and(DB::table('native_product_clients')->value('environment_id'))->toBe($fixture['realm'])
        ->and(DB::table('oauth_clients')->where('client_id', $fixture['nativeClient'])->value('environment_id'))->toBe($fixture['realm'])
        ->and(DB::table('roles')->where('client_id', $fixture['nativeClient'])->where('environment_id', $fixture['realm'])->count())->toBeGreaterThan(0)
        ->and(DB::table('role_permission')->whereNotIn('role_id', DB::table('roles')->select('id'))->count())->toBe(0);

    // Every session and token is revoked, the sandbox's included.
    expect(DB::table('auth_sessions')->whereNull('revoked_at')->count())->toBe(0)
        ->and(DB::table('auth_sessions')->where('environment_id', $fixture['sandbox'])->count())->toBe(1)
        ->and(DB::table('oauth_refresh_tokens')->whereNull('revoked_at')->count())->toBe(0);

    // Audit: the old root's trail is archived verbatim, the realm's chains still verify,
    // and the flip is the next entry of the realm's system chain.
    expect(DB::table('audit_logs')->where('environment_id', $fixture['root'])->count())->toBe(0)
        ->and(DB::table('realm_flip_archive')->where('source_table', 'audit_logs')->count())->toBeGreaterThan(0);

    $archived = json_decode((string) DB::table('realm_flip_archive')->where('source_table', 'audit_logs')->orderBy('id')->value('payload'), true);
    expect($archived)->toHaveKeys(['hash', 'prev_hash', 'sequence', 'environment_id'])
        ->and($archived['environment_id'])->toBe($fixture['root']);

    app(EnvironmentContext::class)->runAs(GenericEnvironment::of($fixture['realm']), function () use ($fixture): void {
        expect(app(AuditLog::class)->verifyChain()->valid)->toBeTrue()
            ->and(app(AuditLog::class)->verifyChain($fixture['realmOrganization'])->valid)->toBeTrue();
    });
    expect(DB::table('audit_logs')->where('environment_id', $fixture['realm'])->where('action', 'realm.flipped')->count())->toBe(1);

    // The map Billing follows.
    expect(DB::table('realm_flip_ids')->where('kind', 'organization')->where('old_id', $fixture['rootOrganization'])->value('new_id'))->toBe($fixture['realmOrganization']);
});

it('is safe to run again: a flipped deployment changes nothing', function (): void {
    $fixture = RealmFlipFixture::build();

    expect(realmFlip('--execute', '--confirm='.$fixture['root'])[0])->toBe(0);

    $after = realmFlipSnapshot();

    [$code, $output] = realmFlip('--execute', '--confirm='.$fixture['root']);
    expect($code)->toBe(0)->and($output)->toContain('Nothing to do')->and(realmFlipSnapshot())->toBe($after);

    [$code, $output] = realmFlip();
    expect($code)->toBe(0)->and($output)->toContain('Nothing to do')->and(realmFlipSnapshot())->toBe($after);

    // Life goes on: somebody signing up later does not make the flip look wrong.
    $this->travel(5)->minutes();
    app(EnvironmentContext::class)->runAs(GenericEnvironment::of($fixture['realm']), fn () => app(Subjects::class)->create('later@example.test', 'Later', 'a-ninth-strong-unbreached-passphrase'));

    expect(realmFlip('--verify')[0])->toBe(0);
});

it('blocks on an unlinked organization that owns a project, and on a member with no realm account', function (): void {
    $fixture = RealmFlipFixture::build();
    $context = app(EnvironmentContext::class);

    [$acme, $stranger] = $context->runAs(Environment::query()->findOrFail($fixture['root']), function () use ($fixture): array {
        $acme = app(Organizations::class)->create(new NewOrganization(name: 'Acme', slug: 'acme'));
        $stranger = app(Subjects::class)->create('stranger@acme.test', 'Stranger', 'a-sixth-strong-unbreached-passphrase');
        app(Memberships::class)->add($acme->id, $stranger->id, MembershipRole::Owner);
        // And a colleague of the linked organization the realm has never seen.
        $newcomer = app(Subjects::class)->create('root-only@cbox.test', 'Root only', 'a-seventh-strong-unbreached-passphrase');
        app(Memberships::class)->add($fixture['rootOrganization'], $newcomer->id, MembershipRole::Viewer);

        return [$acme->id, $stranger->id];
    });
    $project = app(Projects::class)->createForOrganization($acme, 'Acme');
    // A new root organization is paired with a new realm one as it is created. This one
    // is from before that: no link, and its slug is taken in the realm.
    app(OrganizationLinks::class)->unlink($acme);
    $before = realmFlipSnapshot();

    [$code, $output] = realmFlip();

    expect($code)->toBe(1)
        ->and($output)->toContain('BLOCKED')
        ->toContain("Acme (acme, {$acme}) owns 1 projects and has no link")
        ->toContain('root-only@cbox.test')
        ->and(realmFlip('--execute', '--confirm='.$fixture['root'])[0])->toBe(1)
        ->and(realmFlipSnapshot())->toBe($before);

    // --create-missing moves both into the realm with the ids they have.
    [$code, $output] = realmFlip('--execute', '--confirm='.$fixture['root'], '--create-missing');
    expect($code)->toBe(0, $output);
    expect(realmFlip('--verify')[0])->toBe(0);

    expect(DB::table('organizations')->where('id', $acme)->value('environment_id'))->toBe($fixture['realm'])
        ->and(DB::table('organizations')->where('id', $acme)->value('slug'))->toBe('acme-'.substr($acme, -6))
        ->and(DB::table('projects')->where('id', $project->id)->value('organization_id'))->toBe($acme)
        ->and(DB::table('users')->where('id', $stranger)->value('environment_id'))->toBe($fixture['realm'])
        ->and(DB::table('memberships')->where('organization_id', $acme)->where('user_id', $stranger)->value('environment_id'))->toBe($fixture['realm'])
        ->and(DB::table('password_ages')->where('user_id', $stranger)->value('environment_id'))->toBe($fixture['realm'])
        ->and(DB::table('memberships')->where('organization_id', $fixture['realmOrganization'])->where('role', 'viewer')->count())->toBe(1);

    // The moved person still signs in with the password they had.
    app(EnvironmentContext::class)->runAs(GenericEnvironment::of($fixture['realm']), function () use ($stranger): void {
        expect(app(Subjects::class)->verifyPassword($stranger, 'a-sixth-strong-unbreached-passphrase'))->toBeTrue();
    });
});

it('drops an unlinked organization that owns nothing, and says so first', function (): void {
    $fixture = RealmFlipFixture::build();

    $empty = app(EnvironmentContext::class)->runAs(Environment::query()->findOrFail($fixture['root']), function (): string {
        $organization = app(Organizations::class)->create(new NewOrganization(name: 'Nobody', slug: 'nobody'));
        $person = app(Subjects::class)->create('nobody@example.test', 'Nobody', 'an-eighth-strong-unbreached-passphrase');
        app(Memberships::class)->add($organization->id, $person->id, MembershipRole::Owner);

        return $organization->id;
    });
    app(OrganizationLinks::class)->unlink($empty);

    [$code, $output] = realmFlip();
    expect($code)->toBe(0, $output)->and($output)->toContain('owns nothing and is DROPPED');

    expect(realmFlip('--execute', '--confirm='.$fixture['root'])[0])->toBe(0)
        ->and(realmFlip('--verify')[0])->toBe(0)
        ->and(DB::table('organizations')->where('id', $empty)->exists())->toBeFalse()
        ->and(DB::table('users')->where('email', 'nobody@example.test')->exists())->toBeFalse();
});

it('keeps a role by the rule it is told', function (string $rule, string $expected): void {
    $fixture = RealmFlipFixture::build();

    expect(realmFlip('--execute', '--confirm='.$fixture['root'], '--role-conflict='.$rule)[0])->toBe(0)
        ->and(DB::table('memberships')->where('id', $fixture['realmMembership'])->value('role'))->toBe($expected);
})->with([['highest', 'owner'], ['root', 'owner'], ['realm', 'member']]);

it('fails verification when a row still names a retired id', function (): void {
    $fixture = RealmFlipFixture::build();

    expect(realmFlip('--execute', '--confirm='.$fixture['root'])[0])->toBe(0);

    DB::table('organization_api_keys')->update(['created_by_id' => $fixture['rootOwner']]);

    [$code, $output] = realmFlip('--verify');
    expect($code)->toBe(1)->and($output)->toContain('organization_api_keys.created_by_id');
});

it('reports before a verify that no flip is on record', function (): void {
    RealmFlipFixture::build();

    [$code, $output] = realmFlip('--verify');
    expect($code)->toBe(1)->and($output)->toContain('run --execute first');
});

it('answers for a moved flip through the engine as well as the command', function (): void {
    $fixture = RealmFlipFixture::build();

    $report = app(RealmFlip::class)->run(new FlipOptions, commit: false);

    expect($report->blocked())->toBeFalse()
        ->and($report->committed)->toBeFalse()
        ->and($report->passed())->toBeTrue()
        ->and($report->ids['organization'])->toBe([$fixture['rootOrganization'] => $fixture['realmOrganization']])
        ->and($report->ids['user'])->toBe([$fixture['rootOwner'] => $fixture['realmOwner']])
        ->and($report->before['deployment']['default environments'])->toBe(1)
        ->and($report->after['root']['users'])->toBe(0)
        ->and($report->after['deployment']['projects owned by a realm organization'])->toBe(2);
});

it('carries the console\'s configuration to the surviving organization and person', function (): void {
    $fixture = RealmFlipFixture::build();
    $id = static fn (): string => Str::lower((string) Str::ulid());
    $root = ['environment_id' => $fixture['root']];
    $nativeRole = DB::table('roles')->where('client_id', $fixture['nativeClient'])->value('id');

    // A second root member, known to the realm by address only, restricted to the sandbox.
    $rootColleague = $id();
    $rootColleagueMembership = $id();
    RealmFlipFixture::insert('users', [...$root, 'id' => $rootColleague, 'email' => 'Colleague@cbox.test', 'status' => 'active']);
    RealmFlipFixture::insert('memberships', [...$root, 'id' => $rootColleagueMembership, 'organization_id' => $fixture['rootOrganization'], 'user_id' => $rootColleague, 'role' => 'developer', 'status' => 'active', 'all_environments' => false, 'invited_by' => $fixture['rootOwner']]);
    DB::table('membership_environments')->insert([
        ['membership_id' => $rootColleagueMembership, 'environment_id' => $fixture['sandbox']],
        ['membership_id' => $rootColleagueMembership, 'environment_id' => $fixture['realm']],
    ]);

    RealmFlipFixture::insert('role_assignments', [...$root, 'id' => $id(), 'organization_id' => $fixture['rootOrganization'], 'user_id' => $fixture['rootOwner'], 'role_id' => $nativeRole, 'source' => 'manual']);
    RealmFlipFixture::insert('environment_api_keys', [...$root, 'id' => $rootKey = $id(), 'token_hash' => 'root-key', 'prefix' => 'cbid_env_a', 'created_by_type' => 'organization_member', 'created_by_id' => $fixture['rootOwner']]);
    RealmFlipFixture::insert('environment_api_keys', ['environment_id' => $fixture['sandbox'], 'id' => $sandboxKey = $id(), 'token_hash' => 'sandbox-key', 'prefix' => 'cbid_env_b', 'created_by_type' => 'organization_member', 'created_by_id' => $fixture['rootOwner']]);

    // Sign-in policy: the realm has its own baseline; the organization's mandate is the root's.
    RealmFlipFixture::insert('auth_policies', ['environment_id' => $fixture['realm'], 'id' => $realmBaseline = $id(), 'organization_id' => null]);
    RealmFlipFixture::insert('auth_policies', [...$root, 'id' => $rootBaseline = $id(), 'organization_id' => null]);
    RealmFlipFixture::insert('auth_policies', [...$root, 'id' => $mandate = $id(), 'organization_id' => $fixture['rootOrganization']]);

    RealmFlipFixture::insert('connections', [...$root, 'id' => $sso = $id(), 'organization_id' => $fixture['rootOrganization'], 'type' => 'saml', 'name' => 'Team SSO', 'status' => 'active']);
    RealmFlipFixture::insert('connections', [...$root, 'id' => $rootGoogle = $id(), 'organization_id' => null, 'type' => 'oidc', 'provider' => 'google', 'name' => 'Google', 'status' => 'active']);
    RealmFlipFixture::insert('connections', ['environment_id' => $fixture['realm'], 'id' => $id(), 'organization_id' => null, 'type' => 'oidc', 'provider' => 'google', 'name' => 'Google', 'status' => 'active']);
    RealmFlipFixture::insert('connections', [...$root, 'id' => $rootGithub = $id(), 'organization_id' => null, 'type' => 'oauth2', 'provider' => 'github', 'name' => 'GitHub', 'status' => 'active']);

    RealmFlipFixture::insert('verified_domains', [...$root, 'id' => $domain = $id(), 'organization_id' => $fixture['rootOrganization'], 'domain' => 'cbox.test']);
    RealmFlipFixture::insert('webhook_endpoints', [...$root, 'id' => $webhook = $id(), 'organization_id' => $fixture['rootOrganization'], 'url' => 'https://hooks.cbox.test/id']);
    RealmFlipFixture::insert('vault_secrets', [...$root, 'id' => $secret = $id(), 'name' => 'org', 'owner_type' => 'organization', 'owner_id' => $fixture['rootOrganization']]);
    RealmFlipFixture::insert('vault_secrets', [...$root, 'id' => $ownSecret = $id(), 'name' => 'person', 'owner_type' => 'user', 'owner_id' => $fixture['rootOwner']]);

    RealmFlipFixture::insert('feature_flags', [...$root, 'id' => $flag = $id(), 'key' => 'beta']);
    RealmFlipFixture::insert('feature_flag_targets', [...$root, 'id' => $target = $id(), 'feature_flag_id' => $flag, 'target_type' => 'organization', 'target_id' => $fixture['rootOrganization']]);

    // Rows of other environments that name the console's people and organizations.
    RealmFlipFixture::insert('app_catalog_entries', ['environment_id' => $fixture['realm'], 'id' => $tile = $id(), 'key' => 'work', 'organization_ids' => json_encode([$fixture['rootOrganization'], $fixture['emptyOrganization']]), 'user_ids' => json_encode([$fixture['rootOwner']])]);
    RealmFlipFixture::insert('support_sessions', ['environment_id' => $fixture['sandbox'], 'id' => $support = $id(), 'actor_id' => $fixture['rootOwner'], 'actor_kind' => 'environment_admin', 'target_user_id' => 'someone', 'organization_id' => 'theirs', 'expires_at' => now()->addHour()]);
    RealmFlipFixture::insert('product_billing_usage_outbox', ['event_id' => $usage = $id(), 'workspace_id' => $fixture['rootOrganization'], 'meter' => 'mau', 'occurred_at' => now(), 'value' => 1]);

    [$code, $output] = realmFlip('--execute', '--confirm='.$fixture['root']);
    expect($code)->toBe(0, $output);
    expect(realmFlip('--verify')[0])->toBe(0);

    $row = static fn (string $table, string $key, string $column = 'id'): ?object => DB::table($table)->where($column, $key)->first();
    $realmColleagueMembership = DB::table('memberships')->where('organization_id', $fixture['realmOrganization'])->where('user_id', $fixture['colleague'])->first();

    // The colleague: matched by address whatever its case, keeps the higher role, and
    // takes the root's environment grants, minus the realm itself.
    expect($row('users', $rootColleague))->toBeNull()
        ->and($realmColleagueMembership->role)->toBe('admin')
        ->and((bool) $realmColleagueMembership->all_environments)->toBeFalse()
        ->and(DB::table('membership_environments')->where('membership_id', $realmColleagueMembership->id)->pluck('environment_id')->all())->toBe([$fixture['sandbox']])
        ->and(DB::table('membership_environments')->where('membership_id', $rootColleagueMembership)->count())->toBe(0);

    expect(DB::table('role_assignments')->where('role_id', $nativeRole)->first())
        ->environment_id->toBe($fixture['realm'])
        ->organization_id->toBe($fixture['realmOrganization'])
        ->user_id->toBe($fixture['realmOwner']);

    expect($row('environment_api_keys', $rootKey))->environment_id->toBe($fixture['realm'])->created_by_id->toBe($fixture['realmOwner'])
        ->and($row('environment_api_keys', $sandboxKey))->environment_id->toBe($fixture['sandbox'])->created_by_id->toBe($fixture['realmOwner']);

    expect($row('auth_policies', $realmBaseline))->not->toBeNull()
        ->and($row('auth_policies', $rootBaseline))->toBeNull()
        ->and($row('auth_policies', $mandate))->environment_id->toBe($fixture['realm'])->organization_id->toBe($fixture['realmOrganization']);

    expect($row('connections', $sso))->environment_id->toBe($fixture['realm'])->organization_id->toBe($fixture['realmOrganization'])
        ->and($row('connections', $rootGoogle))->toBeNull()
        ->and($row('connections', $rootGithub))->environment_id->toBe($fixture['realm'])
        ->and(DB::table('connections')->where('environment_id', $fixture['realm'])->where('provider', 'google')->count())->toBe(1);

    expect($row('verified_domains', $domain))->organization_id->toBe($fixture['realmOrganization'])
        ->and($row('webhook_endpoints', $webhook))->environment_id->toBe($fixture['realm'])->organization_id->toBe($fixture['realmOrganization'])
        ->and($row('vault_secrets', $secret))->owner_id->toBe($fixture['realmOrganization'])
        ->and($row('vault_secrets', $ownSecret))->owner_id->toBe($fixture['realmOwner'])
        ->and($row('feature_flags', $flag))->environment_id->toBe($fixture['realm'])
        ->and($row('feature_flag_targets', $target))->environment_id->toBe($fixture['realm'])->target_id->toBe($fixture['realmOrganization']);

    expect(json_decode($row('app_catalog_entries', $tile)->organization_ids, true))->toBe([$fixture['realmOrganization'], $fixture['emptyOrganization']])
        ->and(json_decode($row('app_catalog_entries', $tile)->user_ids, true))->toBe([$fixture['realmOwner']])
        ->and($row('support_sessions', $support))->actor_id->toBe($fixture['realmOwner'])->ended_at->not->toBeNull()
        ->and($row('product_billing_usage_outbox', $usage, 'event_id'))->workspace_id->toBe($fixture['realmOrganization']);
});
