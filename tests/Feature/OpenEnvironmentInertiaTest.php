<?php

declare(strict_types=1);

use App\Http\Middleware\HandleInertiaRequests;
use App\Platform\Console\EnvironmentConsolePath;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * THE BLANK WHITE MODAL AFTER SIGN-IN. Signing in on cboxid.com is an Inertia visit; a
 * sign-in that started at an environment's console redirects to `/open/{environment}`,
 * which answers with a self-submitting form to the environment host — not a page. The
 * client followed that redirect inside its XHR, got HTML, and drew it as an empty modal
 * over the sign-in form.
 */
beforeEach(function (): void {
    multiTenantDeployment('cboxid.com');
});

/** The headers of an Inertia visit from a client on the current asset version. */
function inertiaVisit(): array
{
    return ['X-Inertia' => 'true', 'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request())];
}

it('turns an Inertia visit that redirects to the open-environment door into a real navigation', function (): void {
    ['environment' => $environment] = provisionAccount();

    // Signed out at the environment's console: sent to the account host's door, and from
    // there to sign in — the one sign-in that is meant to end in that console.
    test()->get('https://cboxid.com/open/'.$environment->id)->assertRedirect('https://cboxid.com/login');

    $response = test()->from('https://cboxid.com/login')->post('https://cboxid.com/login', [
        'email' => 'owner@acme.example',
        'password' => 'a-strong-unbreached-passphrase',
    ], inertiaVisit());

    $response->assertStatus(409);
    expect($response->headers->get('X-Inertia-Location'))->toContain('/open/'.$environment->id);
});

it('answers an Inertia visit to the open-environment door itself with a real navigation', function (): void {
    ['subjectId' => $subjectId, 'environment' => $environment] = provisionAccount();
    signInAsMember($subjectId);

    test()->get('https://cboxid.com/open/'.$environment->id, inertiaVisit())
        ->assertStatus(409)
        ->assertHeader('X-Inertia-Location', 'https://cboxid.com/open/'.$environment->id);
});

it('sends an ordinary browser navigation straight to the environment\'s console', function (): void {
    ['subjectId' => $subjectId, 'environment' => $environment] = provisionAccount();
    signInAsMember($subjectId);

    test()->get('https://cboxid.com/open/'.$environment->id)
        ->assertRedirect('https://cboxid.com/'.app(EnvironmentConsolePath::class)->for($environment->id));
});
