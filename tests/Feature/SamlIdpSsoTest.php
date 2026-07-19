<?php

declare(strict_types=1);

use App\Platform\PlatformAuth;
use App\Platform\SamlRequestContext;
use App\Platform\SamlSsoHandoff;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
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
    app(Memberships::class)->add($org->id, $subject->id, 'owner');

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
