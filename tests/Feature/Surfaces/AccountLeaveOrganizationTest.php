<?php

declare(strict_types=1);

use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\GenericEnvironment;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Cbox\Id\Platform\PlatformRoot;
use Cbox\Id\UserProperties\Contracts\UserPreferences;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * A PERSON'S OWN PAGES, WHERE THERE IS NO CONSOLE AROUND THEM: leaving an organization,
 * choosing a language, and the old app-picker bookmark. On the login host (login-host
 * mode) and on a customer environment's host, which keeps only the person's own pages.
 */

/** Another organization in the realm (the default environment), with this person in it. */
function aloOrganization(?string $subjectId, string $name, MembershipRole $role = MembershipRole::Member): string
{
    return app(PlatformRoot::class)->run(function () use ($subjectId, $name, $role): string {
        $organization = app(Organizations::class)->create(new NewOrganization($name, str($name)->slug()->toString()));

        if ($subjectId !== null) {
            app(Memberships::class)->add($organization->id, $subjectId, $role);
        }

        return $organization->id;
    });
}

/**
 * A person of the realm in one plain organization (one that owns no products), signed in
 * on the login host.
 *
 * @return array{0: string, 1: string} the person and the organization
 */
function aloMemberOnLoginHost(MembershipRole $role): array
{
    loginHostMode();

    $subjectId = app(PlatformRoot::class)->run(
        fn (): string => app(Subjects::class)->create('person@realm.example', 'Person', 'a-strong-unbreached-passphrase')->id,
    );
    $organizationId = aloOrganization($subjectId, 'Home', $role);

    signInAsMember($subjectId);
    session()->put('cbox.surface', 'login');

    return [$subjectId, $organizationId];
}

/** A person signed in on the login host, in the organization their account came with. */
function aloPersonOnLoginHost(): array
{
    loginHostMode();

    $account = provisionAccount();
    signInAsMember($account['subjectId']);
    session()->put('cbox.surface', 'login');

    return $account;
}

it('lists a person\'s organizations on their account page on the login host, each with its way out', function (): void {
    $account = aloPersonOnLoginHost();
    $side = aloOrganization($account['subjectId'], 'Side project');

    $this->get('https://'.LOGIN_HOST.'/settings')->assertOk()->assertInertia(fn ($page) => $page
        ->component('account/security')
        ->has('organizations', 2)
        ->where('organizations.0.name', 'Acme')
        ->where('organizations.0.isOwner', true)
        ->where('organizations.1.id', $side)
        // The address carries the organization's public id, on the host the page is on.
        ->where('organizations.1.leaveHref', 'https://'.LOGIN_HOST.'/settings/organizations/org_'.$side.'/leave'));
});

it('leaves an organization from the login host and keeps the person signed in on their page', function (): void {
    $account = aloPersonOnLoginHost();
    $side = aloOrganization($account['subjectId'], 'Side project');

    $href = $this->get('https://'.LOGIN_HOST.'/settings')->inertiaProps()['organizations'][1]['leaveHref'];

    $this->from('https://'.LOGIN_HOST.'/settings')
        ->post($href)
        ->assertRedirect('https://'.LOGIN_HOST.'/settings')
        ->assertSessionHas('status', 'You left the organization Side project.');

    expect(app(PlatformRoot::class)->run(fn () => app(Memberships::class)->of($side, $account['subjectId'])))->toBeNull();

    // Still signed in, and the page no longer offers it.
    $this->get('https://'.LOGIN_HOST.'/settings')->assertOk()->assertInertia(fn ($page) => $page->has('organizations', 1));

    // The write is the login host's: the console on the app host does not have it.
    $this->post('https://'.APP_HOST.'/settings/organizations/'.$side.'/leave')->assertNotFound();
});

it('keeps the account when the last organization is left: no sign-out, the page still opens', function (): void {
    [$subjectId, $organizationId] = aloMemberOnLoginHost(MembershipRole::Member);

    $this->from('https://'.LOGIN_HOST.'/settings')
        ->post('https://'.LOGIN_HOST.'/settings/organizations/'.$organizationId.'/leave')
        ->assertRedirect('https://'.LOGIN_HOST.'/settings');

    expect(app(PlatformRoot::class)->run(fn () => app(Memberships::class)->forUser($subjectId)))->toHaveCount(0);

    $this->get('https://'.LOGIN_HOST.'/settings')->assertOk()->assertInertia(fn ($page) => $page
        ->has('organizations', 0)
        ->where('auth.user.id', $subjectId));
});

it('moves the session to another organization when the one it acted in is left', function (): void {
    [$subjectId, $organizationId] = aloMemberOnLoginHost(MembershipRole::Member);
    $other = aloOrganization($subjectId, 'Other');

    $this->from('https://'.LOGIN_HOST.'/settings')
        ->post('https://'.LOGIN_HOST.'/settings/organizations/'.$organizationId.'/leave')
        ->assertRedirect();

    $this->get('https://'.LOGIN_HOST.'/settings')->assertOk()->assertInertia(fn ($page) => $page
        ->has('organizations', 1)
        ->where('organizations.0.id', $other)
        ->where('organizations.0.current', true));
});

it('keeps the action\'s own rules: the last owner stays, and somebody else\'s organization is not found', function (): void {
    [$subjectId, $organizationId] = aloMemberOnLoginHost(MembershipRole::Owner);

    $this->from('https://'.LOGIN_HOST.'/settings')
        ->post('https://'.LOGIN_HOST.'/settings/organizations/'.$organizationId.'/leave')
        ->assertRedirect('https://'.LOGIN_HOST.'/settings')
        ->assertSessionHasErrors('organization');

    expect(app(PlatformRoot::class)->run(fn () => app(Memberships::class)->of($organizationId, $subjectId)))->not->toBeNull();

    $strangers = aloOrganization(null, 'Strangers');

    $this->post('https://'.LOGIN_HOST.'/settings/organizations/'.$strangers.'/leave')->assertNotFound();
})->group('security');

it('offers the same on a customer environment\'s host, which has no People page', function (): void {
    multiTenantDeployment();

    $vendor = provisionAccount('vendor@product.example');
    serveOnTestHost($vendor['environment']);
    app(EnvironmentContext::class)->set(GenericEnvironment::of($vendor['environment']->id));

    [$subjectId, $organization] = actingAsRole(MembershipRole::Member);

    $this->get(route('directory.members'))->assertNotFound();

    $this->get(route('account'))->assertOk()->assertInertia(fn ($page) => $page
        ->has('organizations', 1)
        ->where('organizations.0.id', $organization->id));

    $this->from(route('account'))
        ->post(route('account.organizations.leave', $organization->id))
        ->assertRedirect(route('account'))
        ->assertSessionHas('status');

    expect(app(Memberships::class)->of($organization->id, $subjectId))->toBeNull();
});

it('saves the interface language from the account pages on the login host', function (): void {
    $account = aloPersonOnLoginHost();

    $preference = $this->get('https://'.LOGIN_HOST.'/settings')->assertOk()->inertiaProps()['i18n']['preference'];

    // The picker posts to the host the page is on, and the page says so.
    expect($preference['subjectId'])->toBe($account['subjectId'])
        ->and($preference['updateUrl'])->toBe('https://'.LOGIN_HOST.'/interface-language');

    $this->from('https://'.LOGIN_HOST.'/settings')
        ->post($preference['updateUrl'], ['subject_id' => $preference['subjectId'], 'version' => $preference['version'], 'language' => 'da'])
        ->assertStatus(303);

    expect(app(UserPreferences::class)->read($account['subjectId'])->data->language)->toBe('da');

    $this->get('https://'.LOGIN_HOST.'/settings')->assertOk()->assertHeader('Content-Language', 'da');
});

it('lands the old app-picker bookmark on the console in login-host mode, where the switcher is', function (): void {
    loginHostMode();

    $account = provisionAccount();
    signInAsMember($account['subjectId']);
    session()->put('cbox.surface', 'console');

    $this->get('https://'.APP_HOST.'/switch-app')
        ->assertRedirect('https://'.APP_HOST.'/dashboard?switch-app=1');

    // An app's "switch app" link names the issuer, which is the login host. It has no
    // console, so it sends the person to the console's switcher through the console's own
    // sign-in.
    session()->put('cbox.surface', 'login');
    $this->get('https://'.LOGIN_HOST.'/switch-app')
        ->assertRedirect('https://'.APP_HOST.'/auth/start?return_to='.rawurlencode('/dashboard?switch-app=1'));
});
