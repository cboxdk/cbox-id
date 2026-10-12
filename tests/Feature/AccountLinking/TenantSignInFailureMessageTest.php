<?php

declare(strict_types=1);

use App\Platform\AccountLinking\FederationFailure;
use Tests\Support\RootGoogle;

/*
 * THE SAME SENTENCE ON A TENANT'S OWN SIGN-IN PAGE — a vendor's end users, signing in with
 * the vendor's Google at `acme.cboxid.com/login`. Its own file because the setup is the
 * tenant's alone: no platform operator, no root Google. See FederatedSignInFailureMessagesTest
 * for every branch on the root.
 */

beforeEach(function (): void {
    RootGoogle::deployment();

    $this->account = provisionAccount('owner@acme.example');
    $this->tenantHost = $this->account['environment']->slug.'.'.RootGoogle::HOST;
});

it('shows why a sign-in did not complete on a tenant\'s own sign-in page', function (): void {
    // The tenant turns on Google for ITS users, environment-wide, in its own console.
    actAsEnvironmentAdmin($this->account['subjectId'], $this->account['environment']->id);

    $this->from('https://'.$this->tenantHost.'/admin/social-sign-in?provider=google')
        ->post('https://'.$this->tenantHost.'/admin/social-sign-in', [
            'provider' => 'google', 'clientId' => 'google-client-id', 'clientSecret' => 'tenant-secret', 'parameters' => [], 'forEnvironment' => true,
        ])->assertSessionHasNoErrors();

    RootGoogle::signedOutBrowser();

    // Somebody new to the tenant, who presses Cancel at Google.
    $response = RootGoogle::signIn('end.user@example.com', answer: ['error' => 'access_denied'], host: $this->tenantHost);

    expect((string) parse_url((string) $response->headers->get('Location'), PHP_URL_PATH))->toBe('/login');

    $page = $this->get('https://'.$this->tenantHost.'/login')->assertOk();

    expect($page->inertiaPage()['flash']['signInProblem'] ?? null)
        ->toBe(FederationFailure::Cancelled->message(['provider' => 'Google']));
});
