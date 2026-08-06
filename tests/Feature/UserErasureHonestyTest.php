<?php

declare(strict_types=1);

use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Identity\Enums\UserStatus;
use Cbox\Id\Identity\Models\MfaFactor;
use Cbox\Id\Identity\Models\Session;
use Cbox\Id\Identity\Models\User;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\GenericEnvironment;
use Cbox\Id\Platform\TenantProvisioner;
use Cbox\Id\Platform\ValueObjects\TenantBlueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Exceptions\MethodNotFoundException;
use Livewire\Volt\Volt;

uses(RefreshDatabase::class);

/**
 * The user detail page used to carry a "Delete user" button that called `$user->delete()`
 * inside a `catch (\Throwable)` whose message blamed "linked records". No table in the
 * schema carries a foreign key on `user_id`, so nothing ever refused the delete: the
 * person's row went, everything ABOUT them stayed, and the console reported "User
 * deleted." An administrator told an erasure happened stops chasing it.
 *
 * These tests hold the button gone and the copy honest. They fail against the old page.
 */
if (! function_exists('erasureConsoleSetup')) {
    /** Provision an account + environment and pin an env-admin session on it. */
    function erasureConsoleSetup(): void
    {
        // The user detail page lives under `/admin`, which exists only on a multi-tenant
        // deployment. {@see \App\Http\Middleware\RequireMultiTenant}.
        multiTenantDeployment();

        platformRootEnvironment();

        $result = app(TenantProvisioner::class)->provision(new TenantBlueprint(
            organizationName: 'Acme',
            ownerEmail: 'erasure-owner@acme.example',
            ownerName: 'Owner',
            ownerPassword: 'a-strong-unbreached-passphrase',
        ));

        serveOnTestHost($result->environment);
        app(EnvironmentContext::class)->set(GenericEnvironment::of($result->environment->id));
        actAsEnvironmentAdmin($result->member, $result->environment->id);
    }
}

it('exposes no hard-delete action on the user detail page', function (): void {
    erasureConsoleSetup();
    $user = app(Subjects::class)->create('kept@acme.example', 'Kept');

    // The action is GONE, not merely hidden: a crafted wire request cannot reach it.
    Volt::test('environment.users.show', ['user' => $user->id])->call('deleteUser');
})->throws(MethodNotFoundException::class);

it('offers deactivation as the only off-switch and says what it does not do', function (): void {
    erasureConsoleSetup();
    $user = app(Subjects::class)->create('honest@acme.example', 'Honest');

    $this->get("/admin/users/{$user->id}")
        ->assertOk()
        ->assertSee('Deactivate')
        ->assertDontSee('Delete user')
        // The page must state the limit rather than let silence imply erasure.
        ->assertSee('there is no delete', false)
        ->assertSee('Erasing a person is not implemented in this platform.', false);
});

it('keeps the person and their whole data trail when they are deactivated', function (): void {
    erasureConsoleSetup();
    $user = app(Subjects::class)->create('trail@acme.example', 'Trail');

    app(SessionManager::class)->start($user->id, null, ['pwd']);
    MfaFactor::query()->create([
        'user_id' => $user->id,
        'type' => 'totp',
        'secret_encrypted' => 'sealed',
        'confirmed_at' => now(),
    ]);

    Volt::test('environment.users.show', ['user' => $user->id])
        ->call('suspend')
        ->assertRenderedNotRedirected();

    // Deactivation is a status change, and the console never claims otherwise.
    expect(User::query()->whereKey($user->id)->value('status'))->toBe(UserStatus::Disabled)
        ->and(Session::query()->where('user_id', $user->id)->exists())->toBeTrue()
        ->and(MfaFactor::query()->where('user_id', $user->id)->exists())->toBeTrue();
});
