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
        actAsEnvironmentAdmin($result->owner->id, $result->environment->id);
    }
}

it('exposes no hard-delete route for a user', function (): void {
    erasureConsoleSetup();
    $user = app(Subjects::class)->create('kept@acme.example', 'Kept');

    /*
     * THE ACTION IS GONE, not merely hidden. Under Livewire that meant "the method does
     * not exist"; the same claim now is that no route reaches it — asserted by ASKING FOR
     * IT rather than by grepping the routes file, because a route added under any other
     * name would satisfy a grep and still delete somebody.
     *
     * 405 rather than 404: the URI is real — it is the detail page, and it answers GET and
     * PATCH — and DELETE is simply not one of the things you may do to a person here. The
     * person surviving the request is the claim that actually matters, because a refusal
     * status that nonetheless deleted the row would pass a status assertion alone.
     */
    test()->delete("/admin/users/{$user->id}")->assertStatus(405);

    expect(User::query()->whereKey($user->id)->exists())->toBeTrue();
});

it('offers deactivation as the only off-switch and says what it does not do', function (): void {
    erasureConsoleSetup();
    $user = app(Subjects::class)->create('honest@acme.example', 'Honest');

    /*
     * WHAT THE PAGE OFFERS, read off the props it is built from. The copy that states the
     * limit is in the component and is held by the browser suite, which is the only place
     * that can see whether a sentence is drawn; what the SERVER decides is which lifecycle
     * URLs this person gets — and there is no erasure among them.
     */
    $urls = (array) $this->get("/admin/users/{$user->id}")->assertOk()->inertiaProps('urls');

    expect($urls)->toHaveKey('deactivate')
        ->and($urls)->not->toHaveKey('delete')
        ->and($urls)->not->toHaveKey('destroy')
        ->and($urls)->not->toHaveKey('erase');
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

    test()->post(route('environment.users.deactivate', $user->id))->assertSessionHasNoErrors();

    // Deactivation is a status change, and the console never claims otherwise.
    expect(User::query()->whereKey($user->id)->value('status'))->toBe(UserStatus::Disabled)
        ->and(Session::query()->where('user_id', $user->id)->exists())->toBeTrue()
        ->and(MfaFactor::query()->where('user_id', $user->id)->exists())->toBeTrue();
});
