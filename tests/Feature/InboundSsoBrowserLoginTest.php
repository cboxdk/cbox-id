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
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
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
    $response->assertSessionHasErrors('identifier');

    // The message must not leak WHY the assertion failed — that is a forgery oracle.
    $error = session('errors')->first('identifier');
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
    app(Memberships::class)->add($fixture->org->id, $existing->id, 'member');

    app()->bind(AssertionValidator::class, fn () => new class implements AssertionValidator
    {
        public function validate(Connection $connection, string $rawResponse): FederatedPrincipal
        {
            throw new AccountExistsForEmail('collide@acme.example');
        }
    });

    $response = $this->post('/sso/saml/'.$fixture->connection->id.'/acs', ['SAMLResponse' => 'x']);

    $response->assertRedirect(route('login'));
    expect(session('errors')->first('identifier'))->toContain('already exists');
});
