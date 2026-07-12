<?php

declare(strict_types=1);

use App\Platform\CurrentUser;
use App\Platform\PlatformAuth;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Identity\ValueObjects\FederatedPrincipal;
use Livewire\Volt\Volt;

it('links a pending social identity once the user signs in to the existing account', function () {
    $subject = app(Subjects::class)->create('dana@acme.test', 'Dana', 'supersecret123');

    // A social sign-in for the same email was held aside (email already taken).
    app(PlatformAuth::class)->startPendingLink(new FederatedPrincipal('social:google', 'g|1', 'dana@acme.test', 'Dana'));

    // The user proves ownership by signing in with their existing password.
    Volt::test('auth.login')
        ->set('email', 'dana@acme.test')
        ->set('password', 'supersecret123')
        ->call('login')
        ->assertRedirect(route('dashboard'));

    expect(app(Subjects::class)->linkedIdentities($subject->id))
        ->toContain(['provider' => 'social:google', 'subject' => 'g|1']);
});

it('shows connected accounts and lets a user disconnect one', function () {
    config(['services.google.client_id' => 'client', 'services.google.client_secret' => 'secret']);
    actingAsRole('owner');
    $id = app(CurrentUser::class)->id();

    // The user explicitly linked Google earlier.
    app(Subjects::class)->link($id, new FederatedPrincipal('social:google', 'google|1', 'owner@acme.test'));
    expect(app(Subjects::class)->linkedIdentities($id))->toHaveCount(1);

    Volt::test('settings')
        ->assertSee('Connected accounts')
        ->call('unlinkProvider', 'google');

    expect(app(Subjects::class)->linkedIdentities($id))->toBeEmpty();
});

it('resolves a returning linked social identity back to the same account', function () {
    actingAsRole('owner');
    $id = app(CurrentUser::class)->id();
    $subjects = app(Subjects::class);

    $subjects->link($id, new FederatedPrincipal('social:github', 'gh|42', 'owner@acme.test'));

    // A later social sign-in with the linked identity returns the same subject.
    $resolved = $subjects->provisionFederated(new FederatedPrincipal('social:github', 'gh|42', 'owner@acme.test'));

    expect($resolved->id)->toBe($id);
});
