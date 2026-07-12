<?php

declare(strict_types=1);

use App\Platform\CurrentUser;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Identity\ValueObjects\FederatedPrincipal;
use Livewire\Volt\Volt;

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
