<?php

declare(strict_types=1);

use App\Platform\AppCatalog\CatalogEntry;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\Contracts\RefreshTokens;
use Cbox\Id\OAuthServer\Enums\ClientType;
use Cbox\Id\OAuthServer\Models\Client;
use Cbox\Id\OAuthServer\ValueObjects\NewClient;
use Cbox\Id\Organization\Contracts\Invitations;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * A PERSON'S OWN PAGES: their apps, their preferences, an invitation waiting for them, and
 * what an app with access may do, in words.
 */
beforeEach(fn () => installedDeployment());

/** A published catalog app, optionally tied to an OAuth client. */
function launcherApp(string $key, string $name, ?string $purpose = null, ?Client $client = null, array $extra = []): void
{
    CatalogEntry::query()->create([
        'key' => $key,
        'name' => $name,
        'launch_url' => "https://{$key}.example.test/login",
        'enabled' => true,
        'position' => CatalogEntry::query()->count(),
        'audience' => 'everyone',
        'organization_ids' => [],
        'user_ids' => [],
        'client_ids' => $client === null ? [] : [$client->client_id],
        'purpose' => $purpose,
        'version' => 1,
        ...$extra,
    ]);
}

function launcherClient(string $name): Client
{
    return app(ClientRegistry::class)->register(new NewClient(
        name: $name,
        type: ClientType::Confidential,
        redirectUris: ['https://'.Str::slug($name).'.example.test/callback'],
    ))->client;
}

it('lists the person\'s apps with what each is for and when they last used it', function (): void {
    [$subjectId] = actingAsRole(MembershipRole::Member);
    $billing = launcherClient('Billing');
    launcherApp('billing', 'Billing', 'Finance', $billing);
    launcherApp('docs', 'Team docs', 'Collaboration');
    launcherApp('beta', 'Beta', null, null, ['audience' => 'users', 'user_ids' => ['someone-else'], 'admission_url' => 'https://beta.example.test/join', 'promote_when_unavailable' => true]);
    launcherApp('labs', 'Labs', null, null, ['audience' => 'organizations', 'organization_ids' => ['org-nobody'], 'admission_url' => 'https://labs.example.test/join', 'promote_when_unavailable' => true]);

    app(RefreshTokens::class)->issue($billing, $subjectId, null, ['openid', 'email']);

    $this->get('/settings/apps')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('account/apps')
            ->where('title', 'Your apps')
            ->has('apps', 2)
            ->where('apps.0.name', 'Billing')
            ->where('apps.0.purpose', 'Finance')
            ->where('apps.0.lastUsedIso', fn (?string $iso): bool => $iso !== null)
            ->where('apps.1.name', 'Team docs')
            ->where('apps.1.lastUsedIso', null)
            // An app for named people the person is not among is never named to them; one
            // they could ask for is listed with where to ask.
            ->has('requestable', 1)
            ->where('requestable.0.name', 'Labs')
            // Asked from the organization the person is in.
            ->where('requestable.0.url', fn (string $url): bool => str_starts_with($url, 'https://labs.example.test/join')));
});

it('puts the apps page first in the person\'s pages where the environment publishes apps', function (): void {
    actingAsRole(MembershipRole::Member);
    launcherApp('docs', 'Team docs');

    $this->get('/settings')
        ->assertInertia(fn (Assert $page) => $page
            ->where('shell.nav.areas', fn ($areas): bool => collect($areas)
                ->firstWhere('key', 'account')['pages'][0]['key'] === 'account.apps'));
});

it('leaves the apps page out of the rail where nothing is published', function (): void {
    actingAsRole(MembershipRole::Member);

    $this->get('/settings')
        ->assertInertia(fn (Assert $page) => $page
            ->where('shell.nav.areas', fn ($areas): bool => ! collect(collect($areas)->firstWhere('key', 'account')['pages'])
                ->contains('key', 'account.apps')));
});

it('shows the four preferences as they are set in this browser', function (): void {
    actingAsRole(MembershipRole::Member);

    $this->withUnencryptedCookies(['cbox-format' => 'da-DK', 'cbox-time-zone' => 'Europe/Copenhagen', 'cbox-theme' => 'dark'])
        ->get('/settings/preferences')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('account/preferences')
            ->where('current.regionalFormat', 'da-DK')
            ->where('current.timeZone', 'Europe/Copenhagen'));
});

it('ignores a time zone or format cookie it does not recognise', function (): void {
    actingAsRole(MembershipRole::Member);

    $this->withUnencryptedCookies(['cbox-format' => '<script>', 'cbox-time-zone' => 'Mars/Olympus'])
        ->get('/settings/preferences')
        ->assertInertia(fn (Assert $page) => $page
            ->where('current.regionalFormat', null)
            ->where('current.timeZone', null));
});

it('lists an invitation for the person\'s confirmed address and joins it from their page', function (): void {
    [$subjectId] = actingAsRole(MembershipRole::Member);
    $email = app(Subjects::class)->find($subjectId)?->email;
    $initech = app(Organizations::class)->create(new NewOrganization('Initech', 'initech-launcher'));
    app(Invitations::class)->invite($initech->id, (string) $email, MembershipRole::Member);

    $invitation = null;
    $this->get('/settings')
        ->assertInertia(function (Assert $page) use (&$invitation): void {
            $page->has('invitations', 1)
                ->where('invitations.0.organization', 'Initech')
                ->where('invitations.0.role', 'Member')
                ->where('invitations.0.acceptHref', function (string $href) use (&$invitation): bool {
                    $invitation = $href;

                    return str_contains($href, '/settings/invitations/');
                });
        });

    $this->post((string) $invitation)
        ->assertRedirect()
        ->assertSessionHas('status', 'You joined Initech.');

    expect(app(Memberships::class)->of($initech->id, $subjectId))->not->toBeNull();

    $this->get('/settings')->assertInertia(fn (Assert $page) => $page->has('invitations', 0));
});

it('says what an app with access may do in words, never its scope codes', function (): void {
    [$subjectId] = actingAsRole(MembershipRole::Member);
    app(RefreshTokens::class)->issue(launcherClient('Helpdesk'), $subjectId, null, ['openid', 'email', 'offline_access']);

    $this->get('/settings/activity')
        ->assertInertia(fn (Assert $page) => $page
            ->where('applications.0.name', 'Helpdesk')
            ->where('applications.0.actsOffline', true)
            ->where('applications.0.permissions', ['Verify your identity', 'Your email address'])
            ->missing('applications.0.scopes'));
});
