<?php

declare(strict_types=1);

use App\Platform\CurrentUser;
use App\Platform\PlatformAuth;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function signInUserHttp(): void
{
    $subject = app(Subjects::class)->create('member@acme.test', 'Member', 'supersecret123');
    $org = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-ret'));
    app(Memberships::class)->add($org->id, $subject->id, 'member');
    $session = app(SessionManager::class)->start($subject->id, $org->id, ['pwd']);
    session([PlatformAuth::SESSION_KEY => $session->id]);
    app(CurrentUser::class)->set($subject, $session, $org, 'member');
}

it('offers a return link for a safe https return_to (client SDK profile redirect)', function (): void {
    signInUserHttp();

    $this->get('/settings?return_to='.urlencode('https://app.acme.com/dashboard'))
        ->assertOk()
        ->assertSee('Return to app.acme.com');
});

it('never renders a return link for an unsafe return_to (no open redirect)', function (string $unsafe): void {
    signInUserHttp();

    $this->get('/settings?return_to='.urlencode($unsafe))
        ->assertOk()
        ->assertDontSee('Return to', false);
})->with([
    'javascript:alert(1)',
    'data:text/html,evil',
    'http://evil.example.com/phish', // http on a non-local host is refused
    'not-a-url',
]);
