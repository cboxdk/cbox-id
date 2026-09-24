<?php

declare(strict_types=1);

use App\Mail\InvitationMail;
use App\Platform\CurrentUser;
use App\Platform\Invitations\Contracts\OrganizationInvitations;
use App\Platform\Invitations\ValueObjects\Inviter;
use App\Platform\Invitations\ValueObjects\NewInvitation;
use App\Platform\OAuth\Contracts\AuthorizationOrganizations;
use App\Platform\PlatformAuth;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Kernel\Crypto\Contracts\TokenSigner;
use Cbox\Id\Kernel\Crypto\Enums\SigningAlg;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\Contracts\IssuerResolver;
use Cbox\Id\Kernel\Tenancy\Contracts\TenantContext;
use Cbox\Id\Kernel\Tenancy\GenericEnvironment;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\Contracts\PushedAuthorizationRequests;
use Cbox\Id\OAuthServer\Enums\ClientType;
use Cbox\Id\OAuthServer\Models\AuthorizationCode;
use Cbox\Id\OAuthServer\ValueObjects\NewClient;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\Enums\MembershipStatus;
use Cbox\Id\Organization\Models\Membership;
use Cbox\Id\Organization\Models\Organization;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;

/**
 * `organization`, `organization_hint`, `prompt=select_organization` and
 * `prompt=create_organization` on /oauth/authorize — the contract every Cbox ID SDK already
 * sends. id-js refuses tokens whose `org` is not the one it asked for, so the assertion
 * that matters most here is the one on the TOKEN, not on the redirect.
 */
const ORG_SELECTION_VERIFIER = 'an-organization-selection-verifier-0123456789';

/**
 * A person in two organizations — Owner of Acme (where their session is) and Member of
 * Globex — signed in the way a real request arrives.
 *
 * @return array{subjectId: string, acme: Organization, globex: Organization}
 */
function orgSelectionPerson(): array
{
    $subject = app(Subjects::class)->create('pat@acme.test', 'Pat Doe', 'supersecret123');
    $acme = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-selection'));
    $globex = app(Organizations::class)->create(new NewOrganization('Globex', 'globex-selection'));

    app(Memberships::class)->add($acme->id, $subject->id, MembershipRole::Owner);
    app(Memberships::class)->add($globex->id, $subject->id, MembershipRole::Member);

    $session = app(SessionManager::class)->start($subject->id, $acme->id, ['pwd']);
    session([PlatformAuth::SESSION_KEY => $session->id]);
    app(CurrentUser::class)->set($subject, $session, $acme, MembershipRole::Owner);

    return ['subjectId' => $subject->id, 'acme' => $acme, 'globex' => $globex];
}

/**
 * Put a membership in a state no service writes on its own (an unaccepted invitation, a
 * suspension). Straight to the row, with the tenant scope lifted — a scoped update here
 * matched nothing and left every "refused" case below passing on an active membership.
 */
function orgSelectionMembershipStatus(string $organizationId, string $subjectId, MembershipStatus $status): void
{
    $updated = app(TenantContext::class)->withoutScope(fn (): int => Membership::query()
        ->where('organization_id', $organizationId)
        ->where('user_id', $subjectId)
        ->update(['status' => $status->value]));

    expect($updated)->toBe(1);
}

/** A public app, first-party (no consent screen) unless told otherwise, owned by the platform. */
function orgSelectionClient(bool $firstParty = true): string
{
    return app(ClientRegistry::class)->register(new NewClient(
        'Tax App',
        ClientType::Public,
        redirectUris: ['https://app.test/cb'],
        grantTypes: ['authorization_code', 'refresh_token'],
        scopes: ['openid', 'profile', 'offline_access'],
        firstParty: $firstParty,
    ))->client->client_id;
}

/** @return array<string, string> */
function orgSelectionParams(string $clientId, array $extra = []): array
{
    return array_merge([
        'client_id' => $clientId,
        'redirect_uri' => 'https://app.test/cb',
        'scope' => 'openid profile offline_access',
        'state' => 'st',
        'code_challenge' => pkcePair(ORG_SELECTION_VERIFIER)['challenge'],
        'response_type' => 'code',
        'code_challenge_method' => 'S256',
    ], $extra);
}

/** The RFC 6749 §4.1.2.1 error redirect, `iss` and all. */
function orgSelectionError(string $error, string $description): string
{
    return 'https://app.test/cb?error='.$error
        .'&error_description='.urlencode($description)
        .'&state=st&iss='.urlencode(app(IssuerResolver::class)->issuer());
}

/** @return array<string, string> the query the browser was sent back to the app with */
function orgSelectionCallback(TestResponse $response): array
{
    $location = leftFor($response);

    expect($location)->toStartWith('https://app.test/cb?');

    parse_str((string) parse_url((string) $location, PHP_URL_QUERY), $query);

    /** @var array<string, string> $query */
    return $query;
}

/**
 * Redeem a code exactly as the SDK does, and return the verified claims of both tokens.
 *
 * @return array{access: array<string, mixed>, id: array<string, mixed>, refresh: string|null}
 */
function orgSelectionTokens(string $clientId, string $code): array
{
    $response = test()->postJson('/oauth/token', [
        'grant_type' => 'authorization_code',
        'client_id' => $clientId,
        'code' => $code,
        'redirect_uri' => 'https://app.test/cb',
        'code_verifier' => ORG_SELECTION_VERIFIER,
    ])->assertOk();

    $signer = app(TokenSigner::class);

    return [
        'access' => $signer->verify((string) $response->json('access_token'), [SigningAlg::RS256])->all(),
        'id' => $signer->verify((string) $response->json('id_token'), [SigningAlg::RS256])->all(),
        'refresh' => is_string($refresh = $response->json('refresh_token')) ? $refresh : null,
    ];
}

it('binds the grant to the requested organization, not the session\'s, all the way into the tokens', function (): void {
    ['acme' => $acme, 'globex' => $globex] = orgSelectionPerson();
    $clientId = orgSelectionClient();

    $query = orgSelectionCallback(authorizeRequest(orgSelectionParams($clientId, ['organization' => $globex->id])));

    expect(AuthorizationCode::query()->sole()->organization_id)->toBe($globex->id);

    // The id-js expectation: a switch returns tokens for EXACTLY the requested org, with the
    // person's role there — not Acme's, where their session is and where they are Owner.
    $tokens = orgSelectionTokens($clientId, $query['code']);

    expect($tokens['access']['org'])->toBe($globex->id)
        ->and($tokens['access']['org_role'])->toBe('member')
        ->and($tokens['id']['org'])->toBe($globex->id)
        ->and($tokens['id']['org_name'])->toBe('Globex')
        ->and($tokens['access']['org'])->not->toBe($acme->id);

    // …and a refresh keeps it: the binding is the grant's, not the request's.
    $refreshed = test()->postJson('/oauth/token', [
        'grant_type' => 'refresh_token',
        'client_id' => $clientId,
        'refresh_token' => $tokens['refresh'],
    ])->assertOk();

    expect(app(TokenSigner::class)->verify((string) $refreshed->json('access_token'), [SigningAlg::RS256])->get('org'))
        ->toBe($globex->id);
});

it('still binds to the session\'s organization when the app names none', function (): void {
    ['acme' => $acme] = orgSelectionPerson();
    $clientId = orgSelectionClient();

    orgSelectionCallback(authorizeRequest(orgSelectionParams($clientId)));

    expect(AuthorizationCode::query()->sole()->organization_id)->toBe($acme->id);
});

it('switches silently under prompt=none for a first-party app', function (): void {
    ['globex' => $globex] = orgSelectionPerson();
    $clientId = orgSelectionClient();

    $query = orgSelectionCallback(authorizeRequest(orgSelectionParams($clientId, [
        'organization' => $globex->id,
        'prompt' => 'none',
    ])));

    expect($query)->toHaveKey('code')
        ->and(AuthorizationCode::query()->sole()->organization_id)->toBe($globex->id);
});

/**
 * EVERY WAY AN ORGANIZATION CAN BE OUT OF REACH gets the same answer, and it is the
 * client's to handle: `access_denied`, no code. One description for all of them, so the
 * error never tells an app which organizations exist.
 */
it('refuses an organization the person cannot use with access_denied', function (string $case): void {
    ['subjectId' => $subjectId, 'globex' => $globex] = orgSelectionPerson();
    $clientId = orgSelectionClient();

    $target = match ($case) {
        'not a member' => app(Organizations::class)->create(new NewOrganization('Initech', 'initech-selection'))->id,
        'does not exist' => '01jzzzzzzzzzzzzzzzzzzzzzzz',
        'membership suspended', 'invitation not accepted' => $globex->id,
        'organization suspended' => app(Organizations::class)->suspend($globex->id, $subjectId)->id,
        'organization deleted' => app(Organizations::class)->archive($globex->id, $subjectId)->id,
    };

    if ($case === 'membership suspended' || $case === 'invitation not accepted') {
        orgSelectionMembershipStatus($globex->id, $subjectId,
            $case === 'membership suspended' ? MembershipStatus::Suspended : MembershipStatus::Invited);
    }

    authorizeRequest(orgSelectionParams($clientId, ['organization' => $target]))
        ->assertRedirect(orgSelectionError('access_denied', 'The user is not an active member of the requested organization.'));

    expect(AuthorizationCode::query()->count())->toBe(0);
})->with([
    'not a member',
    'does not exist',
    'membership suspended',
    'invitation not accepted',
    'organization suspended',
    'organization deleted',
])->group('security');

/**
 * Refused at ARRIVAL, not only when the code is minted: a third-party app naming another
 * tenant's organization must not get as far as a consent screen that says "Globex" — the
 * person would be asked to agree to something they cannot have.
 */
it('refuses an organization out of reach before any consent screen is drawn', function (): void {
    orgSelectionPerson();
    $clientId = orgSelectionClient(firstParty: false);
    $initech = app(Organizations::class)->create(new NewOrganization('Initech', 'initech-arrival'));

    authorizeRequest(orgSelectionParams($clientId, ['organization' => $initech->id]))
        ->assertRedirect(orgSelectionError('access_denied', 'The user is not an active member of the requested organization.'));
})->group('security');

it('answers prompt=none with access_denied, not interaction_required, for an organization out of reach', function (): void {
    orgSelectionPerson();
    $clientId = orgSelectionClient();
    $initech = app(Organizations::class)->create(new NewOrganization('Initech', 'initech-silent'));

    authorizeRequest(orgSelectionParams($clientId, ['organization' => $initech->id, 'prompt' => 'none']))
        ->assertRedirect(orgSelectionError('access_denied', 'The user is not an active member of the requested organization.'));
})->group('security');

/**
 * THE ENVIRONMENT IS IN THE WHERE CLAUSE. A person can hold a membership row in an
 * organization of ANOTHER environment (the platform root's account members do); binding to
 * it from this environment's `/authorize` would hand this environment's app another
 * environment's tenant. Asked with the tenant scope suspended, because a guard that only
 * holds behind a scoped read can never fail.
 */
it('never binds to an organization in another environment, even with tenant scoping suspended', function (): void {
    ['subjectId' => $subjectId] = orgSelectionPerson();
    $clientId = orgSelectionClient();
    $environments = app(EnvironmentContext::class);

    $foreign = $environments->runAs(GenericEnvironment::of('env_elsewhere'), function () use ($subjectId): Organization {
        $organization = app(Organizations::class)->create(new NewOrganization('Elsewhere', 'elsewhere'));
        app(Memberships::class)->add($organization->id, $subjectId, MembershipRole::Owner);

        return $organization;
    });

    $service = app(AuthorizationOrganizations::class);

    expect($environments->withoutScope(fn () => $service->usableBy($subjectId, $foreign->id)))->toBeNull()
        ->and(array_map(fn ($choice) => $choice->name, $environments->withoutScope(fn () => $service->choicesFor($subjectId))))
        ->toBe(['Acme', 'Globex']);

    authorizeRequest(orgSelectionParams($clientId, ['organization' => $foreign->id]))
        ->assertRedirect(orgSelectionError('access_denied', 'The user is not an active member of the requested organization.'));
})->group('security');

/**
 * A membership removed while the consent screen sat open. The check at arrival passed; the
 * code is minted on the approval, and must not assert a role the person no longer holds.
 */
it('re-checks the bound organization when the code is minted', function (): void {
    ['subjectId' => $subjectId, 'globex' => $globex] = orgSelectionPerson();
    $clientId = orgSelectionClient(firstParty: false);

    $props = consentScreen(orgSelectionParams($clientId, ['organization' => $globex->id]));

    expect($props['organization'])->toBe('Globex');

    app(Memberships::class)->remove($globex->id, $subjectId);

    expect(leftFor(answerConsent($props)))
        ->toBe(orgSelectionError('access_denied', 'The user is not an active member of the requested organization.'))
        ->and(AuthorizationCode::query()->count())->toBe(0);
})->group('security');

/**
 * Two meanings in one request are refused, not guessed between — the same combinations
 * id-js refuses before the redirect, so every other client gets the same rule.
 */
it('refuses organization parameters that contradict each other', function (array $params, string $description): void {
    ['globex' => $globex] = orgSelectionPerson();
    $clientId = orgSelectionClient();

    $params = array_map(fn (string $value): string => str_replace('{globex}', $globex->id, $value), $params);

    authorizeRequest(orgSelectionParams($clientId, $params))
        ->assertRedirect(orgSelectionError('invalid_request', $description));

    expect(AuthorizationCode::query()->count())->toBe(0);
})->with([
    'organization + select_organization' => [
        ['organization' => '{globex}', 'prompt' => 'select_organization'],
        'organization binds the request to one organization, so prompt=select_organization has nothing to choose. Send organization_hint to preselect one in the picker instead.',
    ],
    'organization + create_organization' => [
        ['organization' => '{globex}', 'prompt' => 'create_organization'],
        'organization binds the request to an existing organization and prompt=create_organization creates a new one. Send one or the other.',
    ],
    'empty organization' => [
        ['organization' => ''],
        'The organization parameter is empty. Omit it to authorize without binding to an organization.',
    ],
    'none + select_organization' => [
        ['prompt' => 'none select_organization'],
        'prompt=none cannot be combined with another prompt value.',
    ],
    'create + organization' => [
        ['organization' => '{globex}', 'prompt' => 'create'],
        'prompt=create signs up a new account, which is not a member of any organization yet. Omit organization.',
    ],
    'create + create_organization' => [
        ['prompt' => 'create create_organization'],
        'prompt=create already asks the new account for its organization. Omit create_organization.',
    ],
]);

/**
 * PAR: the pushed parameters ARE the request (RFC 9126 §4). A link carrying a pushed
 * request_uri plus `&organization=` must not bind the grant to an organization the client
 * never pushed — it would be one of the person's own, so no membership check stops it.
 */
it('takes the organization from the pushed request alone', function (): void {
    ['acme' => $acme, 'globex' => $globex] = orgSelectionPerson();
    $clientId = orgSelectionClient();
    $client = app(ClientRegistry::class)->byClientId($clientId);

    // Pushed WITH an organization: honoured.
    $pushed = app(PushedAuthorizationRequests::class)->push($client, orgSelectionParams($clientId, ['organization' => $globex->id]));
    orgSelectionCallback(authorizeRequest(['client_id' => $clientId, 'request_uri' => $pushed['request_uri']]));

    expect(AuthorizationCode::query()->sole()->organization_id)->toBe($globex->id);

    AuthorizationCode::query()->delete();

    // Pushed WITHOUT one, and one smuggled onto the query: ignored.
    $pushed = app(PushedAuthorizationRequests::class)->push($client, orgSelectionParams($clientId));
    orgSelectionCallback(authorizeRequest([
        'client_id' => $clientId,
        'request_uri' => $pushed['request_uri'],
        'organization' => $globex->id,
    ]));

    expect(AuthorizationCode::query()->sole()->organization_id)->toBe($acme->id);
})->group('security');

it('carries the organization request across the sign-in round trip', function (): void {
    $clientId = orgSelectionClient();

    authorizeRequest(orgSelectionParams($clientId, [
        'organization' => 'org_123',
        'organization_hint' => 'org_456',
        'prompt' => 'login consent',
    ]))->assertRedirect(route('login'));

    parse_str((string) parse_url((string) session('url.intended'), PHP_URL_QUERY), $resume);

    expect($resume['organization'] ?? null)->toBe('org_123')
        ->and($resume['organization_hint'] ?? null)->toBe('org_456')
        ->and($resume['prompt'] ?? null)->toBe('login consent')
        ->and($resume['reauthed'] ?? null)->toBe('1');
});

it('shows the consent screen to a first-party app when it asks for prompt=consent', function (): void {
    orgSelectionPerson();
    $clientId = orgSelectionClient();

    authorizeRequest(orgSelectionParams($clientId, ['prompt' => 'consent']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('oauth/consent')->where('organization', 'Acme'));

    expect(AuthorizationCode::query()->count())->toBe(0);
});

// ── The hosted picker ─────────────────────────────────────────────────────────────────

/** Follow /authorize to the picker and return its id and props. */
function orgSelectionPicker(string $clientId, array $extra = []): array
{
    $location = authorizeRequest(orgSelectionParams($clientId, ['prompt' => 'select_organization', ...$extra]))
        ->assertRedirect()
        ->headers->get('Location');

    expect($location)->toContain('/organization');

    $props = (array) test()->get((string) $location)->assertOk()->inertiaProps();

    return [(string) $location, $props];
}

it('shows the picker with only the organizations the person may use here', function (): void {
    ['subjectId' => $subjectId, 'globex' => $globex] = orgSelectionPerson();
    $clientId = orgSelectionClient();

    // Out of reach, each for a different reason — none may be offered.
    $suspended = app(Organizations::class)->create(new NewOrganization('Suspended Co', 'suspended-co'));
    app(Memberships::class)->add($suspended->id, $subjectId, MembershipRole::Admin);
    app(Organizations::class)->suspend($suspended->id, $subjectId);

    $invited = app(Organizations::class)->create(new NewOrganization('Invited Co', 'invited-co'));
    app(Memberships::class)->add($invited->id, $subjectId, MembershipRole::Member);
    orgSelectionMembershipStatus($invited->id, $subjectId, MembershipStatus::Invited);

    [, $props] = orgSelectionPicker($clientId, ['organization_hint' => $globex->id]);

    expect(array_column($props['organizations'], 'name'))->toBe(['Acme', 'Globex'])
        ->and(array_column($props['organizations'], 'role'))->toBe(['Owner', 'Member'])
        // The hint preselects…
        ->and($props['selected'])->toBe($globex->id)
        ->and($props['client']['name'])->toBe('Tax App');
});

it('ignores a hint the person cannot use, and suggests where they are working', function (): void {
    ['acme' => $acme] = orgSelectionPerson();
    $clientId = orgSelectionClient();
    $initech = app(Organizations::class)->create(new NewOrganization('Initech', 'initech-hint'));

    [, $props] = orgSelectionPicker($clientId, ['organization_hint' => $initech->id]);

    expect($props['selected'])->toBe($acme->id)
        ->and(array_column($props['organizations'], 'id'))->not->toContain($initech->id);
});

it('binds the grant to the organization chosen on the picker', function (): void {
    ['acme' => $acme, 'globex' => $globex] = orgSelectionPerson();
    $clientId = orgSelectionClient();

    [, $props] = orgSelectionPicker($clientId);

    $response = inertiaRequest(fn (): TestResponse => test()->from(route('oauth.authorize'))
        ->post($props['chooseHref'], ['organization' => $globex->id]));

    $query = orgSelectionCallback($response);
    $tokens = orgSelectionTokens($clientId, $query['code']);

    expect($tokens['access']['org'])->toBe($globex->id)
        ->and($tokens['access']['org_role'])->toBe('member');

    // REMEMBERS NOTHING: the session is still in Acme, so the console and the next app
    // are exactly where they were.
    expect(session(PlatformAuth::ORG_KEY) ?? app(CurrentUser::class)->organizationId())->toBe($acme->id);
});

it('goes through the consent screen after the picker for a third-party app', function (): void {
    ['globex' => $globex] = orgSelectionPerson();
    $clientId = orgSelectionClient(firstParty: false);

    [, $props] = orgSelectionPicker($clientId);

    $review = test()->from(route('oauth.authorize'))
        ->post($props['chooseHref'], ['organization' => $globex->id])
        ->assertRedirect()
        ->headers->get('Location');

    $consent = (array) test()->get((string) $review)->assertOk()->inertiaProps();

    expect($consent['organization'])->toBe('Globex')
        ->and(AuthorizationCode::query()->count())->toBe(0);

    orgSelectionCallback(answerConsent($consent));

    expect(AuthorizationCode::query()->sole()->organization_id)->toBe($globex->id);
});

/**
 * THE POSTED ID IS A CLAIM. The page listed the person's organizations; the request that
 * uses the choice asks again, so an id edited into the form — another tenant's — binds
 * nothing.
 */
it('refuses a choice the person cannot use, and mints nothing', function (): void {
    orgSelectionPerson();
    $clientId = orgSelectionClient();
    $initech = app(Organizations::class)->create(new NewOrganization('Initech', 'initech-forged'));

    [$picker, $props] = orgSelectionPicker($clientId);

    test()->from($picker)->post($props['chooseHref'], ['organization' => $initech->id])
        ->assertRedirect($picker)
        ->assertSessionHasErrors(['organization' => 'You are not an active member of that organization.']);

    expect(AuthorizationCode::query()->count())->toBe(0);
})->group('security');

it('spends the picker once', function (): void {
    ['globex' => $globex] = orgSelectionPerson();
    $clientId = orgSelectionClient();

    [, $props] = orgSelectionPicker($clientId);

    orgSelectionCallback(inertiaRequest(fn (): TestResponse => test()->from(route('oauth.authorize'))
        ->post($props['chooseHref'], ['organization' => $globex->id])));

    // A second submit from a stale tab: no second code.
    $again = inertiaRequest(fn (): TestResponse => test()->from(route('oauth.authorize'))
        ->post($props['chooseHref'], ['organization' => $globex->id]));

    expect(consentRefusal($again))->toBe('This authorization request can no longer be completed. Please start again.')
        ->and(AuthorizationCode::query()->count())->toBe(1);
})->group('security');

it('returns access_denied to the app when the person cancels on the picker', function (): void {
    orgSelectionPerson();
    $clientId = orgSelectionClient();

    [, $props] = orgSelectionPicker($clientId);

    $response = inertiaRequest(fn (): TestResponse => test()->from(route('oauth.authorize'))->post($props['denyHref']));

    expect(orgSelectionCallback($response)['error'])->toBe('access_denied');
});

// ── The hosted create step ────────────────────────────────────────────────────────────

it('creates an organization owned by the person and binds the grant to it', function (): void {
    ['subjectId' => $subjectId] = orgSelectionPerson();
    $clientId = orgSelectionClient();

    $location = authorizeRequest(orgSelectionParams($clientId, ['prompt' => 'create_organization']))
        ->assertRedirect()
        ->headers->get('Location');

    $props = (array) test()->get((string) $location)->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('oauth/create-organization'))
        ->inertiaProps();

    $query = orgSelectionCallback(inertiaRequest(fn (): TestResponse => test()->from((string) $location)
        ->post($props['storeHref'], ['name' => 'Anna\'s Bakery'])));

    $created = Organization::query()->where('name', 'Anna\'s Bakery')->sole();

    expect(app(Memberships::class)->activeRole($created->id, $subjectId))->toBe(MembershipRole::Owner);

    $tokens = orgSelectionTokens($clientId, $query['code']);

    expect($tokens['access']['org'])->toBe($created->id)
        ->and($tokens['access']['org_role'])->toBe('owner');
});

it('requires a name for the new organization', function (): void {
    orgSelectionPerson();
    $clientId = orgSelectionClient();

    $location = (string) authorizeRequest(orgSelectionParams($clientId, ['prompt' => 'create_organization']))->headers->get('Location');
    $props = (array) test()->get($location)->inertiaProps();

    test()->from($location)->post($props['storeHref'], ['name' => '   '])
        ->assertSessionHasErrors(['name' => 'Give your organization a name.']);

    expect(Organization::query()->count())->toBe(2);
});

it('limits how many organizations one person can create in an hour', function (): void {
    orgSelectionPerson();
    $clientId = orgSelectionClient();

    for ($i = 1; $i <= 6; $i++) {
        $location = (string) authorizeRequest(orgSelectionParams($clientId, ['prompt' => 'create_organization']))->headers->get('Location');
        $props = (array) test()->get($location)->inertiaProps();

        $response = inertiaRequest(fn (): TestResponse => test()->from($location)->post($props['storeHref'], ['name' => 'Team '.$i]));
    }

    // Five went through; the sixth was refused with the reason, and created nothing.
    expect(Organization::query()->where('name', 'like', 'Team %')->count())->toBe(5)
        ->and(session('errors')?->first('name'))->toStartWith('You have created several organizations in a short time.');

    RateLimiter::clear('oauth-create-organization');
})->group('security');

// ── Invitations that land in an app ───────────────────────────────────────────────────

/**
 * An invitation sent on an app's behalf (C1's `client_id` + `return_to`) lands the person in
 * the app. The app's next step is an authorization — usually without `organization`,
 * because it does not know yet which team the person just joined — and that authorization
 * must come back bound to the team they were invited to, not the one they were already in.
 *
 * It does, and simply: accepting switches the session into the invited organization, and an
 * authorization that names none binds to the session's. So the app gets the right `org`
 * without the invitation having to know anything about OAuth.
 */
it('binds the app\'s next authorization to the organization an invitation just joined', function (): void {
    Mail::fake();
    installedDeployment();

    ['subjectId' => $subjectId, 'acme' => $acme] = orgSelectionPerson();
    $clientId = orgSelectionClient();
    $initech = app(Organizations::class)->create(new NewOrganization('Initech', 'initech-invite'));

    app(OrganizationInvitations::class)->send(new NewInvitation(
        organizationId: $initech->id,
        email: 'pat@acme.test',
        role: MembershipRole::Member,
        inviter: new Inviter(null, 'Initech Owner'),
        clientId: $clientId,
        returnTo: 'https://app.test/welcome',
    ));

    preg_match('#/invitations/([^/]+)/accept#', (string) Mail::sent(InvitationMail::class)->last()?->url, $token);

    test()->post(route('invitation.accept.store', $token[1] ?? ''))->assertRedirect('https://app.test/welcome');

    orgSelectionCallback(authorizeRequest(orgSelectionParams($clientId)));

    expect(AuthorizationCode::query()->sole()->organization_id)->toBe($initech->id)
        ->and($initech->id)->not->toBe($acme->id)
        ->and(app(Memberships::class)->activeRole($initech->id, $subjectId))->toBe(MembershipRole::Member);
});
