<?php

declare(strict_types=1);

use App\Platform\PlatformAuth;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Cbox\Id\SamlIdp\Contracts\SamlIdentityProvider;
use Cbox\Id\SamlIdp\Enums\SamlStatusCode;
use Cbox\Id\SamlIdp\Exceptions\EmailNotVerified;
use Cbox\Id\SamlIdp\Exceptions\InvalidAuthnRequest;
use Cbox\Id\SamlIdp\Models\SamlIdpSession;
use Cbox\Id\SamlIdp\Testing\InteractsWithSamlIdp;
use Cbox\Id\SamlIdp\ValueObjects\AuthnRequest;
use Cbox\Id\SamlIdp\ValueObjects\SamlError;
use Cbox\Id\SamlIdp\ValueObjects\SamlResponse;
use Illuminate\Testing\TestResponse;

uses(InteractsWithSamlIdp::class);

/**
 * WHAT ISSUANCE REFUSES REACHES THE PERSON OR THE SP — NEVER A 500.
 *
 * The SSO endpoint checks what it can before it asks for an assertion, but
 * `issueResponse()` refuses on its own as well: a request that was already answered, a
 * person who is not a member of the organization that owns the SP, an SP disabled since
 * parsing, and (since laravel-id 2.8) an unverified email the SP would receive. Each of
 * those is an answer, not a server error.
 */
function issuanceSession(?string $organizationId = null): string
{
    $subjects = app(Subjects::class);
    $subject = $subjects->create('issuance@sp-test.example', 'Issuance Person', 'supersecret123');
    $subjects->markEmailVerified($subject->id, 'issuance@sp-test.example');

    $org = $organizationId ?? app(Organizations::class)->create(new NewOrganization('Acme', 'issuance-acme'))->id;
    app(Memberships::class)->add($org, $subject->id, MembershipRole::Owner);

    return app(SessionManager::class)->start($subject->id, $org, ['pwd'])->id;
}

function issuanceSso(string $sessionId, string $samlRequest): TestResponse
{
    return test()->withSession([PlatformAuth::SESSION_KEY => $sessionId])
        ->get('/sso/saml/idp/sso?'.http_build_query(['SAMLRequest' => $samlRequest]));
}

/**
 * The real IdP for everything but issuance, which throws what the test hands it.
 */
function issuanceThrows(Throwable $refusal): void
{
    $real = app(SamlIdentityProvider::class);

    app()->instance(SamlIdentityProvider::class, new class($real, $refusal) implements SamlIdentityProvider
    {
        public function __construct(private readonly SamlIdentityProvider $real, private readonly Throwable $refusal) {}

        public function metadata(): string
        {
            return $this->real->metadata();
        }

        public function parseAuthnRequest(string $samlRequest, ?string $relayState = null, ?string $signature = null, ?string $sigAlg = null, bool $fromRedirectBinding = true, ?string $rawQueryString = null): AuthnRequest
        {
            return $this->real->parseAuthnRequest($samlRequest, $relayState, $signature, $sigAlg, $fromRedirectBinding, $rawQueryString);
        }

        public function issueResponse(AuthnRequest $request, string $subjectId, array $attributes = [], array $amr = []): SamlResponse
        {
            throw $this->refusal;
        }

        public function issueErrorResponse(SamlError $error): SamlResponse
        {
            return $this->real->issueErrorResponse($error);
        }
    });
}

it('answers a replayed AuthnRequest instead of failing', function (): void {
    $sp = $this->registerSamlServiceProvider();
    $sessionId = issuanceSession();
    $samlRequest = $this->makeRedirectAuthnRequest($sp->entity_id);

    issuanceSso($sessionId, $samlRequest)->assertOk()->assertSee('name="SAMLResponse"', false);

    issuanceSso($sessionId, $samlRequest)
        ->assertStatus(400)
        ->assertDontSee('name="SAMLResponse"', false);

    expect(SamlIdpSession::query()->count())->toBe(1);
});

it('refuses a person who is not a member of the organization that owns the SP', function (): void {
    $owner = app(Organizations::class)->create(new NewOrganization('Globex', 'issuance-globex'));
    $sp = $this->registerSamlServiceProvider(organizationId: $owner->id);

    $response = issuanceSso(issuanceSession(), $this->makeRedirectAuthnRequest($sp->entity_id));

    $response->assertForbidden();
    $response->assertDontSee('name="SAMLResponse"', false);
    expect(SamlIdpSession::query()->count())->toBe(0);
});

it('still signs in a member of the organization that owns the SP', function (): void {
    $owner = app(Organizations::class)->create(new NewOrganization('Globex', 'issuance-globex'));
    $sp = $this->registerSamlServiceProvider(organizationId: $owner->id);

    issuanceSso(issuanceSession($owner->id), $this->makeRedirectAuthnRequest($sp->entity_id))
        ->assertOk()
        ->assertSee('name="SAMLResponse"', false);
});

it('tells the person to confirm their address when issuance itself refuses an unverified email', function (): void {
    $sp = $this->registerSamlServiceProvider();
    $sessionId = issuanceSession();

    issuanceThrows(EmailNotVerified::forServiceProvider(new SamlError(
        spEntityId: $sp->entity_id,
        acsUrl: $sp->acs_url,
        status: SamlStatusCode::Responder,
        subStatus: SamlStatusCode::RequestDenied,
    )));

    $response = issuanceSso($sessionId, $this->makeRedirectAuthnRequest($sp->entity_id));

    $response->assertForbidden();
    $response->assertSee('Confirm your email address before signing in to this service.');
    $response->assertDontSee('name="SAMLResponse"', false);
});

it('answers the SP with a signed error Response for a reportable refusal at issuance', function (): void {
    $sp = $this->registerSamlServiceProvider(acsUrl: 'https://sp.example.test/acs');
    $sessionId = issuanceSession();

    issuanceThrows(InvalidAuthnRequest::reportable('refused at issuance', new SamlError(
        spEntityId: $sp->entity_id,
        acsUrl: $sp->acs_url,
        status: SamlStatusCode::Responder,
        subStatus: SamlStatusCode::RequestDenied,
        inResponseTo: '_testreq0000000000000000000000000000',
    )));

    $response = issuanceSso($sessionId, $this->makeRedirectAuthnRequest($sp->entity_id));

    $response->assertOk();
    $html = $response->getContent() ?: '';
    preg_match('/name="SAMLResponse" value="([^"]+)"/', $html, $m);
    $xml = base64_decode(html_entity_decode($m[1] ?? ''), true) ?: '';

    expect($html)->toContain('https://sp.example.test/acs')
        ->and($xml)->toContain('urn:oasis:names:tc:SAML:2.0:status:Responder')
        ->and($xml)->toContain('urn:oasis:names:tc:SAML:2.0:status:RequestDenied');
});
