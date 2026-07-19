<?php

declare(strict_types=1);

use App\Platform\Appearance\Appearance;
use App\Platform\CurrentUser;
use App\Platform\PlatformAuth;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Livewire\Volt\Volt;

it('lets an admin theme the branded login page via the appearance editor', function () {
    [, $org] = actingAsRole('owner');

    // Branding now lives in the dedicated Appearance editor (Theme Editor), which
    // writes the full appearance block AND keeps the legacy brand_color in sync.
    $theme = Appearance::fromPreset('cbox')->toArray();
    $theme['light']['primary'] = '#0ea5e9';

    Volt::test('appearance')->call('save', $theme)->assertHasNoErrors();

    expect(app(Organizations::class)->find($org->id)?->settings)->toMatchArray([
        'brand_color' => '#0ea5e9',
    ]);

    $this->get('/o/'.$org->slug.'/login')
        ->assertOk()
        ->assertSee('#0ea5e9')          // colour injected into the themed <style>
        ->assertSee($org->name);        // org name on the branded panel
});

it('points org settings at the appearance editor', function () {
    actingAsRole('owner');

    // The old inline brand-colour form is gone; settings now links to the editor.
    Volt::test('settings')->assertSee(route('appearance'));
});

it('redirects a member away from org settings to their own account', function () {
    // A full HTTP sign-in (web session key, not just the Volt component context) so
    // GET /settings runs the real middleware + component mount as this member.
    $subject = app(Subjects::class)->create('plainmember@acme.test', 'Member', 'supersecret123');
    $org = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-plain'));
    app(Memberships::class)->add($org->id, $subject->id, 'member');
    $session = app(SessionManager::class)->start($subject->id, $org->id, ['pwd']);
    session([PlatformAuth::SESSION_KEY => $session->id]);
    app(CurrentUser::class)->set($subject, $session, $org, 'member');

    // The member/admin split is enforced at the door: a member who lands on the
    // admin settings surface is sent to their own security centre, so they never
    // reach the (admin-gated) branding or rename controls in the first place.
    $this->get('/settings')->assertRedirect(route('account'));
});

it('lets an admin rename their organization', function () {
    [, $org] = actingAsRole('owner');

    Volt::test('settings')
        ->set('orgName', 'Acme Rocketry')
        ->call('rename')
        ->assertHasNoErrors();

    expect(app(Organizations::class)->find($org->id)?->name)->toBe('Acme Rocketry');
});

it('rejects an empty organization name', function () {
    actingAsRole('owner');

    Volt::test('settings')->set('orgName', '')->call('rename')->assertHasErrors('orgName');
});

it('hides social buttons and 404s providers when none are configured', function () {
    config(['services.google.client_id' => null, 'services.github.client_id' => null, 'services.microsoft.client_id' => null]);

    $this->get('/login')->assertOk()->assertDontSee('Continue with Google');
    $this->get('/auth/google/redirect')->assertNotFound();
});

it('offers a social provider once it is configured', function () {
    config(['services.google.client_id' => 'client', 'services.google.client_secret' => 'secret']);

    $this->get('/login')->assertOk()->assertSee('Continue with Google');
});
