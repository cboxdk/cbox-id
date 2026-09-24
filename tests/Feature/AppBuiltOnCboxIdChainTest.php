<?php

declare(strict_types=1);

use App\Mail\InvitationMail;
use App\Models\InvitationRoleGrant;
use App\Platform\CurrentUser;
use Cbox\Id\Identity\Contracts\BreachedPasswordCheck;
use Cbox\Id\Identity\Models\User as Subject;
use Cbox\Id\Identity\NeverBreachedCheck;
use Cbox\Id\Kernel\Crypto\Contracts\TokenSigner;
use Cbox\Id\Kernel\Crypto\Enums\SigningAlg;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\Contracts\IssuerResolver;
use Cbox\Id\Kernel\Tenancy\Contracts\TenantContext;
use Cbox\Id\Kernel\Tenancy\GenericEnvironment;
use Cbox\Id\Organization\Contracts\CustomerApiKeys;
use Cbox\Id\Organization\Enums\InvitationStatus;
use Cbox\Id\Organization\Models\Invitation;
use Cbox\Id\Organization\Models\Membership;
use Cbox\Id\Organization\Models\Organization;
use Cbox\Id\Platform\Contracts\EnvironmentApiKeys;
use Cbox\Id\Platform\Enums\EnvironmentApiScope;
use Cbox\Id\Platform\TenantProvisioner;
use Cbox\Id\Platform\ValueObjects\TenantBlueprint;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;

/*
|--------------------------------------------------------------------------
| An app built on Cbox ID, end to end
|--------------------------------------------------------------------------
| Every piece below has its own suite. This one proves they hold as a CHAIN: the org a
| sign-up founds is the org the owner invites into, the role the invitation parks is the
| role the next token carries, the key a member mints is capped by the role an owner can
| take away, and the staff grant the vendor makes reaches one app and not its neighbour.
|
| One multi-tenant deployment, one vendor environment ("cboxtax") served on this suite's
| host, and every step through the door a real caller uses: the browser's pages, the
| vendor backend's management API with its `cbid_env_` key, and the app's own OAuth
| endpoints with its own credentials. Three people, three browsers.
*/

const CHAIN_APP_ORIGIN = 'https://app.cboxtax.test';
const CHAIN_REDIRECT_URI = 'https://app.cboxtax.test/auth/callback';
const CHAIN_API = 'https://api.cboxtax.test';
const CHAIN_VERIFIER = 'the-cboxtax-chain-verifier-of-sufficient-length-0123456789';

beforeEach(function (): void {
    app()->instance(BreachedPasswordCheck::class, new NeverBreachedCheck);
    Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 200)]);
    Mail::fake();
});

/**
 * One browser per person. The suite's HTTP client has ONE session store, so a second
 * person signing in would join the first person's browser as a held account — which is a
 * real feature, and not this scenario. Switching swaps the whole session, and ends the
 * request so nothing memoised for the last person answers for the next.
 */
function chainBrowser(string $person, ?string $environmentId = null): void
{
    /** @var array{jars: array<string, array<string, mixed>>, current: string|null, environment: string|null} $state */
    static $state = ['jars' => [], 'current' => null, 'environment' => null];

    if ($person === '__reset__') {
        $state = ['jars' => [], 'current' => null, 'environment' => $environmentId];

        return;
    }

    if ($state['current'] !== null) {
        $state['jars'][$state['current']] = session()->all();
    }

    session()->flush();
    session()->put($state['jars'][$person] ?? []);
    $state['current'] = $person;

    nextRequest();
    app()->forgetInstance(CurrentUser::class);

    // …and stand back in the vendor's environment, so a read this test makes between
    // requests asks the same environment the next request will resolve.
    if ($state['environment'] !== null) {
        app(EnvironmentContext::class)->set(GenericEnvironment::of($state['environment']));
    }
}

/** @return array<string, string> the query the browser was sent back to the app with */
function chainCallback(TestResponse $response): array
{
    $location = (string) leftFor($response);

    expect($location)->toStartWith(CHAIN_REDIRECT_URI.'?');

    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    /** @var array<string, string> $query */
    return $query;
}

/**
 * The app's sign-in, as its SDK sends it.
 *
 * @param  array<string, string>  $extra
 */
function chainAuthorize(string $clientId, array $extra = []): TestResponse
{
    return test()->get(route('oauth.authorize', [
        'response_type' => 'code',
        'client_id' => $clientId,
        'redirect_uri' => CHAIN_REDIRECT_URI,
        'scope' => 'openid profile email',
        'state' => 'st',
        'code_challenge' => pkcePair(CHAIN_VERIFIER)['challenge'],
        'code_challenge_method' => 'S256',
        ...$extra,
    ]));
}

/**
 * The app's backend redeeming a code with its own credentials; the verified claims of
 * both tokens and the raw response.
 *
 * @return array{access: array<string, mixed>, id: array<string, mixed>, body: array<string, mixed>}
 */
function chainRedeem(string $clientId, string $secret, string $code): array
{
    $body = test()->withBasicAuth($clientId, $secret)->postJson('/oauth/token', [
        'grant_type' => 'authorization_code',
        'code' => $code,
        'redirect_uri' => CHAIN_REDIRECT_URI,
        'code_verifier' => CHAIN_VERIFIER,
    ])->assertOk()->json();

    $signer = app(TokenSigner::class);

    return [
        'access' => $signer->verify((string) $body['access_token'], [SigningAlg::RS256])->all(),
        'id' => $signer->verify((string) $body['id_token'], [SigningAlg::RS256])->all(),
        'body' => $body,
    ];
}

it('runs the whole app-on-Cbox-ID scenario as one chain', function (): void {
    // ── The deployment and the vendor ────────────────────────────────────────────────
    multiTenantDeployment();
    platformRootEnvironment();

    $vendor = app(TenantProvisioner::class)->provision(new TenantBlueprint(
        organizationName: 'Cboxtax',
        ownerEmail: 'owner@vendor.test',
        ownerName: 'Vendor Owner',
        ownerPassword: 'a-strong-unbreached-passphrase',
    ));
    $environment = serveOnTestHost($vendor->environment);
    $environment->forceFill(['name' => 'cboxtax'])->save();

    expect($environment->domain)->not->toBeNull('the vendor environment could not take the suite host');

    chainBrowser('__reset__', $environment->id);
    chainBrowser('vendor');

    // The vendor switches self-service sign-up on, in its environment console.
    actAsEnvironmentAdmin($vendor->owner->id, $environment->id);
    test()->from(route('environment.auth-policy'))
        ->put(route('environment.auth-policy.self-service-signup'), ['enabled' => true])
        ->assertRedirect(route('environment.auth-policy'));

    // …and holds a management key for its backend.
    $envKey = app(EnvironmentApiKeys::class)->issue($environment->id, 'cboxtax backend', array_map(
        fn (EnvironmentApiScope $scope): string => $scope->value,
        EnvironmentApiScope::offerable(),
    ))->plaintext;

    expect($envKey)->toStartWith('cbid_env_');

    $backend = fn () => test()->withToken($envKey);

    // The API first, so the app is registered against scopes the environment already owns.
    $apiId = $backend()->postJson('/api/v1/apis', [
        'identifier' => CHAIN_API,
        'name' => 'cboxtax API',
        'scopes' => [
            ['key' => 'tax.quote', 'description' => 'Get a quote'],
            ['key' => 'tax.assess', 'description' => 'Run an assessment'],
        ],
    ])->assertCreated()->json('data.id');

    // The app, as a blueprint: the short form has no key prefix.
    $app = $backend()->postJson('/api/v1/apps', ['blueprint' => [
        'kind' => 'cbox-id.client-blueprint',
        'version' => 1,
        'name' => 'cboxtax',
        'client_type' => 'confidential',
        'grant_types' => ['authorization_code', 'refresh_token', 'client_credentials'],
        'redirect_uris' => [CHAIN_REDIRECT_URI],
        'scopes' => ['openid', 'profile', 'email', 'offline_access', 'apps.manifest', 'tax.quote', 'tax.assess'],
        'first_party' => true,
        'api_key_prefix' => 'ctx_test',
    ]])->assertCreated()->json('data');

    ['client_id' => $clientId, 'client_secret' => $secret] = $app;

    expect($app['organization_id'])->toBeNull()
        ->and($app['first_party'])->toBeTrue();

    // The API enforces the app's roles and permissions.
    $backend()->patchJson("/api/v1/apis/{$apiId}", ['client_id' => $clientId])
        ->assertOk()
        ->assertJsonPath('data.client_id', $clientId);

    // The app publishes its own manifest with its own credentials.
    $manifestToken = test()->withBasicAuth($clientId, $secret)->postJson('/oauth/token', [
        'grant_type' => 'client_credentials',
        'scope' => 'apps.manifest',
    ])->assertOk()->json('access_token');

    test()->withToken((string) $manifestToken)->postJson('/api/v1/apps/manifest', [
        'version' => '1',
        'permissions' => [
            ['key' => 'tax:quote', 'description' => 'Get a quote', 'tenant_assignable' => true],
            ['key' => 'tax:assess', 'description' => 'Run an assessment', 'tenant_assignable' => true],
            ['key' => 'support:impersonate', 'description' => 'Sign in as a customer', 'tenant_assignable' => false],
        ],
        'roles' => [
            ['key' => 'editor', 'name' => 'Editor', 'permissions' => ['tax:quote', 'tax:assess']],
            ['key' => 'viewer', 'name' => 'Viewer', 'permissions' => ['tax:quote']],
            ['key' => 'support', 'name' => 'Support', 'permissions' => ['support:impersonate'], 'tenant_assignable' => false],
        ],
    ])->assertOk()->assertJson(['roles_declared' => 3, 'permissions_declared' => 3]);

    // ── 1. Anna signs up from the app, and founds her team ───────────────────────────
    chainBrowser('anna');

    chainAuthorize($clientId, ['prompt' => 'create'])->assertRedirect(route('signup'));

    // The page says who she is signing up for.
    test()->get(route('signup'))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('forApp', 'cboxtax')->where('createsIdp', false));

    $resume = (string) test()->from(route('signup'))->post(route('signup.register'), [
        'organization' => 'Hansen Revision',
        'name' => 'Anna Hansen',
        'email' => 'anna@hansen-revision.test',
        'password' => 'a-strong-unbreached-passphrase',
        'website' => '',
        'renderedAt' => now()->getTimestamp() - 30,
    ])->assertRedirect()->headers->get('Location');

    expect(parse_url($resume, PHP_URL_PATH))->toBe('/oauth/authorize');

    $tokens = chainRedeem($clientId, $secret, chainCallback(test()->get($resume))['code']);

    $anna = Subject::query()->where('email', 'anna@hansen-revision.test')->sole();
    $hansen = Organization::query()->where('name', 'Hansen Revision')->sole();

    expect($hansen->environment_id)->toBe($environment->id)
        ->and($tokens['access']['sub'])->toBe($anna->id)
        ->and($tokens['access']['org'])->toBe($hansen->id)
        ->and($tokens['access']['org_role'])->toBe('owner')
        ->and($tokens['id']['org'])->toBe($hansen->id)
        ->and($tokens['id']['org_name'])->toBe('Hansen Revision')
        ->and($tokens['id']['org_role'])->toBe('owner');

    // prompt=create already asked for her organization (create + create_organization is
    // refused as contradictory), so the hosted create step is a SECOND team: it must bind
    // the grant to the new one and leave her session where it was.
    $create = (string) chainAuthorize($clientId, ['prompt' => 'create_organization'])->assertRedirect()->headers->get('Location');
    $store = test()->get($create)->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('oauth/create-organization'))
        ->inertiaProps('storeHref');

    $holding = chainRedeem($clientId, $secret, chainCallback(
        inertiaRequest(fn (): TestResponse => test()->from($create)->post((string) $store, ['name' => 'Hansen Holding'])),
    )['code']);

    $holdingOrg = Organization::query()->where('name', 'Hansen Holding')->sole();

    expect($holding['access']['org'])->toBe($holdingOrg->id)
        ->and($holding['access']['org_role'])->toBe('owner')
        ->and($holding['access']['sub'])->toBe($anna->id);

    // ── 2. Anna invites Bo as an Editor of the app, from her People page ─────────────
    $roles = collect($backend()->getJson('/api/v1/roles')->assertOk()->json('data'))
        ->where('client_id', $clientId)
        ->keyBy('key');

    expect($roles->keys()->sort()->values()->all())->toBe(['editor', 'support', 'viewer']);

    $people = test()->get(route('directory.members'))->assertOk()->inertiaProps();
    $offered = collect($people['accessRoles'])->pluck('id')->all();

    // Her session is still in the team she founded: the create step bound one grant to
    // Hansen Holding and remembered nothing.
    expect(data_get($people, 'auth.organization.id'))->toBe($hansen->id)
        ->and($offered)->toContain($roles['editor']['id'], $roles['viewer']['id'])
        ->and($offered)->not->toContain($roles['support']['id']);

    // Posted anyway, by id: the staff role is not parked on the invitation.
    test()->from(route('directory.members'))->post(route('directory.members.invite'), [
        'email' => 'bo@nordic-survey.test',
        'role' => 'member',
        'accessRoles' => [$roles['editor']['id'], $roles['support']['id']],
        'client_id' => $clientId,
        'return_to' => CHAIN_APP_ORIGIN.'/welcome?team=hansen',
    ])->assertSessionHasNoErrors()->assertSessionHas('status', 'Invitation sent to bo@nordic-survey.test.');

    $invitation = Invitation::query()->where('email', 'bo@nordic-survey.test')->sole();

    expect($invitation->organization_id)->toBe($hansen->id)
        ->and(InvitationRoleGrant::query()->where('invitation_id', $invitation->id)->pluck('role_id')->all())
        ->toBe([$roles['editor']['id']]);

    preg_match('#/invitations/([^/?]+)/accept#', (string) Mail::sent(InvitationMail::class)->last()?->url, $link);
    $inviteToken = $link[1] ?? '';

    // ── 3. Bo opens the link, accepts, lands in the app, and signs in to it ─────────
    chainBrowser('bo');

    test()->get(route('invitation.accept', $inviteToken))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('auth/join-organization')
            ->where('confirmation.heading', 'Join Hansen Revision?')
            ->where('confirmation.facts.4', ['label' => 'App', 'value' => 'cboxtax']));

    // The GET spent nothing.
    expect($invitation->refresh()->status)->toBe(InvitationStatus::Pending)
        ->and(Subject::query()->where('email', 'bo@nordic-survey.test')->exists())->toBeFalse();

    test()->post(route('invitation.accept.store', $inviteToken))
        ->assertRedirect(CHAIN_APP_ORIGIN.'/welcome?team=hansen');

    $bo = Subject::query()->where('email', 'bo@nordic-survey.test')->sole();

    $boTokens = chainRedeem($clientId, $secret, chainCallback(
        chainAuthorize($clientId, ['scope' => 'openid profile tax.quote tax.assess']),
    )['code']);

    expect($boTokens['access']['sub'])->toBe($bo->id)
        ->and($boTokens['access']['org'])->toBe($hansen->id)
        ->and($boTokens['access']['org_role'])->toBe('member')
        ->and($boTokens['access']['roles'])->toBe(['editor'])
        // A set, not a list: the framework reads it with an unordered DISTINCT, so
        // PostgreSQL and SQLite answer the same two permissions in different orders.
        ->and($boTokens['access']['permissions'])->toEqualCanonicalizing(['tax:quote', 'tax:assess'])
        ->and($boTokens['access']['scope'])->toBe('openid profile tax.quote tax.assess')
        // The API's identifier, and the issuer beside it because OIDC scopes rode along.
        ->and($boTokens['access']['aud'])->toBe([CHAIN_API, app(IssuerResolver::class)->issuer()])
        ->and($invitation->refresh()->status)->toBe(InvitationStatus::Accepted);

    // Asked for the API alone, the token is for the API alone.
    $apiOnly = test()->withBasicAuth($clientId, $secret)->postJson('/oauth/token', [
        'grant_type' => 'authorization_code',
        'code' => chainCallback(chainAuthorize($clientId, ['scope' => 'tax.quote']))['code'],
        'redirect_uri' => CHAIN_REDIRECT_URI,
        'code_verifier' => CHAIN_VERIFIER,
    ])->assertOk()->json('access_token');

    $apiOnly = app(TokenSigner::class)->verify((string) $apiOnly, [SigningAlg::RS256])->all();

    expect($apiOnly['aud'])->toBe(CHAIN_API)
        ->and($apiOnly['scope'])->toBe('tax.quote')
        ->and($apiOnly['permissions'])->toEqualCanonicalizing(['tax:quote', 'tax:assess']);

    // ── 4. The vendor backend founds a second team with Bo as its owner ─────────────
    $nordic = $backend()->postJson('/api/v1/organizations', [
        'name' => 'Nordic Survey',
        'owner_user_id' => $bo->id,
    ])->assertCreated()
        ->assertJsonPath('data.name', 'Nordic Survey')
        ->assertJsonPath('data.slug', 'nordic-survey')
        ->json('data.id');

    $inNordic = chainRedeem($clientId, $secret, chainCallback(chainAuthorize($clientId, ['organization' => $nordic]))['code']);

    expect($inNordic['access']['org'])->toBe($nordic)
        ->and($inNordic['access']['org_role'])->toBe('owner')
        ->and($inNordic['id']['org_name'])->toBe('Nordic Survey');

    // Anna's second team is not his.
    $refused = chainCallback(chainAuthorize($clientId, ['organization' => $holdingOrg->id]));

    expect($refused)->toBe([
        'error' => 'access_denied',
        'error_description' => 'The user is not an active member of the requested organization.',
        'state' => 'st',
        'iss' => app(IssuerResolver::class)->issuer(),
    ]);

    // ── 5. Staff: the vendor makes Sylvester support, environment-wide ─────────────
    $sylvester = $backend()->postJson('/api/v1/users', [
        'email' => 'sylvester@vendor.test',
        'name' => 'Sylvester',
        'password' => 'a-strong-unbreached-passphrase',
    ])->assertCreated()->json('data.id');

    $backend()->putJson("/api/v1/users/{$sylvester}/environment-roles/{$roles['support']['id']}")
        ->assertOk()
        ->assertExactJson(['data' => [
            'role_id' => $roles['support']['id'],
            'key' => 'support',
            'name' => 'Support',
            'client_id' => $clientId,
            'tenant_assignable' => false,
            'user_id' => $sylvester,
            'organization_id' => null,
            'source' => 'manual',
        ]]);

    // A second app in the same environment, which declares nothing.
    $ledger = $backend()->postJson('/api/v1/apps', [
        'name' => 'Ledger',
        'type' => 'web',
        'redirect_uris' => [CHAIN_REDIRECT_URI],
        'first_party' => true,
    ])->assertCreated()->json('data');

    chainBrowser('sylvester');
    attemptLogin(['email' => 'sylvester@vendor.test', 'password' => 'a-strong-unbreached-passphrase'])
        ->assertSessionHasNoErrors();

    $staff = chainRedeem($clientId, $secret, chainCallback(chainAuthorize($clientId))['code']);
    $neighbour = chainRedeem($ledger['client_id'], $ledger['client_secret'], chainCallback(chainAuthorize($ledger['client_id']))['code']);

    expect($staff['access']['sub'])->toBe($sylvester)
        ->and($staff['access']['org'])->toBeNull()
        ->and($staff['access']['roles'])->toBe(['support'])
        ->and($staff['access']['permissions'])->toBe(['support:impersonate'])
        // The leak check: the same person, the same environment, the neighbouring app.
        ->and($neighbour['access']['sub'])->toBe($sylvester)
        ->and($neighbour['access'])->not->toHaveKey('roles')
        ->and($neighbour['access'])->not->toHaveKey('permissions');

    // ── 6. Sylvester opens a support session as Anna, in Hansen Revision ──────────
    $support = $backend()->postJson('/api/v1/support-sessions', [
        'user_id' => $anna->id,
        'organization_id' => $hansen->id,
        'client_id' => $clientId,
        'actor_user_id' => $sylvester,
        'reason' => 'Ticket 7: the assessment total looks wrong',
        'ttl_minutes' => 30,
        'redirect_uri' => CHAIN_REDIRECT_URI,
        'code_challenge' => pkcePair(CHAIN_VERIFIER)['challenge'],
    ])->assertCreated()->json('data');

    expect($support['user_id'])->toBe($anna->id)
        ->and($support['organization_id'])->toBe($hansen->id)
        ->and($support['act'])->toBe(['sub' => $sylvester]);

    $acted = chainRedeem($clientId, $secret, (string) $support['code']);

    expect($acted['access']['sub'])->toBe($anna->id)
        ->and((array) $acted['access']['act'])->toBe(['sub' => $sylvester])
        ->and((array) $acted['id']['act'])->toBe(['sub' => $sylvester])
        ->and($acted['access']['org'])->toBe($hansen->id)
        ->and($acted['access']['org_role'])->toBe('owner')
        ->and($acted['body'])->not->toHaveKey('refresh_token')
        ->and($acted['body']['expires_in'])->toBeLessThanOrEqual(3600);

    // ── 7. Bo mints a key for the app's API, capped by what he holds ─────────────
    chainBrowser('bo');

    $keys = test()->get(route('account.api-keys'))->assertOk()->inertiaProps();

    expect($keys['organizationId'])->toBe($hansen->id)
        ->and($keys['apps'])->toHaveCount(1)
        ->and($keys['apps'][0]['clientId'])->toBe($clientId)
        ->and($keys['apps'][0]['prefix'])->toBe('ctx_test')
        ->and($keys['apps'][0]['permissions'])->toBe([
            ['name' => 'tax:assess', 'description' => 'Run an assessment'],
            ['name' => 'tax:quote', 'description' => 'Get a quote'],
        ]);

    test()->from(route('account.api-keys'))->post(route('account.api-keys.store'), [
        'client_id' => $clientId,
        'organization_id' => $hansen->id,
        'name' => 'Quote robot',
        'permissions' => ['tax:quote'],
        'expires' => 'never',
    ])->assertRedirect()->assertSessionHasNoErrors();

    $plaintext = (string) flashed('freshKey');

    expect($plaintext)->toStartWith('ctx_test_');

    $verify = fn (string $id, string $secret): TestResponse => test()->withBasicAuth($id, $secret)
        ->postJson('/oauth/api-keys/verify', ['key' => $plaintext])
        ->assertOk();

    $keyId = app(CustomerApiKeys::class)->forUser($hansen->id, $bo->id)->sole()->id;
    $active = fn (array $permissions): array => [
        'active' => true,
        'key_id' => $keyId,
        'sub' => $bo->id,
        'org' => $hansen->id,
        'org_role' => 'member',
        'permissions' => $permissions,
        'client_id' => $clientId,
        'expires_at' => null,
    ];

    $verify($clientId, $secret)->assertExactJson($active(['tax:quote']));

    // Another app's credentials learn nothing about a key that is not theirs.
    $verify($ledger['client_id'], $ledger['client_secret'])->assertExactJson(['active' => false]);

    // Anna takes Bo's Editor role away on her People page: the same key, the next
    // request, carries nothing — it was only ever as strong as its holder.
    chainBrowser('anna');

    // (The staff role cannot be handed to him from here either, posted by id: he still
    // holds exactly the one role his invitation carried.)
    test()->from(route('directory.members'))->post(route('directory.members.access', $bo->id), [
        'role' => $roles['support']['id'],
        'granted' => true,
    ])->assertRedirect(route('directory.members'));

    $backend()->getJson("/api/v1/organizations/{$hansen->id}/members/{$bo->id}/roles")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.key', 'editor')
        ->assertJsonPath('data.0.organization_id', $hansen->id);

    test()->from(route('directory.members'))->post(route('directory.members.access', $bo->id), [
        'role' => $roles['editor']['id'],
        'granted' => false,
    ])->assertRedirect(route('directory.members'))->assertSessionHasNoErrors();

    $backend()->getJson("/api/v1/organizations/{$hansen->id}/members/{$bo->id}/roles")
        ->assertOk()
        ->assertExactJson(['data' => []]);

    $verify($clientId, $secret)->assertExactJson($active([]));

    // ── 8. Bo leaves Hansen Revision; its last owner cannot ─────────────────────────
    chainBrowser('bo');

    test()->from(route('directory.members'))->post(route('directory.members.leave'))
        ->assertRedirect(route('dashboard'))
        ->assertSessionHas('status', 'You left Hansen Revision.');

    // No longer a member, the key is dead with the membership.
    $verify($clientId, $secret)->assertExactJson(['active' => false]);

    chainBrowser('anna');

    test()->from(route('directory.members'))->post(route('directory.members.leave'))
        ->assertRedirect(route('directory.members'))
        ->assertSessionHasErrors(['member' => 'You are the only owner. Transfer ownership to someone else first — or delete the organization.']);

    // Read with the tenant scope lifted: a scoped read here could answer "nobody" for a
    // reason that has nothing to do with who is left.
    $roster = app(TenantContext::class)->withoutScope(fn (): array => Membership::query()
        ->where('organization_id', $hansen->id)
        ->get()
        ->mapWithKeys(fn (Membership $membership): array => [$membership->user_id => $membership->role->value])
        ->all());

    expect($roster)->toBe([$anna->id => 'owner']);
})->group('chain');
