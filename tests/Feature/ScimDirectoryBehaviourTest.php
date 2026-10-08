<?php

declare(strict_types=1);

use Cbox\Id\Directory\Contracts\Directories;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Illuminate\Testing\TestResponse;

/*
|--------------------------------------------------------------------------
| SCIM 2.0 as this deployment serves it, since laravel-id 1.23.
|--------------------------------------------------------------------------
|
| The protocol is the framework's; what is held here is that THIS app serves the parts an
| IT administrator's identity provider now relies on — through the app's own routes,
| middleware and rate limit — and that the IT-admin docs describe them truthfully:
| `409 uniqueness` on a duplicate `externalId` or group name (Entra ID and Okta match the
| user with a filter after it), weak ETags with `If-Match`, `sortBy`, filters that mix
| `and`/`or`, and `/Bulk`.
*/

const SCIM_JSON = 'application/scim+json';

function scimDirectoryToken(): string
{
    $organization = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-scim-'.bin2hex(random_bytes(3))));

    return app(Directories::class)->register($organization->id, 'Okta')->token;
}

/** @param  array<string, mixed>  $body */
function scimCall(string $token, string $method, string $path, array $body = [], array $headers = []): TestResponse
{
    return test()->withToken($token)
        ->withHeaders(['Accept' => SCIM_JSON, ...$headers])
        ->json($method, '/scim/v2'.$path, $body, ['Content-Type' => SCIM_JSON]);
}

/** @return array<string, mixed> */
function scimUser(string $email, string $externalId): array
{
    return [
        'schemas' => ['urn:ietf:params:scim:schemas:core:2.0:User'],
        'userName' => $email,
        'externalId' => $externalId,
        'name' => ['givenName' => 'Ada', 'familyName' => 'Lovelace'],
        'emails' => [['value' => $email, 'type' => 'work', 'primary' => true]],
        'active' => true,
    ];
}

it('advertises bulk, ETags, sorting and filtering in ServiceProviderConfig', function (): void {
    $config = scimCall(scimDirectoryToken(), 'GET', '/ServiceProviderConfig')->assertOk();

    expect($config->json('bulk.supported'))->toBeTrue()
        ->and($config->json('bulk.maxOperations'))->toBe(1000)
        ->and($config->json('etag.supported'))->toBeTrue()
        ->and($config->json('sort.supported'))->toBeTrue()
        ->and($config->json('filter.supported'))->toBeTrue();
});

it('answers a duplicate externalId 409 uniqueness, where it used to upsert', function (): void {
    $token = scimDirectoryToken();

    scimCall($token, 'POST', '/Users', scimUser('ada@acme.test', 'okta-1'))->assertCreated();

    scimCall($token, 'POST', '/Users', scimUser('ada.other@acme.test', 'okta-1'))
        ->assertStatus(409)
        ->assertJsonPath('scimType', 'uniqueness');

    // What Entra ID and Okta do next: find the existing user by a filter.
    scimCall($token, 'GET', '/Users?filter='.rawurlencode('externalId eq "okta-1"'))
        ->assertOk()
        ->assertJsonPath('totalResults', 1)
        ->assertJsonPath('Resources.0.userName', 'ada@acme.test');
});

it('answers a duplicate group name 409 rather than an error', function (): void {
    $token = scimDirectoryToken();
    $group = ['schemas' => ['urn:ietf:params:scim:schemas:core:2.0:Group'], 'displayName' => 'Engineering'];

    scimCall($token, 'POST', '/Groups', $group)->assertCreated();
    scimCall($token, 'POST', '/Groups', $group)->assertStatus(409)->assertJsonPath('scimType', 'uniqueness');
});

it('versions a resource with a weak ETag and refuses a write against a stale one', function (): void {
    $token = scimDirectoryToken();
    $created = scimCall($token, 'POST', '/Users', scimUser('grace@acme.test', 'okta-2'))->assertCreated();
    $id = (string) $created->json('id');
    $etag = (string) $created->headers->get('ETag');

    expect($etag)->toStartWith('W/')
        ->and($created->json('meta.version'))->toBe($etag);

    scimCall($token, 'GET', '/Users/'.$id, headers: ['If-None-Match' => $etag])->assertStatus(304);

    $patch = ['schemas' => ['urn:ietf:params:scim:api:messages:2.0:PatchOp'], 'Operations' => [['op' => 'replace', 'path' => 'active', 'value' => false]]];

    $moved = scimCall($token, 'PATCH', '/Users/'.$id, $patch, ['If-Match' => $etag])->assertOk();

    expect($moved->headers->get('ETag'))->not->toBe($etag);

    // The tag it was read at is gone: the write is refused, not applied over somebody else's.
    scimCall($token, 'PATCH', '/Users/'.$id, $patch, ['If-Match' => $etag])->assertStatus(412);
});

it('sorts and filters with and/or mixed', function (): void {
    $token = scimDirectoryToken();

    foreach (['carol', 'alice', 'bob'] as $index => $name) {
        scimCall($token, 'POST', '/Users', scimUser($name.'@acme.test', 'ext-'.$index))->assertCreated();
    }

    $sorted = scimCall($token, 'GET', '/Users?sortBy=userName&sortOrder=descending')->assertOk();

    expect(array_column((array) $sorted->json('Resources'), 'userName'))->toBe(['carol@acme.test', 'bob@acme.test', 'alice@acme.test']);

    $filter = 'userName sw "a" or userName sw "b" and active eq true';

    expect(scimCall($token, 'GET', '/Users?filter='.rawurlencode($filter))->assertOk()->json('totalResults'))->toBe(2);
});

it('runs a Bulk request, resolving bulkId references', function (): void {
    $token = scimDirectoryToken();

    $bulk = scimCall($token, 'POST', '/Bulk', [
        'schemas' => ['urn:ietf:params:scim:api:messages:2.0:BulkRequest'],
        'Operations' => [
            ['method' => 'POST', 'path' => '/Users', 'bulkId' => 'u1', 'data' => scimUser('lin@acme.test', 'okta-9')],
            ['method' => 'POST', 'path' => '/Groups', 'bulkId' => 'g1', 'data' => [
                'schemas' => ['urn:ietf:params:scim:schemas:core:2.0:Group'],
                'displayName' => 'Support',
                'members' => [['value' => 'bulkId:u1']],
            ]],
        ],
    ])->assertOk();

    expect(array_column((array) $bulk->json('Operations'), 'status'))->toBe(['201', '201']);

    $userId = scimCall($token, 'GET', '/Users?filter='.rawurlencode('userName eq "lin@acme.test"'))->assertOk()->json('Resources.0.id');

    $groupId = scimCall($token, 'GET', '/Groups?filter='.rawurlencode('displayName eq "Support"'))->assertOk()->json('Resources.0.id');

    // The group's member is the user the same request created, by the id it was given.
    expect(array_column((array) scimCall($token, 'GET', '/Groups/'.$groupId)->assertOk()->json('members'), 'value'))->toBe([$userId]);
});
