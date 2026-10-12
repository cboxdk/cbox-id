<?php

declare(strict_types=1);

use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * THE BLANK WHITE MODAL AFTER SIGN-IN. Signing in on cboxid.com is an Inertia visit; the
 * dashboard of a workspace with one environment redirects to `/open/{environment}`, which
 * answers with a self-submitting form to the environment host — not a page. The client
 * followed that redirect inside its XHR, got HTML, and drew it as an empty modal over the
 * sign-in form. Every owner with one environment met it on every sign-in.
 */
beforeEach(function (): void {
    multiTenantDeployment('cboxid.com');
});

/** The headers of an Inertia visit from a client on the current asset version. */
function inertiaVisit(): array
{
    return ['X-Inertia' => 'true', 'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request())];
}

it('turns an Inertia visit that redirects to the handoff into a real navigation', function (): void {
    ['subjectId' => $subjectId, 'environment' => $environment] = provisionAccount();
    signInAsMember($subjectId);

    $response = test()->get('https://cboxid.com/dashboard', inertiaVisit());

    $response->assertStatus(409);
    expect($response->headers->get('X-Inertia-Location'))->toContain('/open/'.$environment->id);
});

it('answers an Inertia visit to the handoff itself with a real navigation, never the form', function (): void {
    ['subjectId' => $subjectId, 'environment' => $environment] = provisionAccount();
    signInAsMember($subjectId);

    test()->get('https://cboxid.com/open/'.$environment->id, inertiaVisit())
        ->assertStatus(409)
        ->assertHeader('X-Inertia-Location', 'https://cboxid.com/open/'.$environment->id);
});

it('still serves the handoff form to an ordinary browser navigation', function (): void {
    ['subjectId' => $subjectId, 'environment' => $environment] = provisionAccount();
    signInAsMember($subjectId);

    test()->get('https://cboxid.com/open/'.$environment->id)->assertOk()->assertSee('<form', false);
});
