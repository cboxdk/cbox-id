<?php

declare(strict_types=1);

use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Kernel\Audit\Contracts\AuditLog;
use Cbox\Id\Kernel\Audit\ValueObjects\AuditEvent;
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

/*
|--------------------------------------------------------------------------
| ⌘K: find anything in THIS console, and nothing outside it.
|--------------------------------------------------------------------------
|
| The search is the one console endpoint whose whole job is reading across kinds of record,
| so its scope is the thing to prove: the environment console sees its own environment and
| never another's, the organization console sees its own organization and never a
| neighbour's — and a pasted id or key opens the one record it names.
*/

/** Search as the console's palette does. @return array<string, mixed> */
function paletteSearch(string $route, string $term): array
{
    return test()->getJson(route($route, ['q' => $term]))->assertOk()->json();
}

/** @param  array<string, mixed>  $answer  @return list<string> */
function paletteTitles(array $answer, string $group): array
{
    foreach ($answer['groups'] as $candidate) {
        if ($candidate['key'] === $group) {
            return array_column($candidate['items'], 'title');
        }
    }

    return [];
}

/** A user, an organization, an app and a key named $name, in the current environment. */
function seedSearchable(string $name, string $environmentId): array
{
    $user = app(Subjects::class)->create(strtolower($name).'@search.test', $name.' Person', 'supersecret123');
    $organization = app(Organizations::class)->create(new NewOrganization($name.' Org', strtolower($name).'-org'));
    $app = app(ClientRegistry::class)->register(new NewClient($name.' App', redirectUris: ['https://'.strtolower($name).'.test/cb']))->client;
    $key = app(EnvironmentApiKeys::class)->issue($environmentId, $name.' Key', ['users:read']);

    return ['user' => $user, 'organization' => $organization, 'app' => $app, 'key' => $key];
}

it('finds users, organizations, apps and keys in this environment — and never another\'s', function (): void {
    multiTenantDeployment();
    $tenant = actAsEnvironmentAdminOfATenant();

    $mine = seedSearchable('Zephyr', $tenant);

    // The same names in ANOTHER environment: the default one every test also has.
    app(EnvironmentContext::class)->runAs(GenericEnvironment::of('env_test'), fn () => seedSearchable('Zephyrine', 'env_test'));

    $answer = paletteSearch('environment.search', 'zephyr');

    expect(paletteTitles($answer, 'users'))->toBe(['Zephyr Person'])
        ->and(paletteTitles($answer, 'organizations'))->toBe(['Zephyr Org'])
        ->and(paletteTitles($answer, 'apps'))->toBe(['Zephyr App'])
        ->and(paletteTitles($answer, 'keys'))->toBe(['Zephyr Key'])
        ->and(json_encode($answer))->not->toContain('Zephyrine');

    // Another environment's record by its exact id is as absent as by its name.
    $foreign = app(EnvironmentContext::class)->runAs(GenericEnvironment::of('env_test'), fn () => Client::query()->where('name', 'Zephyrine App')->sole());

    expect(paletteSearch('environment.search', $foreign->client_id)['groups'])->toBe([])
        ->and(paletteSearch('environment.search', $mine['app']->client_id)['jump']['href'] ?? null)
        ->toBe(route('environment.clients.show', $mine['app']->id));
})->group('security');

it('jumps straight to the record a pasted id names', function (): void {
    multiTenantDeployment();
    $tenant = actAsEnvironmentAdminOfATenant();
    $mine = seedSearchable('Quill', $tenant);

    $entry = app(AuditLog::class)->record(new AuditEvent(action: 'search.probe'));

    expect(paletteSearch('environment.search', $mine['user']->id)['jump']['href'] ?? null)->toBe(route('environment.users.show', $mine['user']->id))
        ->and(paletteSearch('environment.search', $mine['organization']->id)['jump']['href'] ?? null)->toBe(route('environment.organizations.show', $mine['organization']->id))
        ->and(paletteSearch('environment.search', $mine['app']->id)['jump']['kind'] ?? null)->toBe('app')
        ->and(paletteSearch('environment.search', $entry->id)['jump']['href'] ?? null)->toBe(route('environment.audit', ['entry' => $entry->id]));

    // A WHOLE key pasted by mistake is cut to its prefix and finds itself — the rest of it
    // is never used for anything.
    $jump = paletteSearch('environment.search', $mine['key']->plaintext)['jump'] ?? null;

    expect($jump['kind'] ?? null)->toBe('key')
        ->and($jump['subtitle'] ?? null)->toBe($mine['key']->key->prefix)
        ->and(paletteSearch('environment.search', $mine['key']->plaintext)['query'])->toBe($mine['key']->key->prefix);
})->group('security');

it('confines the organization console to its own organization', function (): void {
    [, $org] = actingAsRole(MembershipRole::Owner);

    // A member of this organization, and somebody who is not.
    $colleague = app(Subjects::class)->create('marlow@acme.test', 'Marlow Colleague', 'supersecret123');
    app(Memberships::class)->add($org->id, $colleague->id, MembershipRole::Member);
    app(Subjects::class)->create('marlowe@elsewhere.test', 'Marlowe Stranger', 'supersecret123');

    // An app of this organization's, and one of a neighbour's.
    $neighbour = app(Organizations::class)->create(new NewOrganization('Neighbour', 'neighbour'));
    app(ClientRegistry::class)->register(new NewClient('Marlow Tasks', redirectUris: ['https://tasks.test/cb'], organizationId: $org->id));
    app(ClientRegistry::class)->register(new NewClient('Marlow Ledger', redirectUris: ['https://ledger.test/cb'], organizationId: $neighbour->id));

    $answer = paletteSearch('search', 'marlow');

    expect(paletteTitles($answer, 'users'))->toBe(['Marlow Colleague'])
        ->and(paletteTitles($answer, 'apps'))->toBe(['Marlow Tasks'])
        // The organization console has no organizations list and no management keys.
        ->and(paletteTitles($answer, 'organizations'))->toBe([])
        ->and(paletteTitles($answer, 'keys'))->toBe([])
        ->and(json_encode($answer))->not->toContain('Stranger')->not->toContain('Ledger');
})->group('security');

it('offers the palette its actions from the registry, as deep links', function (): void {
    multiTenantDeployment();
    actAsEnvironmentAdminOfATenant();

    $actions = collect(paletteSearch('environment.search', '')['actions'])->keyBy('name');

    expect($actions->get('apps.create')['label'] ?? null)->toBe('Create app')
        ->and($actions->get('apps.create')['href'] ?? null)->toBe(route('environment.clients.create'))
        ->and($actions->get('apps.secrets.rotate')['label'] ?? null)->toBe('Rotate app secret…')
        ->and($actions->get('apps.secrets.rotate')['href'] ?? null)->toBe(route('environment.clients'));
});

it('answers a member who may not administer with a refusal, not results', function (): void {
    actingAsRole(MembershipRole::Member);

    $this->getJson(route('search', ['q' => 'anything']))->assertForbidden();
})->group('security');

it('asks nothing of a one-letter query', function (): void {
    multiTenantDeployment();
    actAsEnvironmentAdminOfATenant();

    expect(paletteSearch('environment.search', 'z')['groups'])->toBe([]);
});
