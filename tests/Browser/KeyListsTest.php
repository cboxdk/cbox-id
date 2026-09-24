<?php

declare(strict_types=1);

use App\Platform\Sudo;
use Carbon\CarbonImmutable;
use Cbox\Id\Platform\Contracts\EnvironmentApiKeys;
use Cbox\Id\Platform\Enums\EnvironmentApiScope;
use Cbox\Id\Platform\PlatformRoot;

/**
 * A REVOKED KEY IS DRAWN AS ONE.
 *
 * The environment key list drew every key the same way — a revoked key beside the live
 * ones, a Revoke button on each. The feature suite asserts what the page is GIVEN (a
 * status and a null revoke link); whether the row actually draws the status and leaves
 * the button off is a fact about the rendered page, so it is checked here.
 */
beforeEach(function (): void {
    installedDeployment();
});

it('marks revoked and expired environment keys and offers Revoke only on a live one', function (): void {
    ['subjectId' => $ownerId, 'environment' => $environment] = provisionAccount();
    signInAsMember($ownerId);
    app(Sudo::class)->confirm();

    app(PlatformRoot::class)->run(function () use ($environment): void {
        $keys = app(EnvironmentApiKeys::class);
        $keys->issue($environment->id, 'Live worker', ['users:read']);
        $keys->revoke($environment->id, $keys->issue($environment->id, 'Old worker', ['users:read'])->key->id);
        $keys->issue($environment->id, 'Lapsed worker', ['users:read'], CarbonImmutable::now()->subDay());
    });

    $page = visit('/keys');

    $page->assertSee('Live worker')
        ->assertSee('Revoked')
        ->assertSee('Expired')
        // Labels, with the API's key beside them — not the raw key alone.
        ->assertSee('Read users')
        // One live key, one button.
        ->assertScript(
            'Array.from(document.querySelectorAll("button")).filter(b => b.textContent.trim() === "Revoke").length',
            1,
        )
        ->assertNoJavaScriptErrors();
})->group('a11y');

it('draws a labelled box for every scope a management key can be given', function (): void {
    ['subjectId' => $ownerId] = provisionAccount();
    signInAsMember($ownerId);
    app(Sudo::class)->confirm();

    $page = visit('/keys');

    // One checkbox per offered scope — the whole tenancy API, and nothing reserved.
    $page->assertScript(
        'document.querySelectorAll("fieldset [role=checkbox]").length',
        count(EnvironmentApiScope::offerable()),
    );

    foreach (EnvironmentApiScope::offerable() as $scope) {
        $page->assertSee($scope->label())->assertSee($scope->value);
    }

    $page->assertDontSee('directories:read')->assertNoJavaScriptErrors();
});
