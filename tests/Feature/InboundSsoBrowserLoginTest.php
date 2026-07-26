<?php

declare(strict_types=1);

use App\Platform\PlatformAuth;
use Cbox\Id\Federation\Contracts\AssertionValidator;
use Cbox\Id\Federation\Contracts\Connections;
use Cbox\Id\Federation\Enums\ConnectionType;
use Cbox\Id\Federation\Exceptions\InvalidAssertion;
use Cbox\Id\Federation\Models\Connection;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Identity\Exceptions\AccountExistsForEmail;
use Cbox\Id\Identity\ValueObjects\FederatedPrincipal;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(fn () => Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 200)]));

/**
 * The framework validates the assertion and hands back a session, then documents that a
 * hosting app must turn it into a cookie. This app never did: an enterprise user
 * authenticated at Okta/Entra and landed on a raw JSON blob, never signed in. That is
 * the whole value proposition of B2B SSO.
 *
 * The PROTOCOL is the framework's concern and is proven there against real signed
 * assertions. What is tested here is the inch the app owns: session adoption, the
 * redirect, and what a human sees when it fails.
 */
function ssoConnection(): object
{
    $org = app(Organizations::class)->create(new NewOrganization('Acme', 'acme'));
    $connections = app(Connections::class);
    $connection = $connections->create($org->id, ConnectionType::Saml, 'Okta', []);
    $connections->activate($org->id, $connection->id);

    return (object) ['org' => $org, 'connection' => $connection->refresh()];
}

/**
 * Put the request on the ACCOUNT plane: base_domains set (multi-tenant SaaS shape) and
 * the current environment IS the `is_default` platform root — which is exactly what
 * PlaneResolver::onAccountPlane() asks. Local to this file rather than borrowed from
 * another test's global helper, so running this file alone still stands up the plane.
 */
function ssoRootEnvironment(): Environment
{
    config(['cbox-id.environments.base_domains' => ['cboxid.com']]);

    $root = Environment::query()->create([
        'name' => 'Production',
        'slug' => 'production',
        'status' => 'active',
        'is_default' => true,
    ]);

    app(EnvironmentContext::class)->set($root);

    return $root;
}

/** Bind a validator that returns a principal for the given email, bypassing XML-DSig. */
function fakeAssertionFor(string $email): void
{
    $principal = new FederatedPrincipal(
        provider: 'saml',
        subject: 'idp|'.md5($email),
        email: $email,
        name: 'SSO User',
    );

    app()->bind(AssertionValidator::class, fn () => new class($principal) implements AssertionValidator
    {
        public function __construct(private readonly FederatedPrincipal $principal) {}

        public function validate(Connection $connection, string $rawResponse): FederatedPrincipal
        {
            return $this->principal;
        }
    });
}

it('signs the browser in and lands on the dashboard after a SAML assertion', function (): void {
    $fixture = ssoConnection();
    fakeAssertionFor('enterprise.user@acme.example');

    $response = $this->post('/sso/saml/'.$fixture->connection->id.'/acs', [
        'SAMLResponse' => 'irrelevant-the-validator-is-faked',
    ]);

    // Not JSON. A redirect, with a real session behind it.
    $response->assertRedirect();
    expect($response->headers->get('Location'))->not->toContain('/login');

    $active = session(PlatformAuth::SESSION_KEY);
    expect($active)->not->toBeNull();

    // …and the user actually exists and is reachable as the signed-in subject.
    $subject = app(Subjects::class)->findByEmail('enterprise.user@acme.example');
    expect($subject)->not->toBeNull();
});

it('sends the user back to sign-in with a readable message when the assertion is rejected', function (): void {
    $fixture = ssoConnection();

    app()->bind(AssertionValidator::class, fn () => new class implements AssertionValidator
    {
        public function validate(Connection $connection, string $rawResponse): FederatedPrincipal
        {
            throw InvalidAssertion::make('signature mismatch');
        }
    });

    $response = $this->post('/sso/saml/'.$fixture->connection->id.'/acs', [
        'SAMLResponse' => 'forged',
    ]);

    $response->assertRedirect(route('login'));
    // `email`, not `identifier`: it is the key BOTH sign-in screens actually render.
    // Under `identifier` the message reached the session and no view ever read it, so
    // the user was returned to a blank form with nothing explaining the failure.
    $response->assertSessionHasErrors('email');

    // The message must not leak WHY the assertion failed — that is a forgery oracle.
    $error = session('errors')->first('email');
    expect($error)->not->toContain('signature')
        ->and($error)->toContain('could not verify');

    expect(session(PlatformAuth::SESSION_KEY))->toBeNull();
});

it('refuses an unknown or inactive connection without starting a session', function (): void {
    $response = $this->post('/sso/saml/con_does_not_exist/acs', ['SAMLResponse' => 'x']);

    $response->assertRedirect(route('login'));
    expect(session(PlatformAuth::SESSION_KEY))->toBeNull();
});

it('explains the collision when an account already exists for that email', function (): void {
    $fixture = ssoConnection();

    // A password account already owns this address.
    $existing = app(Subjects::class)->create('collide@acme.example', 'Existing', password: 'a-strong-unbreached-passphrase');
    app(Memberships::class)->add($fixture->org->id, $existing->id, MembershipRole::Member);

    app()->bind(AssertionValidator::class, fn () => new class implements AssertionValidator
    {
        public function validate(Connection $connection, string $rawResponse): FederatedPrincipal
        {
            throw new AccountExistsForEmail('collide@acme.example');
        }
    });

    $response = $this->post('/sso/saml/'.$fixture->connection->id.'/acs', ['SAMLResponse' => 'x']);

    $response->assertRedirect(route('login'));
    expect(session('errors')->first('email'))->toContain('already exists');
});

/*
 * THE ACCOUNT PLANE'S FAILURE BRANCH.
 *
 * The SSO callbacks are deliberately not plane-gated: an account org with a verified
 * domain federates on the platform-root host itself. The SUCCESS path was taught to fork
 * on the plane; the ERROR path was not, and kept redirecting to `/login` — a
 * `plane:subject` route that 404s here. So a member whose assertion failed validation
 * (expired, clock skew, signature mismatch, unknown NameID) got a bare 404 AFTER
 * authenticating successfully at their IdP, and had every reason to think SSO had worked.
 */
it('sends an account-plane SSO failure to the workspace sign-in, not a 404', function (): void {
    ssoRootEnvironment();
    $fixture = ssoConnection();

    app()->bind(AssertionValidator::class, fn () => new class implements AssertionValidator
    {
        public function validate(Connection $connection, string $rawResponse): FederatedPrincipal
        {
            throw InvalidAssertion::make('clock skew');
        }
    });

    $response = $this->post('/sso/saml/'.$fixture->connection->id.'/acs', ['SAMLResponse' => 'forged']);

    $response->assertRedirect(route('workspace.login'));
    $response->assertSessionHasErrors('email');
    expect(session('errors')->first('email'))->toContain('could not verify');

    // The destination must actually SERVE on this plane — a redirect to a 404 is the bug.
    $this->get(route('workspace.login'))->assertOk();

    expect(session(PlatformAuth::SESSION_KEY))->toBeNull();
});

it('sends an account-plane OIDC failure to the workspace sign-in, not a 404', function (): void {
    ssoRootEnvironment();
    $fixture = ssoConnection();

    // An unknown connection id is the earliest error branch, and it needs no OIDC
    // handshake to reach — the plane fork is what is under test, not the protocol.
    $response = $this->get('/sso/oidc/con_does_not_exist/callback?state=x&code=y');

    $response->assertRedirect(route('workspace.login'));
    expect(session('errors')->first('email'))->toContain('no longer active');
    expect(session(PlatformAuth::SESSION_KEY))->toBeNull();
});

it('keeps the subject plane on /login when SSO fails there', function (): void {
    // No base_domains → single-host / tenant shape → the subject door is the right one.
    config(['cbox-id.environments.base_domains' => []]);

    $response = $this->get('/sso/oidc/con_does_not_exist/callback?state=x&code=y');

    $response->assertRedirect(route('login'));
    expect(session('errors')->first('email'))->toContain('no longer active');
});
