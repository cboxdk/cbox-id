<?php

declare(strict_types=1);

use App\Platform\CurrentUser;
use App\Platform\EnvironmentAdminAuth;
use App\Platform\IntendedUrl;
use App\Platform\PlatformAuth;
use App\Platform\SsoMandates;
use Cbox\Id\Federation\Contracts\Connections;
use Cbox\Id\Federation\Enums\ConnectionType;
use Cbox\Id\Identity\Contracts\AuthPolicies;
use Cbox\Id\Identity\Contracts\BreachedPasswordCheck;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Identity\Enums\SsoEnforcement;
use Cbox\Id\Identity\NeverBreachedCheck;
use Cbox\Id\Identity\ValueObjects\AuthPolicy;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\Enums\ClientType;
use Cbox\Id\OAuthServer\Models\AuthorizationCode;
use Cbox\Id\OAuthServer\ValueObjects\NewClient;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Cbox\Id\Platform\PlatformRoot;

/**
 * "SSO required" as a rule about ENTERING AN ORGANIZATION (`cbox-id.sso.mandate_per_organization`).
 *
 * The person signs in however the environment allows. The organization that requires SSO
 * is entered only on a session that came through its own connection: by the request's
 * organization binding, by the authorize endpoint, and by the console's organization
 * address. Their other organizations never notice.
 */
beforeEach(function (): void {
    app()->instance(BreachedPasswordCheck::class, new NeverBreachedCheck);
    config(['cbox-id.sso.mandate_per_organization' => true]);
});

/**
 * Pat: a member of Acme, which requires SSO and has an active connection, and (unless
 * told otherwise) the owner of an organization of their own, which requires nothing. Acme
 * is the FIRST membership, which is the one a sign-in used to start in.
 *
 * @return array{subjectId: string, acme: string, own: ?string, startUrl: string}
 */
function mandatedPerson(bool $withOwn = true): array
{
    $acme = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-mandate'));
    $connection = app(Connections::class)->create(
        $acme->id,
        ConnectionType::Oidc,
        'Acme IdP',
        ['issuer' => 'https://idp.acme.test', 'client_id' => 'abc', 'client_secret' => 's', 'signing_key' => 'k'],
    );
    app(Connections::class)->activate($acme->id, $connection->id);

    $subject = app(Subjects::class)->create('pat@acme.test', 'Pat Doe', 'supersecret123');
    app(Memberships::class)->add($acme->id, $subject->id, MembershipRole::Member);

    $own = null;

    if ($withOwn) {
        $own = app(Organizations::class)->create(new NewOrganization('Pat Doe', 'pat-doe'))->id;
        app(Memberships::class)->add($own, $subject->id, MembershipRole::Owner);
    }

    app(AuthPolicies::class)->setForOrganization($acme->id, new AuthPolicy(sso: SsoEnforcement::Required));

    return ['subjectId' => $subject->id, 'acme' => $acme->id, 'own' => $own, 'startUrl' => url("/sso/oidc/{$connection->id}/redirect")];
}

/** The browser holds this session and nothing else: no organization chosen yet. */
function mandateBrowserSession(string $subjectId, ?string $organizationId, array $amr): void
{
    $session = app(SessionManager::class)->start($subjectId, $organizationId, $amr);

    session()->forget([PlatformAuth::ORG_KEY, PlatformAuth::ACCOUNTS_KEY, PlatformAuth::ACTIVE_KEY]);
    session([PlatformAuth::SESSION_KEY => $session->id]);
    app(CurrentUser::class)->clear();
}

function mandateClient(): string
{
    return app(ClientRegistry::class)->register(new NewClient(
        'Tax App',
        ClientType::Public,
        redirectUris: ['https://app.test/cb'],
        grantTypes: ['authorization_code', 'refresh_token'],
        scopes: ['openid', 'email'],
        firstParty: true,
    ))->client->client_id;
}

/** @return array<string, string> */
function mandateAuthorize(string $clientId, array $extra = []): array
{
    return ['client_id' => $clientId, 'redirect_uri' => 'https://app.test/cb', ...$extra];
}

it('keeps the per-person rule while the switch is off', function (): void {
    config(['cbox-id.sso.mandate_per_organization' => false]);
    $pat = mandatedPerson();

    expect(app(PlatformAuth::class)->localSignInAllowedFor($pat['subjectId']))->toBeFalse()
        ->and(app(SsoMandates::class)->admits(null, $pat['acme']))->toBeTrue();
});

it('lets a member of a mandating organization sign in with a password, into another organization', function (): void {
    $pat = mandatedPerson();

    attemptLogin(['email' => 'pat@acme.test'])->assertSessionHasNoErrors()->assertRedirect(route('dashboard'));

    $session = app(SessionManager::class)->active((string) session(PlatformAuth::SESSION_KEY));

    expect($session?->user_id)->toBe($pat['subjectId'])
        ->and($session?->organization_id)->toBe($pat['own'])
        ->and(session(PlatformAuth::ORG_KEY))->toBe($pat['own']);
});

it('judges a session by the organization whose connection opened it', function (): void {
    $pat = mandatedPerson();
    $mandates = app(SsoMandates::class);
    $sessions = app(SessionManager::class);

    $password = $sessions->start($pat['subjectId'], $pat['own'], ['pwd']);
    $throughAcme = $sessions->start($pat['subjectId'], $pat['acme'], ['sso']);
    $throughElsewhere = $sessions->start($pat['subjectId'], $pat['own'], ['sso']);
    $social = $sessions->start($pat['subjectId'], $pat['acme'], ['social', 'google']);
    $support = $sessions->start($pat['subjectId'], $pat['acme'], ['impersonation']);

    expect($mandates->admits($password, $pat['acme']))->toBeFalse()
        ->and($mandates->admits($throughAcme, $pat['acme']))->toBeTrue()
        ->and($mandates->admits($throughElsewhere, $pat['acme']))->toBeFalse()
        ->and($mandates->admits($social, $pat['acme']))->toBeFalse()
        ->and($mandates->admits($support, $pat['acme']))->toBeTrue()
        ->and($mandates->admits($password, (string) $pat['own']))->toBeTrue()
        ->and($mandates->admits($throughAcme, (string) $pat['own']))->toBeTrue();
});

it('authorizes an app in the person\'s other organization on a password session', function (): void {
    $pat = mandatedPerson();
    mandateBrowserSession($pat['subjectId'], $pat['acme'], ['pwd']);

    expect(leftFor(authorizeRequest(mandateAuthorize(mandateClient()))))->toStartWith('https://app.test/cb?code=')
        ->and(AuthorizationCode::query()->sole()->organization_id)->toBe($pat['own']);
});

it('sends an authorization for the mandating organization through its connection, and honours the session that comes back', function (): void {
    $pat = mandatedPerson();
    $clientId = mandateClient();
    mandateBrowserSession($pat['subjectId'], $pat['own'], ['pwd']);

    authorizeRequest(mandateAuthorize($clientId, ['organization' => $pat['acme']]))->assertRedirect($pat['startUrl']);

    expect(session(IntendedUrl::KEY))->toContain('/oauth/authorize')->toContain('organization='.$pat['acme'])
        ->and(AuthorizationCode::query()->count())->toBe(0);

    // The connection answered: the framework starts the session in its organization.
    mandateBrowserSession($pat['subjectId'], $pat['acme'], ['sso']);

    expect(leftFor($this->get((string) session(IntendedUrl::KEY))))->toStartWith('https://app.test/cb?code=')
        ->and(AuthorizationCode::query()->sole()->organization_id)->toBe($pat['acme']);
});

it('refuses instead of looping when the connection did not satisfy the mandate', function (): void {
    $pat = mandatedPerson();
    $clientId = mandateClient();
    mandateBrowserSession($pat['subjectId'], $pat['own'], ['pwd']);

    authorizeRequest(mandateAuthorize($clientId, ['organization' => $pat['acme']]))->assertRedirect($pat['startUrl']);

    expect(leftFor($this->get((string) session(IntendedUrl::KEY))))
        ->toStartWith('https://app.test/cb?')->toContain('error=access_denied');
});

it('answers a silent authorization for the mandating organization with interaction_required', function (): void {
    $pat = mandatedPerson();
    mandateBrowserSession($pat['subjectId'], $pat['own'], ['pwd']);

    expect(leftFor(authorizeRequest(mandateAuthorize(mandateClient(), ['organization' => $pat['acme'], 'prompt' => 'none']))))
        ->toContain('error=interaction_required');
});

it('asks somebody whose only organization requires SSO to choose it, and sends them through its connection', function (): void {
    $pat = mandatedPerson(withOwn: false);
    mandateBrowserSession($pat['subjectId'], null, ['pwd']);

    $picker = authorizeRequest(mandateAuthorize(mandateClient()));
    $picker->assertRedirect();

    expect((string) $picker->headers->get('Location'))->toEndWith('/organization');

    $props = (array) $this->get((string) $picker->headers->get('Location'))->assertOk()->inertiaProps();

    $chosen = inertiaRequest(fn () => $this->post($props['chooseHref'], ['organization' => $pat['acme']]));

    expect(leftFor($chosen))->toBe($pat['startUrl'])
        ->and(session(IntendedUrl::KEY))->toContain('organization='.$pat['acme'])
        ->and(AuthorizationCode::query()->count())->toBe(0);
});

it('opens the mandating organization\'s console only on a session through its connection', function (): void {
    $account = provisionAccount('owner@mandate.example');
    $organizationId = $account['organization']->id;
    $root = app(PlatformRoot::class);

    $startUrl = $root->run(function () use ($organizationId): string {
        $connection = app(Connections::class)->create(
            $organizationId,
            ConnectionType::Oidc,
            'Acme IdP',
            ['issuer' => 'https://idp.acme.test', 'client_id' => 'abc', 'client_secret' => 's', 'signing_key' => 'k'],
        );
        app(Connections::class)->activate($organizationId, $connection->id);
        app(AuthPolicies::class)->setForOrganization($organizationId, new AuthPolicy(sso: SsoEnforcement::Required));

        return url("/sso/oidc/{$connection->id}/redirect");
    });

    $front = route('orgs.show', ['org' => orgSlug($organizationId)]);

    $root->run(fn () => mandateBrowserSession($account['subjectId'], null, ['pwd']));
    $this->get($front)->assertRedirect($startUrl);
    expect(session(IntendedUrl::KEY))->toBe($front);
    $this->post(route('orgs.switch'), ['organization' => $organizationId, 'path' => ''])->assertRedirect();

    $root->run(fn () => mandateBrowserSession($account['subjectId'], $organizationId, ['sso']));
    expect((string) $this->get($front)->assertRedirect()->headers->get('Location'))->not->toBe($startUrl);
});

it('administers the mandating organization\'s environment only on a session through its connection', function (): void {
    multiTenantDeployment();
    config(['cbox-id.environments.base_domains' => ['cboxid.com']]);
    $account = provisionAccount('owner@mandate-env.example');
    $organizationId = $account['organization']->id;

    app(PlatformRoot::class)->run(fn () => app(AuthPolicies::class)->setForOrganization($organizationId, new AuthPolicy(sso: SsoEnforcement::Required)));

    // A password session, as a member who signed in to the realm another way holds. Under
    // the per-person rule nothing asks it here: the doors are what refuse.
    config(['cbox-id.sso.mandate_per_organization' => false]);
    actAsEnvironmentAdmin($account['subjectId'], $account['environment']->id);
    $this->get(envConsole())->assertOk();

    config(['cbox-id.sso.mandate_per_organization' => true]);
    app()->forgetInstance(EnvironmentAdminAuth::class);
    app()->forgetScopedInstances();
    $this->get(envConsole())->assertNotFound();
});
