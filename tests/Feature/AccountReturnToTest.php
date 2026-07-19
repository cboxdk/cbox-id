<?php

declare(strict_types=1);

use App\Platform\CurrentUser;
use App\Platform\PlatformAuth;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\ValueObjects\NewClient;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function signInUserHttp(): string
{
    $subject = app(Subjects::class)->create('member@acme.test', 'Member', 'supersecret123');
    $org = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-ret'));
    app(Memberships::class)->add($org->id, $subject->id, 'member');
    $session = app(SessionManager::class)->start($subject->id, $org->id, ['pwd']);
    session([PlatformAuth::SESSION_KEY => $session->id]);
    app(CurrentUser::class)->set($subject, $session, $org, 'member');

    return $org->id;
}

it('offers a return link for a return_to that matches a registered redirect host', function (): void {
    $orgId = signInUserHttp();

    // app.acme.com is a real OAuth redirect host registered in this environment — a
    // return here is legitimate, so the link renders.
    app(ClientRegistry::class)->register(
        new NewClient('Acme App', redirectUris: ['https://app.acme.com/callback'], organizationId: $orgId)
    );

    $this->get('/account?return_to='.urlencode('https://app.acme.com/dashboard'))
        ->assertOk()
        ->assertSee('Return to app.acme.com');
});

it('refuses a well-formed https return_to to an UNREGISTERED host (open-redirect fix)', function (): void {
    signInUserHttp();

    // A perfectly valid https URL — but no client in this environment redirects to
    // evil.example.com, so it must NOT be offered as a "return" link (the phishing
    // pivot the host allowlist closes).
    $this->get('/account?return_to='.urlencode('https://evil.example.com/phish'))
        ->assertOk()
        ->assertDontSee('Return to', false);
});

it('never renders a return link for a malformed/unsafe return_to', function (string $unsafe): void {
    signInUserHttp();

    $this->get('/account?return_to='.urlencode($unsafe))
        ->assertOk()
        ->assertDontSee('Return to', false);
})->with([
    'javascript:alert(1)',
    'data:text/html,evil',
    'http://evil.example.com/phish', // http on a non-local host is refused
    'not-a-url',
]);
