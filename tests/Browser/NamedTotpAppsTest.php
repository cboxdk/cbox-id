<?php

declare(strict_types=1);

use App\Platform\PlatformAuth;
use App\Platform\Sudo;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\TotpDevices;
use Cbox\Id\Identity\Models\MfaFactor;
use Cbox\Id\Kernel\Crypto\TotpAuthenticator;
use Cbox\Id\Platform\PlatformRoot;

it('adds, corrects, renames and cancels independent apps on a phone-sized screen', function (): void {
    installedDeployment();
    platformRootEnvironment();
    $subjectId = app(PlatformRoot::class)->run(function (): string {
        [$subject] = accountWithOrg('totp-browser@example.test');
        $devices = app(TotpDevices::class);
        $first = $devices->enrollDevice($subject->id, $subject->email, 'Existing app');
        $devices->confirmDevice($subject->id, $first->id, app(TotpAuthenticator::class)->codeAt($first->secret, time() - 30));

        return $subject->id;
    });
    signInAsMember($subjectId);
    app(PlatformRoot::class)->run(fn () => app(SessionManager::class)->active(session(PlatformAuth::SESSION_KEY))->forceFill(['amr' => ['pwd', 'otp', 'mfa']])->save());
    app(Sudo::class)->confirm();
    $panel = 'section:has(h2:has-text("Authenticator apps"))';
    $page = visit('/account')->resize(390, 844);
    $page->assertSee('Existing app')
        ->click('button:has-text("Add authenticator app")')
        ->fill($panel.' input[name="name"]', 'Cbox ID on my iPhone')
        ->click('button:has-text("Show QR code")')
        ->assertSee('Set up Cbox ID on my iPhone');
    $secret = $page->script('document.querySelector(\'section:has(h2) .mono.select-all\').textContent.trim()');
    $page->fill($panel.' input[name="code"]', '123456')
        ->click($panel.' button:has-text("Confirm")')
        ->assertSee('That code did not match')
        ->assertVisible($panel.' img')
        ->assertSee('Existing app');
    $page->fill($panel.' input[name="code"]', app(TotpAuthenticator::class)->codeAt($secret, time() - 30))
        ->click($panel.' button:has-text("Confirm")')
        ->assertSee('Authenticator app added.')
        ->assertDontSee('Complete setup within')
        ->assertSee('Cbox ID on my iPhone')
        ->assertNoJavaScriptErrors()
        // Measure the visible toast after its fade/slide finishes, not mid-transition.
        ->assertScript('(() => {
            const toast = document.querySelector(\'[data-sonner-toast][data-front="true"]\');
            return toast !== null && getComputedStyle(toast).opacity === "1"
                && toast.getAnimations({ subtree: true }).every(animation => animation.playState === "finished");
        })()', true)
        ->assertNoAccessibilityIssues();
    expect($page->script('document.documentElement.scrollWidth <= window.innerWidth'))->toBeTrue();
    $page->screenshotElement($panel, 'named-authenticator-apps-phone');
    $page->click('button[aria-label="Rename Cbox ID on my iPhone"]')
        ->fill('.cbx-dialog input', 'Spare iPhone')
        ->click('.cbx-dialog button:has-text("Save")')
        ->assertSee('Authenticator app renamed.')
        ->assertSee('Spare iPhone');

    // Removal meets the real step-up gate after confirmation rotated the session.
    $page->click('button[aria-label="Remove Spare iPhone"]')
        ->click('.cbx-dialog button:has-text("Remove")')
        ->assertSee("Confirm it's you")
        ->fill('.cbx-dialog input[name="password"]', 'supersecret123')
        ->click('.cbx-dialog button:has-text("Confirm")')
        ->assertSee('Authenticator app removed.')
        ->assertDontSee('Spare iPhone')
        ->assertSee('Existing app');
    $page->click('button:has-text("Add authenticator app")')
        ->fill($panel.' input[name="name"]', 'Cancelled setup')
        ->click('button:has-text("Show QR code")')
        ->assertSee('Set up Cancelled setup')
        ->click($panel.' button:has-text("Cancel")')
        ->assertDontSee('Complete setup within');
    expect(app(PlatformRoot::class)->run(fn (): int => MfaFactor::query()->where('user_id', $subjectId)->count()))->toBe(1);
    $page->assertNoJavaScriptErrors();
    foreach (['da' => 'Dansk', 'de' => 'Deutsch', 'sv' => 'Svenska', 'nb' => 'Norsk bokmål', 'fr' => 'Français', 'fi' => 'Suomi', 'es' => 'Español', 'it' => 'Italiano', 'nl' => 'Nederlands'] as $locale => $language) {
        $page->click('[data-testid="console-language-picker"]')
            ->click('[role="menuitemradio"]:has-text("'.$language.'")')
            ->assertSee(__('console.totp_apps', [], $locale))
            ->assertNoJavaScriptErrors();
        expect($page->script('document.documentElement.scrollWidth <= window.innerWidth'))->toBeTrue();
        if (in_array($locale, ['da', 'de', 'fi'], true)) {
            $page->screenshotElement('section:has(h2:has-text("'.__('console.totp_apps', [], $locale).'"))', 'named-authenticator-apps-'.$locale);
        }
    }
})->group('a11y');
