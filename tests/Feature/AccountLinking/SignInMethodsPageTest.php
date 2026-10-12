<?php

declare(strict_types=1);

use App\Platform\Sudo;
use Cbox\Id\Federation\Contracts\Connections;
use Cbox\Id\Federation\Enums\ConnectionType;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Identity\Models\IdentityLink;
use Cbox\Id\Identity\Models\WebAuthnCredential;
use Cbox\Id\Identity\ValueObjects\FederatedPrincipal;
use Cbox\Id\Kernel\Audit\Models\AuditEntry;
use Cbox\Id\Platform\PlatformRoot;
use Illuminate\Testing\TestResponse;
use Tests\Support\RootGoogle;

/*
|--------------------------------------------------------------------------
| MY ACCOUNT › SIGN-IN METHODS on cboxid.com/account
|--------------------------------------------------------------------------
|
| "Ingen liste over social links under min account": the page listed only the deployment
| operator's own providers, so with the platform's Google set up in its console it showed
| nothing at all. These hold the section the owner asked for: every linked identity, what
| can still be connected, Connect through the environment's own Google (one redirect URI),
| and Disconnect — refused when it is the last way in.
*/

const ACCOUNT_HOST = RootGoogle::HOST;

beforeEach(function (): void {
    RootGoogle::deployment();

    $this->account = provisionAccount('owner@acme.example');
    $this->ownerId = (string) $this->account['subjectId'];

    RootGoogle::operatorSignedIn();
    $this->google = RootGoogle::turnOn();
    RootGoogle::signedOutBrowser();
});

function signInMethods(): array
{
    $page = test()->get('https://'.ACCOUNT_HOST.'/account')->assertOk();

    expect($page->inertiaPage()['component'])->toBe('account/security');

    return (array) $page->inertiaProps('signInMethods');
}

function linkedOn(string $subjectId): array
{
    return app(PlatformRoot::class)->run(static fn (): array => IdentityLink::query()->where('user_id', $subjectId)->get()->all());
}

it('lists the environment\'s Google as connectable, and connects it through Google and back', function (): void {
    signInAsSubject($this->ownerId);

    $methods = signInMethods();
    expect($methods['identities'])->toBe([])
        ->and($methods['connectable'])->toHaveCount(1)
        ->and($methods['connectable'][0]['provider'])->toBe('google')
        ->and($methods['connectable'][0]['label'])->toBe($this->google->name);

    // Behind a fresh step-up, like every new way in on this page.
    $this->get($methods['connectable'][0]['connectHref'])->assertRedirect();
    expect(linkedOn($this->ownerId))->toBe([]);

    app(Sudo::class)->confirm();

    // A different Google address than the account's: the person proved both sides.
    $back = RootGoogle::through($methods['connectable'][0]['connectHref'], 'owner.private@gmail.com');

    $back->assertRedirect(route('account'))->assertSessionHas('status');

    $links = linkedOn($this->ownerId);
    expect($links)->toHaveCount(1)
        ->and($links[0]->subject)->toBe(RootGoogle::subjectFor('owner.private@gmail.com'))
        ->and($links[0]->connection_id)->toBe($this->google->id);

    $entry = app(PlatformRoot::class)->run(static fn () => AuditEntry::query()->where('action', 'user.identity_linked')->sole());
    expect($entry->context['through'] ?? null)->toBe('account')
        ->and($entry->context['proof'] ?? null)->toBe('signed_in');

    // Listed now — what the provider called the account, when — and no longer offered.
    $methods = signInMethods();
    expect($methods['identities'])->toHaveCount(1)
        ->and($methods['identities'][0])->toMatchArray([
            'provider' => 'google',
            'label' => $this->google->name,
            'email' => 'owner.private@gmail.com',
            'removable' => true,
        ])
        ->and($methods['identities'][0]['linkedOn'])->toBeString()
        ->and($methods['connectable'])->toBe([]);
});

it('refuses to finish a connect for somebody who is no longer the one signed in', function (): void {
    signInAsSubject($this->ownerId);
    app(Sudo::class)->confirm();

    $connect = signInMethods()['connectable'][0]['connectHref'];
    $start = $this->get($connect);

    // Signed out while at Google.
    RootGoogle::signOut();

    $back = RootGoogle::through((string) $start->headers->get('Location'), 'owner.private@gmail.com');

    expect(linkedOn($this->ownerId))->toBe([])
        ->and((string) parse_url((string) $back->headers->get('Location'), PHP_URL_PATH))->not->toBe('/dashboard');
})->group('security');

it('will not connect a provider this person is not offered', function (): void {
    signInAsSubject($this->ownerId);
    app(Sudo::class)->confirm();

    // An organization's own enterprise connection, which is nobody's "Connect" button.
    $enterprise = app(PlatformRoot::class)->run(function () {
        $connection = app(Connections::class)->create($this->account['organization']->id, ConnectionType::Oidc, 'Acme Okta', [
            'issuer' => 'https://acme.okta.test', 'client_id' => 'a', 'client_secret' => 'b', 'signing_key' => 'k',
        ]);
        app(Connections::class)->activate($this->account['organization']->id, $connection->id);

        return $connection;
    });

    $this->get('https://'.ACCOUNT_HOST.'/account/sign-in-methods/'.$enterprise->id.'/connect')->assertNotFound();
})->group('security');

it('disconnects a linked identity, and says so when it is the last way in', function (): void {
    // Linked, and the account also has its password: it may go.
    app(PlatformRoot::class)->run(fn () => app(Subjects::class)->link($this->ownerId, new FederatedPrincipal('oidc', 'google|x', 'owner@acme.example', connectionId: $this->google->id, raw: ['email' => 'owner@acme.example'])));

    signInAsSubject($this->ownerId);
    app(Sudo::class)->confirm();

    $identity = signInMethods()['identities'][0];

    $this->from('https://'.ACCOUNT_HOST.'/account')->delete($identity['disconnectHref'])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status');

    expect(linkedOn($this->ownerId))->toBe([]);

    // A Google-only account: no password, no passkey. Refused, with the sentence.
    $googleOnly = app(PlatformRoot::class)->run(function () {
        $subject = app(Subjects::class)->create('google.only@acme.example', 'Google Only');
        app(Subjects::class)->link($subject->id, new FederatedPrincipal('oidc', 'google|only', 'google.only@acme.example', connectionId: $this->google->id));

        return $subject;
    });

    signInAsSubject($googleOnly->id);
    app(Sudo::class)->confirm();

    $only = signInMethods()['identities'][0];

    /** @var TestResponse $refused */
    $refused = $this->from('https://'.ACCOUNT_HOST.'/account')->delete($only['disconnectHref']);
    $refused->assertSessionHasErrors('signInMethods');

    expect(session('errors')->first('signInMethods'))->toContain('only way to sign in')
        ->and(linkedOn($googleOnly->id))->toHaveCount(1);

    // A passkey is a way in: now it may go.
    app(PlatformRoot::class)->run(fn () => WebAuthnCredential::query()->create([
        'user_id' => $googleOnly->id, 'credential_id' => 'cred', 'public_key' => 'pk', 'name' => 'Phone', 'sign_count' => 0,
    ]));

    $this->from('https://'.ACCOUNT_HOST.'/account')->delete($only['disconnectHref'])->assertSessionHasNoErrors();
    expect(linkedOn($googleOnly->id))->toBe([]);
});

it('shows an organization\'s single sign-on, and does not offer to disconnect it', function (): void {
    $enterprise = app(PlatformRoot::class)->run(function () {
        $connection = app(Connections::class)->create($this->account['organization']->id, ConnectionType::Saml, 'Acme Okta', []);
        app(Subjects::class)->link($this->ownerId, new FederatedPrincipal('saml', 'okta|owner', 'owner@acme.example', connectionId: $connection->id));

        return $connection;
    });

    signInAsSubject($this->ownerId);
    app(Sudo::class)->confirm();

    $sso = collect(signInMethods()['identities'])->firstWhere('label', $enterprise->name);

    expect($sso['provider'])->toBe('sso')
        ->and($sso['removable'])->toBeFalse()
        ->and($sso['disconnectHref'])->toBeNull();
});

it('says what else the account signs in with: its password and its passkeys', function (): void {
    signInAsSubject($this->ownerId);

    $props = (array) $this->get('https://'.ACCOUNT_HOST.'/account')->assertOk()->inertiaProps();

    expect($props['hasPassword'])->toBeTrue()
        ->and($props['passkeys'])->toBe([]);
});
