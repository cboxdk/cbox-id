<?php

declare(strict_types=1);

use App\Platform\Appearance\Appearance;
use App\Platform\Appearance\BrandImage;
use App\Platform\Appearance\BrandImages;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Cbox\Id\Whitelabel\Contracts\BrandProfiles;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;

/*
|--------------------------------------------------------------------------
| THE BRANDING PAGE'S ONE SAVE, AT THE ENVIRONMENT DEFAULT
|--------------------------------------------------------------------------
|
| The page had two Saves: the sign-in theme's (with the logo and favicon) in the header, and
| the name & email panel's below it. On cbox.cboxid.com an administrator uploaded a logo,
| pressed the Save nearest to them — the profile's — read "Branding saved.", and lost the
| logo on the next load. These hold that the page's one request saves both halves, that the
| environment default's images are stored and served by this application, and that they
| reach the environment's sign-in page and every organization that inherits it.
*/

beforeEach(function (): void {
    installedDeployment();
});

/** The page's one Save, on the environment console's own Branding page. */
function saveEnvironmentBranding(array $payload): TestResponse
{
    return test()->from(route('environment.branding'))
        ->post(route('environment.branding.update'), ['environmentDefault' => true, ...$payload]);
}

it('stores the environment default\'s logo and favicon with the name & email, and serves them to every door', function (): void {
    actAsEnvironmentAdminOfATenant();

    $logo = pngDataUri(160, 40);

    saveEnvironmentBranding([
        'theme' => Appearance::fromPreset('cbox')->toArray(),
        'images' => ['logo' => $logo, 'favicon' => pngDataUri(32, 32)],
        'profile' => ['palette' => [], 'appName' => 'Acme ID', 'emailFromName' => 'Acme', 'emailTemplate' => ''],
    ])->assertSessionHasNoErrors()->assertSessionHas('status', 'Environment branding saved.');

    $images = app(BrandImages::class);
    $logoUrl = (string) $images->url(BrandImage::Logo, null);
    $faviconUrl = (string) $images->url(BrandImage::Favicon, null);

    expect($logoUrl)->toStartWith('/brand-assets/brand/')
        ->and($faviconUrl)->toStartWith('/brand-assets/brand/')
        // Both halves, from one request.
        ->and(app(BrandProfiles::class)->forEnvironment()?->app_name)->toBe('Acme ID');

    // Served by this application: the bytes that were uploaded, as an image, cached for good
    // because a new upload is a new name.
    $served = $this->get($logoUrl)->assertOk()->assertHeader('Content-Type', 'image/png');

    expect($served->headers->get('Cache-Control'))->toContain('max-age=31536000')->toContain('immutable')->toContain('public')
        ->and($served->getContent())->toBe(base64_decode(substr($logo, strlen('data:image/png;base64,')), true));

    $this->get($faviconUrl)->assertOk()->assertHeader('Content-Type', 'image/png');

    // After a reload of the page, the editor starts from the stored images.
    $this->get(route('environment.branding'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('console/branding')
            ->where('environmentDefault', true)
            ->where('appearance.logo', $logoUrl)
            ->where('appearance.favicon', $faviconUrl)
            ->where('profile.appName', 'Acme ID'));

    // The environment's own sign-in page…
    $login = $this->get(route('login'))->assertOk();

    expect($login->inertiaProps('brand.logo'))->toBe($logoUrl)
        ->and((string) $login->getContent())->toContain('<link rel="icon" href="'.$faviconUrl.'">');

    // …and an organization that has not set its own, which inherits it.
    $inheriting = app(Organizations::class)->create(new NewOrganization('Inherits Co', 'inherits-co'));

    expect($this->get(route('login.branded', $inheriting->slug))->assertOk()->inertiaProps('brand.logo'))->toBe($logoUrl);
});

it('saves the name & email alone without re-saving the theme', function (): void {
    actAsEnvironmentAdminOfATenant();

    saveEnvironmentBranding([
        'profile' => ['palette' => [], 'appName' => 'Only The Name', 'emailFromName' => '', 'emailTemplate' => ''],
    ])->assertSessionHasNoErrors();

    expect(app(BrandProfiles::class)->forEnvironment()?->app_name)->toBe('Only The Name')
        ->and(app(BrandImages::class)->url(BrandImage::Logo, null))->toBeNull();
});

it('writes nothing when the theme is refused, and says so on the theme', function (): void {
    actAsEnvironmentAdminOfATenant();

    // Near-black text on a near-black page.
    $unreadable = [
        'radius' => '0.5rem',
        'font' => 'system',
        'light' => ['primary' => '#3b6fd4', 'background' => '#101014', 'foreground' => '#141418', 'muted' => '#16161a'],
        'dark' => ['primary' => '#3b6fd4', 'background' => '#1e1e21', 'foreground' => '#f5f5f5', 'muted' => '#a0a0a0'],
    ];

    saveEnvironmentBranding([
        'theme' => $unreadable,
        'images' => ['logo' => pngDataUri()],
        'profile' => ['palette' => [], 'appName' => 'Not Saved', 'emailFromName' => '', 'emailTemplate' => ''],
    ])->assertSessionHasErrors('theme');

    expect(app(BrandImages::class)->url(BrandImage::Logo, null))->toBeNull()
        ->and(app(BrandProfiles::class)->forEnvironment()?->app_name)->toBeNull();
});

it('keeps a saved theme and marks the profile field it refused', function (): void {
    actAsEnvironmentAdminOfATenant();

    saveEnvironmentBranding([
        'theme' => Appearance::fromPreset('warm')->toArray(),
        'images' => ['logo' => pngDataUri()],
        'profile' => ['palette' => ['primary' => 'not a colour'], 'appName' => 'Acme', 'emailFromName' => '', 'emailTemplate' => ''],
    ])->assertSessionHasErrors('profile.palette.primary');

    expect(app(BrandImages::class)->url(BrandImage::Logo, null))->toStartWith('/brand-assets/');
});

it('refuses a save with nothing in it', function (): void {
    actAsEnvironmentAdminOfATenant();

    saveEnvironmentBranding([])->assertSessionHasErrors('theme');
});

it('refuses an image sent without the theme it belongs to', function (): void {
    actAsEnvironmentAdminOfATenant();

    saveEnvironmentBranding([
        'images' => ['logo' => pngDataUri()],
        'profile' => ['palette' => [], 'appName' => 'Acme', 'emailFromName' => '', 'emailTemplate' => ''],
    ])->assertSessionHasErrors('theme');

    expect(app(BrandImages::class)->url(BrandImage::Logo, null))->toBeNull();
});
