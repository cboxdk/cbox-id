<?php

declare(strict_types=1);

use App\Platform\PlatformAuth;
use App\Platform\SamlRequestContext;
use App\Platform\SamlSsoHandoff;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Cbox\Id\SamlIdp\Testing\InteractsWithSamlIdp;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;

uses(InteractsWithSamlIdp::class);

/**
 * Create a subject with a live platform session, and return the session id to seed
 * into the browser cookie (the SSO controller resolves the subject from it, exactly
 * as the Authenticate middleware does — without the redirect).
 */
function samlSubjectSession(string $email = 'sso.user@sp-test.example'): string
{
    $subject = app(Subjects::class)->create($email, 'SSO User', 'supersecret123');
    $org = app(Organizations::class)->create(new NewOrganization('Acme', 'saml-'.substr(md5($email), 0, 8)));
    app(Memberships::class)->add($org->id, $subject->id, MembershipRole::Owner);

    return app(SessionManager::class)->start($subject->id, $org->id, ['pwd'])->id;
}

it('issues a signed assertion auto-POSTed to the registered ACS for a signed-in subject', function () {
    $sp = $this->registerSamlServiceProvider();
    $sessionId = samlSubjectSession();

    $response = $this->withSession([PlatformAuth::SESSION_KEY => $sessionId])
        ->get('/sso/saml/idp/sso?'.http_build_query([
            'SAMLRequest' => $this->makeRedirectAuthnRequest($sp->entity_id),
            'RelayState' => 'opaque-sp-state',
        ]));

    $response->assertOk();
    // The framework already proves the signature; here we assert the delivery shape:
    // a self-submitting POST form carrying a SAMLResponse to the SP's registered ACS.
    $response->assertSee('method="post"', false);
    $response->assertSee('action="'.$sp->acs_url.'"', false);
    $response->assertSee('name="SAMLResponse"', false);
    $response->assertSee('opaque-sp-state', false); // RelayState echoed untouched
});

it('hands an unauthenticated request to login and resumes after sign-in', function () {
    $sp = $this->registerSamlServiceProvider();

    // No subject → the validated request is stashed and the browser is bounced to
    // the host login (RelayState preserved in the stash).
    $bounced = $this->get('/sso/saml/idp/sso?'.http_build_query([
        'SAMLRequest' => $this->makeRedirectAuthnRequest($sp->entity_id),
        'RelayState' => 'resume-state',
    ]));

    $bounced->assertRedirect(route('login'));
    $bounced->assertSessionHas('cbox.saml_idp_pending');

    // Sign in and resume: the resume hit carries no SAMLRequest — the stash drives it.
    $stash = session('cbox.saml_idp_pending');
    $sessionId = samlSubjectSession();

    $resumed = $this->withSession([
        'cbox.saml_idp_pending' => $stash,
        PlatformAuth::SESSION_KEY => $sessionId,
    ])->get('/sso/saml/idp/sso');

    $resumed->assertOk();
    $resumed->assertSee('name="SAMLResponse"', false);
    $resumed->assertSee('action="'.$sp->acs_url.'"', false);
    $resumed->assertSee('resume-state', false);
});

it('resolves the resume URL back to the SSO endpoint once a request is pending', function () {
    $handoff = app(SamlSsoHandoff::class);

    expect($handoff->resumeUrl())->toBeNull();

    $handoff->stash(new SamlRequestContext(
        samlRequest: 'x', relayState: null, signature: null, sigAlg: null, fromRedirect: true,
    ));

    expect($handoff->resumeUrl())->toBe(route('sso.saml.idp.sso'));
});

it('refuses an AuthnRequest from an unregistered service provider', function () {
    $sessionId = samlSubjectSession();

    $this->withSession([PlatformAuth::SESSION_KEY => $sessionId])
        ->get('/sso/saml/idp/sso?'.http_build_query([
            'SAMLRequest' => $this->makeRedirectAuthnRequest('https://unregistered.example/metadata'),
        ]))
        ->assertForbidden();
});

it('returns 400 when no SAMLRequest is present', function () {
    $this->get('/sso/saml/idp/sso')->assertStatus(400);
});

it('exempts the SSO POST binding from CSRF verification', function () {
    // A cross-site SP POST carries no Laravel CSRF token; the endpoint must be
    // exempt or the HTTP-POST binding is rejected (419) before it reaches the IdP.
    expect(app(ValidateCsrfToken::class)->getExcludedPaths())->toContain('sso/saml/idp/sso');
});

it('accepts a tokenless cross-site POST (HTTP-POST binding) and hands off to login', function () {
    $sp = $this->registerSamlServiceProvider();

    // POST binding: base64 only (no DEFLATE), delivered as a form POST with no token.
    $this->post('/sso/saml/idp/sso', [
        'SAMLRequest' => base64_encode($this->authnRequestXml($sp->entity_id)),
        'RelayState' => 'post-binding-state',
    ])->assertRedirect(route('login'));
});

/**
 * A refusal the SP can be told about must go back to its ACS in SAML, not die as a bare
 * 400 on our domain.
 *
 * This app OVERRIDES the package's SSO controller, and the override caught
 * InvalidAuthnRequest without reading its samlError() — so the package's entire error
 * path was dead in production, and the package test proving it works passes against a
 * controller nothing serves.
 *
 * The cost is not cosmetic. A Destination mismatch after a custom-domain migration is the
 * highest-volume real refusal there is: the user sat on our domain reading an unbranded
 * sentence, and the SP's admin had nothing in their logs to correlate it with.
 */
it('returns a signed SAML error Response to the ACS for a refusal the SP can act on', function () {
    $sp = $this->registerSamlServiceProvider(acsUrl: 'https://sp.example.test/acs');
    $sessionId = samlSubjectSession();

    $response = $this->withSession([PlatformAuth::SESSION_KEY => $sessionId])
        ->get('/sso/saml/idp/sso?'.http_build_query([
            // Addresses somewhere that is not this SingleSignOnService endpoint.
            'SAMLRequest' => $this->makeRedirectAuthnRequest($sp->entity_id, destination: 'https://elsewhere.example/sso'),
        ]));

    $response->assertOk();
    $html = $response->getContent() ?: '';

    // An auto-POST form aimed at the SP's OWN ACS, carrying a SAMLResponse.
    expect($html)->toContain('https://sp.example.test/acs')
        ->and($html)->toContain('SAMLResponse')
        ->and($html)->not->toContain('SAML AuthnRequest rejected');

    preg_match('/name="SAMLResponse" value="([^"]+)"/', $html, $m);
    $xml = base64_decode(html_entity_decode($m[1] ?? ''), true) ?: '';

    expect($xml)->toContain('samlp:Response')
        ->and($xml)->toContain('urn:oasis:names:tc:SAML:2.0:status:Requester');
});

/**
 * The stash has to carry the raw query string across the sign-in detour.
 *
 * A redirect-binding signature is computed over the URL-encoded query as the SP built
 * it, not over the decoded parameters (SAML bindings §3.4.4.1) — and encoders disagree
 * about which characters need escaping. Entra and Ping follow RFC 3986, where a space
 * is `%20` and `~` is literal; PHP's `urlencode()` produces `+` and `%7E`. Re-encoding
 * a parsed RelayState therefore yields bytes the SP never signed.
 *
 * The first leg survives without the stash because the live request still has its own
 * query string to fall back on. The resume leg has nothing — it is a fresh GET, with
 * only the session to go on. So a stash that writes the value and reads it back as
 * null fails on exactly one of the two legs, and it is the leg the property was added
 * for. This asserts the round trip, which is where the two halves have to agree.
 */
it('carries the raw query string across the stash, not just into it', function (): void {
    $raw = 'SAMLRequest=abc%20def&RelayState=state%20with~tilde&SigAlg=x&Signature=y';

    $context = new SamlRequestContext(
        samlRequest: 'abc def',
        relayState: 'state with~tilde',
        signature: 'y',
        sigAlg: 'x',
        fromRedirect: true,
        rawQueryString: $raw,
    );

    $restored = SamlRequestContext::fromSession($context->toSession());

    expect($restored)->not->toBeNull()
        ->and($restored->rawQueryString)
        ->toBe($raw, 'the resume leg lost the bytes the SP actually signed');
});

/**
 * THE ASSERTION MUST DESCRIBE HOW THEY ACTUALLY SIGNED IN.
 *
 * `AuthnContextClassRef` was hardcoded to `…:ac:classes:Password`, so a passkey sign-in
 * produced a signed statement that a password had been typed. Relying parties act on that
 * field: an SP configured to require a password was satisfied by a sign-in that had none.
 *
 * The mapping itself is the framework's and is tested there. What this covers is the
 * WIRING — that this controller reads the session's `amr` and passes it — which is the
 * part that can silently not work while every other test stays green.
 */
function assertionContextFor(array $amr): string
{
    $subject = app(Subjects::class)->create('ctx-'.mt_rand().'@acme.test', 'Ctx', 'supersecret123');
    $org = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-ctx-'.mt_rand()));
    app(Memberships::class)->add($org->id, $subject->id, MembershipRole::Owner);

    $sessionId = app(SessionManager::class)->start($subject->id, $org->id, $amr)->id;
    $sp = test()->registerSamlServiceProvider();

    $html = (string) test()->withSession([PlatformAuth::SESSION_KEY => $sessionId])
        ->get('/sso/saml/idp/sso?'.http_build_query([
            'SAMLRequest' => test()->makeRedirectAuthnRequest($sp->entity_id),
        ]))->getContent();

    preg_match('/name="SAMLResponse" value="([^"]+)"/', $html, $m);

    return base64_decode($m[1] ?? '', true) ?: '';
}

it('never says a password was used when the person used a passkey', function (): void {
    $xml = assertionContextFor(['webauthn']);

    expect($xml)->not->toContain('ac:classes:Password')
        ->and($xml)->toContain('ac:classes:unspecified');
});

/**
 * And the mirror: a password sign-in says password-protected-transport, not bare
 * `Password` — which means a password with NO transport protection, and understated what
 * actually happened.
 */
it('says password-protected-transport for a password sign-in', function (): void {
    expect(assertionContextFor(['pwd']))->toContain('ac:classes:PasswordProtectedTransport');
});
