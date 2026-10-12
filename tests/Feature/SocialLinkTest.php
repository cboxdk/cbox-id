<?php

declare(strict_types=1);

use App\Platform\CurrentUser;
use App\Platform\Sudo;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Identity\ValueObjects\FederatedPrincipal;
use Cbox\Id\Organization\Enums\MembershipRole;

/*
 * The deployment OPERATOR's social providers (`services.google.*`) on My account.
 *
 * The link step that replaced the old "sign in, then confirm" hold has its own tests in
 * tests/Feature/AccountLinking; what stays here is what an operator provider adds to the
 * account page, and that a linked identity resolves back to its account.
 */

it('lists an operator provider\'s linked identity and lets a user disconnect it', function () {
    config(['services.google.client_id' => 'client', 'services.google.client_secret' => 'secret']);
    actingAsRole(MembershipRole::Owner);
    $id = app(CurrentUser::class)->id();

    // The user explicitly linked Google earlier.
    app(Subjects::class)->link($id, new FederatedPrincipal('social:google', 'google|1', 'owner@acme.test', raw: ['provider' => 'google', 'email' => 'owner@acme.test']));

    // Disconnecting is sensitive → confirm step-up. The account keeps its password,
    // so the last-way-in guard allows it.
    app(Sudo::class)->confirm();

    // The page OFFERS it — asked of the props rather than of the document, because a row
    // that renders "Linked" without carrying a disconnect URL is a button that does
    // nothing.
    $google = collect(accountSecurity()['signInMethods']['identities'])->firstWhere('provider', 'google');

    expect($google['label'])->toBe('Google')
        ->and($google['email'])->toBe('owner@acme.test')
        ->and($google['disconnectHref'])->toBeString();

    // …and Google is not offered again as something to connect.
    expect(collect(accountSecurity()['signInMethods']['connectable'])->pluck('provider')->all())->not->toContain('google');

    unlinkSocialProvider('google')->assertSessionHasNoErrors();

    expect(app(Subjects::class)->linkedIdentities($id))->toBeEmpty()
        ->and(collect(accountSecurity()['signInMethods']['connectable'])->pluck('provider')->all())->toContain('google');
});

it('resolves a returning linked social identity back to the same account', function () {
    actingAsRole(MembershipRole::Owner);
    $id = app(CurrentUser::class)->id();
    $subjects = app(Subjects::class);

    $subjects->link($id, new FederatedPrincipal('social:github', 'gh|42', 'owner@acme.test'));

    // A later social sign-in with the linked identity returns the same subject.
    $resolved = $subjects->provisionFederated(new FederatedPrincipal('social:github', 'gh|42', 'owner@acme.test'));

    expect($resolved->id)->toBe($id);
});
