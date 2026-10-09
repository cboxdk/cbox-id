<?php

declare(strict_types=1);

use App\Http\Controllers\Console\FgaController;
use App\Platform\Actions\ActionRegistry;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Principal\DelegatedTokenPrincipal;
use App\Platform\EnvironmentSudo;
use App\Platform\Fga\FgaTrail;
use App\Platform\OAuth\ValueObjects\OrganizationChoice;
use Cbox\Id\Kernel\Audit\Enums\ActorType;
use Cbox\Id\Kernel\Audit\Models\AuditEntry;
use Cbox\Id\Kernel\Authorization\Contracts\FineGrainedAuthorization;
use Cbox\Id\Kernel\Authorization\Models\FgaTuple;
use Cbox\Id\Kernel\Authorization\ValueObjects\ConsistencyToken;
use Cbox\Id\Kernel\Authorization\ValueObjects\Tuple;
use Cbox\Id\Kernel\Authorization\ValueObjects\TupleFilter;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\GenericEnvironment;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Cbox\Id\Platform\Contracts\EnvironmentApiKeys;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;

/*
|--------------------------------------------------------------------------
| Fine-grained authorization — an environment's own relationship model, from every door.
|--------------------------------------------------------------------------
|
| The schema (validated, Critical to change, refused while tuples would be stranded), tuple
| batches, checks with inheritance and consistency tokens, list queries — over REST with a
| key, over MCP, and from the console with its check playground; the trail naming the key
| over REST and the person in the console; and never another environment's model, nor the
| reach of a token confined to one organization.
*/

const FGA_ALL = ['fga:read', 'fga:write', 'fga:schema'];

const FGA_DOCS_SCHEMA = <<<'SCHEMA'
    type user

    type group
      relation member: [user, group#member]

    type folder
      relation parent: [folder]
      relation owner: [user]
      relation editor: [user, group#member] or owner or editor from parent
      relation viewer: [user, group#member] or editor or viewer from parent

    type document
      relation parent: [folder]
      relation owner: [user]
      relation editor: [user, group#member] or owner or editor from parent
      relation viewer: [user, group#member] or editor or viewer from parent
    SCHEMA;

/** @param  list<string>  $scopes */
function fgaKey(array $scopes = FGA_ALL): string
{
    return app(EnvironmentApiKeys::class)->issue('env_test', 'Authorization backend', $scopes)->plaintext;
}

/**
 * @return array<string, mixed>
 */
function fgaTupleBody(string $tuple): array
{
    return Tuple::parse($tuple)->toArray();
}

function fgaWriteOverRest(string $key, string ...$tuples): TestResponse
{
    return test()->withToken($key)->postJson('/api/v1/fga/tuples', [
        'tuples' => array_map(fgaTupleBody(...), $tuples),
    ]);
}

function fgaCheckOverRest(string $key, string $tuple, ?string $token = null): TestResponse
{
    $parsed = Tuple::parse($tuple);

    return test()->withToken($key)->getJson('/api/v1/fga/check?'.http_build_query(array_filter([
        'resource_type' => $parsed->resource->type,
        'resource_id' => $parsed->resource->id,
        'relation' => $parsed->relation,
        'subject_type' => $parsed->subject->type,
        'subject_id' => $parsed->subject->id,
        'subject_relation' => $parsed->subject->relation,
        'consistency_token' => $token,
    ], static fn (?string $value): bool => $value !== null)));
}

function fgaModelOverRest(string $key): void
{
    test()->withToken($key)->putJson('/api/v1/fga/schema', ['schema' => FGA_DOCS_SCHEMA])->assertOk();

    fgaWriteOverRest($key,
        'folder:handbook#owner@user:olivia',
        'folder:policies#parent@folder:handbook',
        'document:leave#parent@folder:policies',
        'group:eng#member@user:alice',
        'folder:policies#viewer@group:eng#member',
        'document:leave#editor@user:bob',
    )->assertOk()->assertJsonPath('data.written', 6);
}

it('defines a schema, writes tuples and answers checks through inheritance over REST', function (): void {
    $key = fgaKey();

    $this->withToken($key)->getJson('/api/v1/fga/schema')
        ->assertOk()
        ->assertJsonPath('data.defined', false)
        ->assertJsonPath('data.types', []);

    fgaModelOverRest($key);

    $this->withToken($key)->getJson('/api/v1/fga/schema')
        ->assertOk()
        ->assertJsonPath('data.defined', true)
        ->assertJsonPath('data.version', 1)
        ->assertJsonPath('data.schema', FGA_DOCS_SCHEMA)
        ->assertJsonPath('data.types.3.name', 'document');

    fgaCheckOverRest($key, 'document:leave#viewer@user:olivia')->assertOk()->assertJsonPath('data.allowed', true);
    fgaCheckOverRest($key, 'document:leave#viewer@user:alice')->assertOk()->assertJsonPath('data.allowed', true);
    fgaCheckOverRest($key, 'document:leave#editor@user:alice')->assertOk()->assertJsonPath('data.allowed', false);
    fgaCheckOverRest($key, 'document:leave#viewer@group:eng#member')->assertOk()->assertJsonPath('data.allowed', true);

    $this->withToken($key)->getJson('/api/v1/fga/check/batch?'.http_build_query(['checks' => [
        'document:leave#editor@user:bob',
        'folder:handbook#viewer@user:bob',
    ]]))->assertOk()
        ->assertJsonPath('data.results.0.allowed', true)
        ->assertJsonPath('data.results.0.subject.id', 'bob')
        ->assertJsonPath('data.results.1.allowed', false);

    $this->withToken($key)->getJson('/api/v1/fga/check/batch?'.http_build_query(['checks' => ['document:leave#editor@user:bob', 'not a check']]))
        ->assertUnprocessable()
        ->assertJsonPath('error', 'invalid_tuple')
        ->assertJsonPath('message', 'Tuple 1: `not a check` is not a tuple: write `type:id#relation@type:id` or `…@type:id#relation`.');

    $this->withToken($key)->getJson('/api/v1/fga/resources?'.http_build_query([
        'resource_type' => 'document', 'relation' => 'viewer', 'subject_type' => 'user', 'subject_id' => 'alice',
    ]))->assertOk()->assertJsonPath('data', [['type' => 'document', 'id' => 'leave']])->assertJsonPath('meta.has_more', false);

    $this->withToken($key)->getJson('/api/v1/fga/subjects?'.http_build_query([
        'resource_type' => 'document', 'resource_id' => 'leave', 'relation' => 'viewer', 'subject_type' => 'user', 'limit' => 2,
    ]))->assertOk()
        ->assertJsonPath('data', [['type' => 'user', 'id' => 'alice'], ['type' => 'user', 'id' => 'bob']])
        ->assertJsonPath('meta.has_more', true)
        ->assertJsonPath('meta.next_cursor', 'bob');

    $this->withToken($key)->getJson('/api/v1/fga/tuples?resource_type=document')
        ->assertOk()
        ->assertJsonPath('data.0.tuple', 'document:leave#parent@folder:policies')
        ->assertJsonCount(2, 'data');
});

it('deletes tuples and revokes what was inherited through them, at once', function (): void {
    $key = fgaKey();
    fgaModelOverRest($key);

    $deleted = $this->withToken($key)->postJson('/api/v1/fga/tuples/delete', [
        'tuples' => [fgaTupleBody('document:leave#parent@folder:policies'), fgaTupleBody('document:never#viewer@user:x')],
    ])->assertOk()->assertJsonPath('data.deleted', 1);

    fgaCheckOverRest($key, 'document:leave#viewer@user:alice', $deleted->json('data.consistency_token'))
        ->assertOk()
        ->assertJsonPath('data.allowed', false);
});

it('honours a consistency token, and refuses one from the future or another environment', function (): void {
    $key = fgaKey();
    fgaModelOverRest($key);

    $written = fgaWriteOverRest($key, 'document:leave#viewer@user:dana')->assertOk();
    $token = $written->json('data.consistency_token');

    fgaCheckOverRest($key, 'document:leave#viewer@user:dana', $token)
        ->assertOk()
        ->assertJsonPath('data.allowed', true)
        ->assertJsonPath('data.consistency_token', $token);

    fgaCheckOverRest($key, 'document:leave#viewer@user:dana', (string) ConsistencyToken::for('env_test', 10_000))
        ->assertUnprocessable()
        ->assertJsonPath('error', 'invalid_consistency_token');

    fgaCheckOverRest($key, 'document:leave#viewer@user:dana', (string) ConsistencyToken::for('env_elsewhere', 1))
        ->assertUnprocessable()
        ->assertJsonPath('error', 'invalid_consistency_token');
});

it('validates a schema without saving it, and refuses to save an invalid one or one that strands tuples', function (): void {
    $key = fgaKey();

    $this->withToken($key)->getJson('/api/v1/fga/schema/validate?'.http_build_query(['schema' => "type user\ntype doc\n  relation viewer: [usr]"]))
        ->assertOk()
        ->assertJsonPath('data.valid', false)
        ->assertJsonPath('data.errors.0.line', 3)
        ->assertJsonPath('data.errors.0.message', '`viewer` on `doc` names the type `usr`, which is not defined.');

    $this->withToken($key)->getJson('/api/v1/fga/schema/validate?'.http_build_query(['schema' => FGA_DOCS_SCHEMA]))
        ->assertOk()
        ->assertJsonPath('data.valid', true)
        ->assertJsonPath('data.errors', []);

    $this->withToken($key)->putJson('/api/v1/fga/schema', ['schema' => "type user\ntype doc\n  relation viewer: [usr]"])
        ->assertUnprocessable()
        ->assertJsonPath('error', 'invalid_schema');

    expect(app(FineGrainedAuthorization::class)->schema()->defined())->toBeFalse();

    fgaModelOverRest($key);

    $this->withToken($key)->putJson('/api/v1/fga/schema', ['schema' => "type user\ntype folder\n  relation viewer: [user]"])
        ->assertStatus(409)
        ->assertJsonPath('error', 'schema_conflict');
});

it('refuses tuples and checks the schema does not define, and a write before any schema', function (): void {
    $key = fgaKey();

    fgaWriteOverRest($key, 'document:a#viewer@user:x')->assertUnprocessable()->assertJsonPath('error', 'schema_not_defined');

    fgaModelOverRest($key);

    fgaWriteOverRest($key, 'document:a#viewer@user:x', 'document:a#viewer@team:x')
        ->assertUnprocessable()
        ->assertJsonPath('error', 'invalid_tuple')
        ->assertJsonPath('message', 'Tuple 1: `viewer` on `document` does not take `team` — it takes [user, group#member].');

    fgaCheckOverRest($key, 'document:leave#reader@user:alice')
        ->assertUnprocessable()
        ->assertJsonPath('error', 'unknown_relation');

    expect(FgaTuple::query()->where('resource_id', 'a')->exists())->toBeFalse();
});

it('takes the scope each action declares', function (): void {
    $owner = fgaKey();
    fgaModelOverRest($owner);

    $reader = fgaKey(['fga:read']);
    $writer = fgaKey(['fga:read', 'fga:write']);

    fgaCheckOverRest($reader, 'document:leave#viewer@user:alice')->assertOk();
    fgaWriteOverRest($reader, 'document:leave#viewer@user:x')->assertForbidden();
    fgaWriteOverRest($writer, 'document:leave#viewer@user:x')->assertOk();
    $this->withToken($writer)->putJson('/api/v1/fga/schema', ['schema' => FGA_DOCS_SCHEMA])->assertForbidden();

    $registry = app(ActionRegistry::class);

    expect($registry->named('fga.schema.update')->danger)->toBe(Danger::Critical)
        ->and($registry->named('fga.schema.update')->scope)->toBe('fga:schema')
        ->and($registry->named('fga.tuples.write')->danger)->toBe(Danger::Write)
        ->and($registry->named('fga.tuples.delete')->danger)->toBe(Danger::Destructive)
        ->and($registry->named('fga.check')->danger)->toBe(Danger::Read)
        ->and($registry->named('fga.resources.list')->scope)->toBe('fga:read');
});

it('records the key on the trail over REST, with the tuples by name and the revision', function (): void {
    $key = fgaKey();
    fgaModelOverRest($key);

    $schema = AuditEntry::query()->where('action', FgaTrail::SCHEMA_UPDATED)->sole();
    $written = AuditEntry::query()->where('action', FgaTrail::TUPLES_WRITTEN)->sole();

    expect($schema->actor_type)->toBe(ActorType::Service)
        ->and($schema->context['version'])->toBe(1)
        ->and($written->actor_type)->toBe(ActorType::Service)
        ->and($written->context['written'])->toBe(6)
        ->and($written->context['tuples'])->toContain('group:eng#member@user:alice')
        ->and($written->context['revision'])->toBe(2);

    // Re-writing what is there changes nothing, and says nothing.
    fgaWriteOverRest($key, 'group:eng#member@user:alice')->assertOk()->assertJsonPath('data.written', 0);

    expect(AuditEntry::query()->where('action', FgaTrail::TUPLES_WRITTEN)->count())->toBe(1);
});

it('never shows one environment the model of another', function (): void {
    // Another environment's model, written in its own context: same type names, same ids.
    $context = app(EnvironmentContext::class);
    $context->runAs(GenericEnvironment::of('env_fga_foreign'), function (): void {
        $fga = app(FineGrainedAuthorization::class);
        $fga->updateSchema(FGA_DOCS_SCHEMA);
        $fga->writeTuples([Tuple::parse('document:secret#viewer@user:alice'), Tuple::parse('document:leave#owner@user:mallory')]);
    });

    $key = fgaKey();

    $this->withToken($key)->getJson('/api/v1/fga/schema')->assertOk()->assertJsonPath('data.defined', false);
    fgaCheckOverRest($key, 'document:secret#viewer@user:alice')->assertUnprocessable()->assertJsonPath('error', 'schema_not_defined');

    fgaModelOverRest($key);

    fgaCheckOverRest($key, 'document:secret#viewer@user:alice')->assertOk()->assertJsonPath('data.allowed', false);
    fgaCheckOverRest($key, 'document:leave#owner@user:mallory')->assertOk()->assertJsonPath('data.allowed', false);

    $this->withToken($key)->getJson('/api/v1/fga/tuples?subject_id=mallory')->assertOk()->assertJsonPath('data', []);
    $this->withToken($key)->getJson('/api/v1/fga/resources?'.http_build_query([
        'resource_type' => 'document', 'relation' => 'viewer', 'subject_type' => 'user', 'subject_id' => 'alice',
    ]))->assertOk()->assertJsonPath('data', [['type' => 'document', 'id' => 'leave']]);

    // And this environment's writes never reach the other one.
    $context->runAs(GenericEnvironment::of('env_fga_foreign'), function (): void {
        expect(app(FineGrainedAuthorization::class)->tuples(new TupleFilter)->tuples)->toHaveCount(2);
    });
})->group('security');

it('keeps the model out of reach of a token confined to one organization', function (): void {
    $organization = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-fga'));

    $principal = new DelegatedTokenPrincipal(
        subjectId: 'usr_owner',
        personName: 'Owner',
        clientId: 'cli',
        clientName: 'CLI',
        environmentId: 'env_test',
        scopes: FGA_ALL,
        organization: new OrganizationChoice($organization->id, $organization->name, MembershipRole::Owner),
        customerConsole: false,
    );

    foreach (['fga.schema.get', 'fga.schema.update', 'fga.tuples.write', 'fga.check'] as $name) {
        expect(fn () => $principal->authorize(app(ActionRegistry::class)->named($name)))
            ->toThrow(AuthorizationException::class, 'administered from the environment console');
    }
})->group('security');

it('offers every action as an MCP tool, and answers a check over MCP', function (): void {
    $key = fgaKey();
    fgaModelOverRest($key);

    expect(mcpTools($key))->toHaveKeys([
        'fga_schema_get', 'fga_schema_update', 'fga_schema_validate',
        'fga_tuples_write', 'fga_tuples_delete', 'fga_tuples_list',
        'fga_check', 'fga_check_batch', 'fga_resources_list', 'fga_subjects_list',
    ]);

    $result = mcpCall($key, 'fga_check', [
        'resource_type' => 'document',
        'resource_id' => 'leave',
        'relation' => 'viewer',
        'subject_type' => 'user',
        'subject_id' => 'olivia',
    ]);

    expect($result['isError'] ?? false)->toBeFalse()
        ->and($result['structuredContent']['data']['allowed'] ?? null)->toBeTrue();
});

it('edits the schema behind the step-up, writes tuples and answers the playground in the console', function (): void {
    craftedEnvAdmin();

    $this->get(route('environment.fga'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('console/fga/index')
            ->where('schema.defined', false));

    // The editor decides every check: behind the step-up, like every page that changes how
    // the environment decides access.
    app(EnvironmentSudo::class)->forget();
    $this->get(route('environment.fga.schema'))->assertRedirect(route('environment.sudo'));
    $this->put(route('environment.fga.schema.update'), ['schema' => FGA_DOCS_SCHEMA])->assertRedirect(route('environment.sudo'));

    app(EnvironmentSudo::class)->confirm();

    $this->get(route('environment.fga.schema'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('console/fga/schema')
            ->where('source', FgaController::STARTER_SCHEMA));

    $this->get(route('environment.fga.schema', ['draft' => "type user\ntype doc\n  relation viewer: [usr]"]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('validation.valid', false)
            ->where('validation.errors.0.line', 3));

    $this->from(route('environment.fga.schema'))
        ->put(route('environment.fga.schema.update'), ['schema' => "type doc\n  relation viewer: [usr]"])
        ->assertSessionHasErrors('schema');

    $this->from(route('environment.fga.schema'))
        ->put(route('environment.fga.schema.update'), ['schema' => FGA_DOCS_SCHEMA])
        ->assertRedirect(route('environment.fga'));

    $this->from(route('environment.fga'))->post(route('environment.fga.tuples.store'), ['tuple' => 'folder:handbook#viewer@user:alice'])->assertSessionHasNoErrors();
    $this->from(route('environment.fga'))->post(route('environment.fga.tuples.store'), ['tuple' => 'document:leave#parent@folder:handbook'])->assertSessionHasNoErrors();
    $this->from(route('environment.fga'))->post(route('environment.fga.tuples.store'), ['tuple' => 'not a tuple'])->assertSessionHasErrors('tuple');
    $this->from(route('environment.fga'))->post(route('environment.fga.tuples.store'), ['tuple' => 'document:x#viewer@team:a'])->assertSessionHasErrors('tuple');

    $this->get(route('environment.fga', [
        'check_resource' => 'document:leave',
        'check_relation' => 'viewer',
        'check_subject' => 'user:alice',
    ]))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('schema.defined', true)
        ->where('check.allowed', true)
        ->where('check.error', null)
        ->where('tuples', ['folder:handbook#viewer@user:alice', 'document:leave#parent@folder:handbook'])
        ->where('schema.types.3.relations.3.definition', '[user, group#member] or editor or viewer from parent'));

    $this->get(route('environment.fga', ['check_resource' => 'document:leave', 'check_relation' => 'reader', 'check_subject' => 'user:alice']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('check.error', '`document` has no relation `reader` in the schema.'));

    $this->from(route('environment.fga'))->delete(route('environment.fga.tuples.destroy'), ['tuple' => 'document:leave#parent@folder:handbook'])->assertSessionHasNoErrors();

    $this->get(route('environment.fga', ['check_resource' => 'document:leave', 'check_relation' => 'viewer', 'check_subject' => 'user:alice']))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('check.allowed', false));

    // The person, not a key, on the trail.
    expect(AuditEntry::query()->where('action', FgaTrail::SCHEMA_UPDATED)->sole()->actor_type)->not->toBe(ActorType::Service)
        ->and(AuditEntry::query()->where('action', FgaTrail::TUPLES_DELETED)->sole()->context['tuples'])->toBe(['document:leave#parent@folder:handbook']);
});
