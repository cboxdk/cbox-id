<?php

declare(strict_types=1);

use App\Platform\Sudo;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\Contracts\DeviceAuthorization;
use Cbox\Id\OAuthServer\Enums\ClientType;
use Cbox\Id\OAuthServer\ValueObjects\NewClient;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Cbox\Id\Platform\Contracts\Projects;
use Cbox\Id\Platform\PlatformRoot;

/*
| The hosted sign-in pages in a real browser: the typeface a theme picks actually renders
| (in the editor's preview, where it used to change nothing), and the device sign-in page a
| TV's QR code opens works on a phone.
*/

beforeEach(function (): void {
    installedDeployment();
});

/** An owner of an organization at the platform root, signed in in the browser's session. */
function hostedOwner(string $email = 'hosted-owner@acme.test'): string
{
    platformRootEnvironment();

    $subject = app(PlatformRoot::class)->run(function () use ($email) {
        $subject = app(Subjects::class)->create($email, 'Hosted Owner', 'supersecret123');
        $org = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-hosted-'.substr(md5($email), 0, 6)));
        app(Memberships::class)->add($org->id, $subject->id, MembershipRole::Owner);
        app(Projects::class)->createForOrganization($org->id, 'Acme');

        return $subject;
    });

    signInAsMember($subject->id);
    app(Sudo::class)->confirm();

    return $subject->id;
}

/** A TV app asking for a code, at the platform root this browser is signed in on. */
function tvCode(): string
{
    return app(PlatformRoot::class)->run(function (): string {
        $client = app(ClientRegistry::class)->register(new NewClient(
            name: 'Living Room TV',
            type: ClientType::Public,
            grantTypes: ['urn:ietf:params:oauth:grant-type:device_code', 'refresh_token'],
            scopes: ['openid', 'profile', 'email', 'offline_access'],
        ))->client;

        return app(DeviceAuthorization::class)->request($client, ['openid', 'email'])->userCode;
    });
}

it('changes the preview\'s typeface — headings included — when a face is picked, in a face that loads', function (): void {
    hostedOwner();

    $page = visit('/branding')->assertSee('Live preview');

    $family = 'getComputedStyle(document.querySelector("[data-testid=appearance-preview] h2")).fontFamily';

    $page->click('Source Serif')
        ->assertScript($family.'.includes("Source Serif 4")', true)
        // The body text too, not only the heading.
        ->assertScript('getComputedStyle(document.querySelector("[data-testid=appearance-preview] p")).fontFamily.includes("Source Serif 4")', true)
        // And the face is really there, from this origin — not a silent fallback.
        ->assertScript('document.fonts.load("16px \'Source Serif 4\'").then((faces) => faces.length > 0)', true);

    $page->click('Nunito')
        ->assertScript($family.'.includes("Nunito")', true)
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'hosted-login-appearance-fonts');
});

it('marks the method this device signed in with last, after signing out', function (): void {
    platformRootEnvironment();

    app(PlatformRoot::class)->run(function (): void {
        $subject = app(Subjects::class)->create('last-used@acme.test', 'Last Used', 'a-strong-unbreached-passphrase');
        $org = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-last-used'));
        app(Memberships::class)->add($org->id, $subject->id, MembershipRole::Owner);
    });

    // A device that has never signed in says nothing.
    $page = visit('/login')->assertSee('Sign in')->assertDontSee('Last used');

    // Signed in with a password in this browser…
    $page->fill('email', 'last-used@acme.test')->press('Continue');
    $page->assertSee('Password')
        ->fill('input[type="password"]', 'a-strong-unbreached-passphrase')
        ->click('button[type="submit"]');
    $page->assertSee('Welcome back')->assertPathIs('/dashboard');

    // …and out again.
    $page->click('button.cbx-avatar-btn');
    $page->click('[role="menuitem"]:has-text("Sign out")');

    // The sign-in page now badges the email step — where a password starts — and says why
    // to a screen reader, as a description of the button rather than part of its name.
    $page->assertSee('Welcome back. Access your organization')
        ->assertSee('Last used')
        ->assertScript('document.querySelector("[data-last-used]")?.getAttribute("aria-describedby")', 'last-used-hint')
        ->assertScript('document.getElementById("last-used-hint")?.textContent', 'The way you signed in last time on this device.')
        ->assertNoAccessibilityIssues()
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'hosted-login-last-used');
})->group('a11y');

it('approves a TV from a phone: the QR link prefills, the code matches, and it says to go back', function (): void {
    hostedOwner('tv-phone@acme.test');
    $code = tvCode();

    $page = visit('/device?user_code='.strtolower(str_replace('-', '', $code)))
        ->on()->mobile()
        ->assertSee('Sign in to Living Room TV?')
        ->assertSee($code)
        ->assertScript('document.documentElement.scrollWidth <= document.documentElement.clientWidth', true)
        ->assertNoAccessibilityIssues()
        ->screenshot(filename: 'hosted-login-device-consent-mobile');

    $page->press('Approve')
        ->assertSee('You can return to your TV or device')
        ->assertNoAccessibilityIssues()
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'hosted-login-device-approved-mobile');
})->group('a11y');

it('takes the code the way a phone types it, in a field that already has focus', function (): void {
    hostedOwner('tv-typed@acme.test');
    $code = tvCode();

    $page = visit('/device')
        ->on()->mobile()
        ->inDarkMode()
        ->assertSee('Connect a device')
        ->assertScript('document.activeElement?.getAttribute("name")', 'userCode')
        ->assertScript('document.activeElement?.getAttribute("autocomplete")', 'off')
        ->assertNoAccessibilityIssues()
        ->screenshot(filename: 'hosted-login-device-code-mobile-dark');

    $page->type('userCode', strtolower(str_replace('-', '', $code)))
        ->assertValue('userCode', $code)
        ->press('Continue')
        ->assertSee('Sign in to Living Room TV?')
        ->assertNoJavaScriptErrors();
})->group('a11y');

it('asks for an upload in place of a remote logo, previews the file, and saves it to every door', function (): void {
    hostedOwner('logo-owner@acme.test');

    // A remote logo URL saved before logos became uploads.
    app(PlatformRoot::class)->run(function (): void {
        $organizationId = (string) app(Memberships::class)->forUser((string) app(Subjects::class)->findByEmail('logo-owner@acme.test')?->id)->first()?->organization_id;
        app(Organizations::class)->updateSettings($organizationId, ['brand_logo_url' => 'https://cdn.example.test/logo.png']);
    });

    $png = tempnam(sys_get_temp_dir(), 'logo').'.png';
    $image = imagecreatetruecolor(160, 40);
    imagefill($image, 0, 0, (int) imagecolorallocate($image, 20, 120, 90));
    imagepng($image, $png);

    $page = visit('/branding')
        ->assertSee('Upload your logo — remote logo URLs are no longer shown.')
        // The editor never draws the old address — not even in its own preview.
        ->assertScript('[...document.images].some((img) => img.src.includes("cdn.example.test"))', false)
        ->assertNoAccessibilityIssues()
        ->screenshot(filename: 'hosted-login-appearance-remote-logo-notice');

    $page->attach('#theme-logo', $png)
        // Previewed from the file itself, before anything is sent.
        ->assertScript('document.querySelector("[data-testid=appearance-preview] img")?.src.startsWith("data:image/png")', true)
        ->assertDontSee('Upload your logo — remote logo URLs are no longer shown.')
        ->press('Save changes')
        ->assertSee('Branding saved.')
        // Saved, and now served by this application.
        ->assertScript('document.querySelector("[data-testid=appearance-preview] img")?.getAttribute("src").startsWith("/brand-assets/")', true)
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'hosted-login-appearance-logo-uploaded');

    $page->script('document.getElementById("theme-images")?.scrollIntoView({ block: "center" })');
    $page->screenshot(filename: 'hosted-login-appearance-logo-section');

    // ONE page: the white-label half — name, sender, welcome mail — beneath the sign-in look.
    $page->assertSee('Name & email')
        ->assertSee('Email sender name')
        ->assertNoAccessibilityIssues();
    $page->script('document.getElementById("brand-app-name")?.scrollIntoView({ block: "center" })');
    $page->screenshot(filename: 'hosted-login-branding-name-email');

    @unlink($png);
});
