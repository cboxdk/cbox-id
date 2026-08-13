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
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

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
    // The SaaS shape, stated: the root and the tenants are different hosts, so `/login`
    // has to be asked for on the one under test rather than on whichever the default is.
    // FederatedLanding used to FORK on that distinction and does not any more — one
    // console, one landing — so what this shape still buys is the plane bulkhead, which
    // is what turned the failure branch into a 404 in the first place.
    multiTenantDeployment();

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
it('sends a root-host SSO failure to a sign-in that actually serves, not a 404', function (): void {
    ssoRootEnvironment();
    $fixture = ssoConnection();

    app()->bind(AssertionValidator::class, fn () => new class implements AssertionValidator
    {
        public function validate(Connection $connection, string $rawResponse): FederatedPrincipal
        {
            throw InvalidAssertion::make('clock skew');
        }
    });

    // On the platform root's own host. This used to be the ACCOUNT plane and had a
    // landing of its own; the root is a tenant like any other, so there is one landing —
    // and the property worth guarding is unchanged and is the whole reason this file
    // exists: the destination must actually SERVE, because a redirect to a 404 arrives
    // AFTER a successful authentication at the IdP.
    $response = $this->post('https://cboxid.com/sso/saml/'.$fixture->connection->id.'/acs', ['SAMLResponse' => 'forged']);

    $response->assertRedirect(route('login'));
    $response->assertSessionHasErrors('email');
    expect(session('errors')->first('email'))->toContain('could not verify');

    $this->get('https://cboxid.com/login')->assertOk();

    expect(session(PlatformAuth::SESSION_KEY))->toBeNull();
});

it('sends a root-host OIDC failure to a sign-in that actually serves, not a 404', function (): void {
    ssoRootEnvironment();
    $fixture = ssoConnection();

    // An unknown connection id is the earliest error branch, and it needs no OIDC
    // handshake to reach — the plane fork is what is under test, not the protocol.
    $response = $this->get('https://cboxid.com/sso/oidc/con_does_not_exist/callback?state=x&code=y');

    $response->assertRedirect(route('login'));
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

/**
 * THE CALLBACK MUST ACCEPT A CROSS-SITE POST, AND THIS APP'S ROUTE SHADOWS THE PACKAGE'S.
 *
 * `response_mode=form_post` means the provider hands the browser an auto-submitting form
 * aimed at the redirect URI — which Apple does by itself the moment a scope beyond
 * `openid` is requested. The framework was taught to accept POST there and it made no
 * difference to this product: `routes/web.php` re-registers the callback deliberately, to
 * turn a session into a cookie, and it registered GET only. Apple's answer was 405.
 *
 * Route-level assertions, because the request-level ones cannot see either half: Laravel's
 * test helpers bypass CSRF entirely, so a POST through them passes whether the exemption
 * works or not.
 */
it('serves the OIDC callback on POST as well as GET, from this app controller', function (): void {
    $route = collect(Route::getRoutes()->getRoutes())
        ->first(fn ($route): bool => $route->uri() === 'sso/oidc/{connection}/callback');

    expect($route)->not->toBeNull()
        ->and($route->methods())->toContain('POST')
        // THIS app's controller on both verbs. The package registers the same URI, and a
        // split — GET here, POST in the package — would sign the person in on one binding
        // and hand them raw JSON on the other.
        ->and($route->getActionName())->toContain('App\Http\Controllers\Sso\OidcCallbackController');
});

it('exempts the OIDC callback from CSRF, which a provider cannot carry', function (): void {
    // Read from the static the framework fills at bootstrap, the same way
    // FrontendCsrfTest does — a request-level assertion would pass either way, because
    // Laravel's test helpers disable CSRF verification outright.
    $property = new ReflectionProperty(ValidateCsrfToken::class, 'neverVerify');
    $property->setAccessible(true);

    expect($property->getValue())->toContain('sso/oidc/*/callback');
});

/**
 * And the state it checks comes from the stash, not the session — because a cross-site
 * POST does not carry a `SameSite=Lax` session cookie, so a controller reading the session
 * finds nothing on exactly the callbacks that need it and says the link expired.
 */
it('reads the flow state from the stash the redirect leg wrote', function (): void {
    $source = file_get_contents(base_path('app/Http/Controllers/Sso/OidcCallbackController.php'));

    expect($source)->toContain('FederationFlowStash')
        ->and($source)->not->toContain("session()->pull('oidc.");
});
