<?php

declare(strict_types=1);

use App\Platform\Appearance\Appearance;
use App\Platform\LastSignInMethod;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Platform\PlatformRoot;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| CBOXID.COM/LOGIN IS THE PLATFORM ENVIRONMENT'S HOSTED SIGN-IN, LIKE ANY TENANT'S
|--------------------------------------------------------------------------
|
| What the platform environment's console sets — the theme and typeface, the logo, the
| sign-in methods, the social providers — is what the root's sign-in page draws, the same
| way a tenant's console's settings are what its hosted page draws.
*/

beforeEach(function (): void {
    Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 200)]);
    multiTenantDeployment('cboxid.com');
    config(['cbox-id.environments.base_domains' => ['cboxid.com']]);
    provisionAccount('owner@acme.example');

    $operator = actAsOperator('ops@platform.test');
    app(PlatformRoot::class)->run(fn () => app(Subjects::class)->markEmailVerified((string) $operator->subject_id, 'ops@platform.test'));
});

it('paints the root\'s sign-in page in the theme, typeface and logo its environment console sets', function (): void {
    $theme = Appearance::fromPreset('cbox')->toArray();
    $theme['font'] = 'serif';

    $this->from('https://cboxid.com/admin/branding')
        ->post('https://cboxid.com/admin/branding', ['theme' => $theme, 'images' => ['logo' => pngDataUri()], 'environmentDefault' => false])
        ->assertSessionHasNoErrors();

    app()->forgetScopedInstances();
    session()->flush();

    $login = $this->get('https://cboxid.com/login')->assertOk();

    // The typeface rides the style block the root view emits before React exists…
    expect((string) $login->getContent())->toContain('--font-sans:')
        // …and the logo is on the card, under the product's own name.
        ->and($login->inertiaProps('brand.logo'))->not->toBeNull()
        ->and($login->inertiaProps('brand.name'))->toBe(config('app.name'));
});

it('offers on the root\'s sign-in page exactly the methods its environment console leaves on', function (): void {
    $this->from('https://cboxid.com/admin/sign-in-rules')
        ->put('https://cboxid.com/admin/sign-in-rules/methods', [
            'passkeys' => false, 'magicLink' => false, 'botChallenge' => true, 'sessionIdleMinutes' => '', 'sessionAbsoluteMinutes' => '',
        ])
        ->assertSessionHasNoErrors();

    app()->forgetScopedInstances();
    session()->flush();

    expect($this->get('https://cboxid.com/login')->assertOk()->inertiaProps('methods'))
        ->toBe(['passkeys' => false, 'magicLink' => false]);
});

it('badges the way this device signed in last on the root, as on a tenant', function (): void {
    session()->flush();

    $this->withCookie(LastSignInMethod::COOKIE, 'passkey')
        ->get('https://cboxid.com/login')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('lastUsed', 'passkey'));
});
