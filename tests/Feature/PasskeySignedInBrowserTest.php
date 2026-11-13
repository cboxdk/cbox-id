<?php

declare(strict_types=1);

use App\Platform\PlatformAuth;
use Cbox\Id\Identity\Contracts\SessionManager;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * A SIGN-IN PAGE LEFT OPEN IN A BROWSER THAT HAS SIGNED IN SINCE — a restored tab on a phone,
 * most often (found from Chrome on an iPhone in production).
 *
 * The passkey ceremony asks the guest doors for its challenge with `fetch`. The guest gate
 * answered that browser with a 302 to the dashboard, and a fetch follows a 302: in login-host
 * mode the dashboard is on the app host, the redirect left the origin, `connect-src 'self'`
 * stopped it, and the page could only say "Load failed". A JSON caller is told where to go
 * instead, and the page goes there as a navigation.
 */
function signedInBrowser(string $email): void
{
    [$subject] = accountWithOrg($email);

    test()->withSession([
        PlatformAuth::SESSION_KEY => app(SessionManager::class)->start($subject->id, null, ['pwd'])->id,
    ]);
}

it('tells a signed-in browser asking for passkey options where to go, instead of redirecting the fetch', function (): void {
    signedInBrowser('restored-tab@acme.test');

    $this->postJson('/passkeys/login/options')
        ->assertStatus(409)
        ->assertHeaderMissing('Location')
        ->assertJsonPath('redirect', route('dashboard'));
});

it('answers the passkey assertion of a signed-in browser the same way', function (): void {
    signedInBrowser('restored-tab-assert@acme.test');

    $this->postJson('/passkeys/login', ['id' => 'cred_x', 'type' => 'public-key', 'response' => []])
        ->assertStatus(409)
        ->assertJsonPath('redirect', route('dashboard'));
});

it('answers the page\'s own request shape — Accept: application/json, a JSON body, no X-Requested-With', function (): void {
    signedInBrowser('fetch-shape@acme.test');

    $this->call('POST', '/passkeys/login/options', [], [], [], [
        'HTTP_ACCEPT' => 'application/json',
        'CONTENT_TYPE' => 'application/json',
    ], '{}')->assertStatus(409)->assertJsonPath('redirect', route('dashboard'));
});

it('still sends a signed-in browser navigating to the sign-in page to the dashboard', function (): void {
    signedInBrowser('navigates@acme.test');

    $this->get('/login')->assertRedirect(route('dashboard'));
});

it('leaves an Inertia visit to a guest door to Inertia\'s own way of leaving the page', function (): void {
    loginHostMode();

    $account = provisionAccount();
    signInAsMember($account['subjectId']);
    session()->put('cbox.surface', 'login');

    // Inertia posts JSON too; it must not be mistaken for a script that cannot follow.
    $this->withHeaders(['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest'])
        ->postJson('https://'.LOGIN_HOST.'/login', ['email' => 'someone@acme.test', 'password' => 'x'])
        ->assertStatus(409)
        ->assertHeader('X-Inertia-Location', 'https://'.APP_HOST.'/auth/start?return_to='.rawurlencode('/dashboard'));
});

it('sends the signed-in browser through the console\'s start in login-host mode, as a navigation the page makes', function (): void {
    loginHostMode();

    $account = provisionAccount();
    signInAsMember($account['subjectId']);
    session()->put('cbox.surface', 'login');

    // The redirect this replaced: off the login host, to the app host — a cross-origin hop
    // a fetch under `connect-src 'self'` is not allowed to make.
    $this->postJson('https://'.LOGIN_HOST.'/passkeys/login/options')
        ->assertStatus(409)
        ->assertHeaderMissing('Location')
        ->assertJsonPath('redirect', 'https://'.APP_HOST.'/auth/start?return_to='.rawurlencode('/dashboard'));
});

it('still issues a guest its passkey options', function (): void {
    $this->postJson('/passkeys/login/options')
        ->assertOk()
        ->assertJsonStructure(['challenge', 'rpId']);
});
