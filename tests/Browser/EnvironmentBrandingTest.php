<?php

declare(strict_types=1);

use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\ValueObjects\NewOrganization;

/*
|--------------------------------------------------------------------------
| THE ENVIRONMENT DEFAULT'S BRANDING, IN A BROWSER
|--------------------------------------------------------------------------
|
| On cbox.cboxid.com an administrator chose a logo on the environment console's Branding
| page, pressed the Save nearest to them — the name & email panel's own — read "Branding
| saved.", and found the logo gone after a refresh: that Save never sent it. The page has
| ONE Save now, in a bar that appears with the first change and stays in view wherever the
| person scrolls. These drive it the way that person did — on the environment's host, as
| its administrator — through to the environment's own sign-in page.
*/

beforeEach(function (): void {
    installedDeployment();
});

/** A PNG on disk, for the file input. */
function brandingPng(int $width, int $height, array $rgb): string
{
    $path = tempnam(sys_get_temp_dir(), 'brand').'.png';
    $image = imagecreatetruecolor($width, $height);
    imagefill($image, 0, 0, (int) imagecolorallocate($image, ...$rgb));
    imagepng($image, $path);

    return $path;
}

/** The save bar's own Save, by its place — there is no other "Save changes" on the page. */
const SAVE_BAR = '[data-testid="branding-save-bar"]';

it('saves an uploaded logo and favicon from the one Save, keeps them after a reload and shows them on the sign-in page', function (): void {
    actAsEnvironmentAdminOfATenant();
    $inheriting = app(Organizations::class)->create(new NewOrganization('Inherits Co', 'inherits-co'));

    $logo = brandingPng(160, 40, [20, 120, 90]);
    $favicon = brandingPng(32, 32, [200, 60, 30]);

    $page = visit('/admin/branding')
        ->assertSee('Live preview')
        // Nothing changed, nothing to save: no bar, and no Save in the header any more.
        ->assertScript('document.querySelector(\''.SAVE_BAR.'\') === null', true)
        ->assertScript('[...document.querySelectorAll("button")].filter((b) => b.textContent.trim() === "Save changes").length', 0)
        ->assertNoJavaScriptErrors();

    $page->attach('#theme-logo', $logo)
        // Previewed from the file itself, before anything is sent.
        ->assertScript('document.querySelector("[data-testid=appearance-preview] img")?.getAttribute("src").startsWith("data:image/png")', true)
        ->assertSeeIn(SAVE_BAR, 'Unsaved changes');

    $page->attach('#theme-favicon', $favicon)
        ->assertScript('document.querySelector("img[src^=\'data:image/png\'].w-3")?.getAttribute("src").startsWith("data:image/png")', true)
        ->screenshot(filename: 'fix-branding-logo-unsaved');

    // The person who lost their logo scrolled down to the name & email and saved THERE.
    // The bar came with them — in view at the bottom of the screen, not up in the header.
    $page->script('document.getElementById("brand-app-name")?.scrollIntoView({ block: "center" })');
    $page->type('#brand-app-name', 'Acme ID')
        ->assertScript('(() => { const r = document.querySelector(\''.SAVE_BAR.'\').getBoundingClientRect(); return r.top >= 0 && r.bottom <= window.innerHeight; })()', true)
        ->screenshot(filename: 'fix-branding-logo-save-bar');

    $page->click(SAVE_BAR.' button.btn-primary')
        ->assertSee('Environment branding saved.')
        ->assertScript('document.querySelector(\''.SAVE_BAR.'\') === null', true)
        // Stored, and drawn from this application now.
        ->assertScript('document.querySelector("[data-testid=appearance-preview] img")?.getAttribute("src").startsWith("/brand-assets/")', true)
        ->assertNoJavaScriptErrors();

    // A hard refresh: a new page load, nothing carried over in the editor's memory.
    $page = visit('/admin/branding')
        ->assertScript('document.querySelector("[data-testid=appearance-preview] img")?.getAttribute("src").startsWith("/brand-assets/")', true)
        ->assertScript('document.querySelector("img[alt=\'Current logo\']")?.complete && document.querySelector("img[alt=\'Current logo\']").naturalWidth', 160)
        ->assertScript('document.querySelector("img[alt=\'Current favicon\']")?.getAttribute("src").startsWith("/brand-assets/")', true)
        ->assertValue('#brand-app-name', 'Acme ID')
        ->assertScript('document.querySelector(\''.SAVE_BAR.'\') === null', true)
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'fix-branding-logo-after-reload');

    // The environment's own sign-in page, and an organization's that inherits the default.
    foreach (['/login' => 'fix-branding-logo-hosted-login', '/o/'.$inheriting->slug.'/login' => 'fix-branding-logo-hosted-login-inheriting'] as $door => $shot) {
        visit($door)
            ->assertScript('[...document.images].some((img) => img.getAttribute("src")?.startsWith("/brand-assets/") && img.complete && img.naturalWidth === 160)', true)
            ->assertScript('document.querySelector("link[rel=icon]")?.getAttribute("href").startsWith("/brand-assets/")', true)
            ->assertNoJavaScriptErrors()
            ->screenshot(filename: $shot);
    }

    @unlink($logo);
    @unlink($favicon);
});

it('discards from the bar, and asks before a link elsewhere drops unsaved changes', function (): void {
    actAsEnvironmentAdminOfATenant();

    $page = visit('/admin/branding')->assertSee('Live preview');

    // Discard puts the stored theme back and the bar away.
    $page->click('[aria-label="Warm preset"]')
        ->assertSeeIn(SAVE_BAR, 'Unsaved changes')
        ->click(SAVE_BAR.' button:not(.btn-primary)')
        ->assertScript('document.querySelector(\''.SAVE_BAR.'\') === null', true)
        ->assertScript('document.querySelector("[aria-label=\'Cbox preset\']")?.getAttribute("aria-pressed")', 'true');

    // A change, then a link in the rail: the page asks, and staying keeps the draft.
    $page->click('[aria-label="Warm preset"]')
        ->click('.cbx-rail a[href$="/admin/settings"]')
        ->assertSee('Leave without saving?')
        ->click('Keep editing')
        ->assertPathIs('/admin/branding')
        ->assertScript('document.querySelector("[aria-label=\'Warm preset\']")?.getAttribute("aria-pressed")', 'true')
        ->assertSeeIn(SAVE_BAR, 'Unsaved changes');

    // Leaving anyway goes where the link pointed.
    $page->click('.cbx-rail a[href$="/admin/settings"]')
        ->assertSee('Leave without saving?')
        ->click('Discard and leave')
        ->assertPathIs('/admin/settings')
        ->assertNoJavaScriptErrors();
});

it('keeps the save bar above the phone\'s navigation bar at 390px', function (): void {
    actAsEnvironmentAdminOfATenant();

    $page = visit('/admin/branding')->resize(390, 844)->assertSee('Live preview');

    // A typeface: a change the preview draws without touching its colours.
    $page->click('Nunito')
        ->assertSeeIn(SAVE_BAR, 'Unsaved changes')
        // Above the bottom navigation bar, not under it, and nothing wider than the phone.
        ->assertScript('document.querySelector(\''.SAVE_BAR.'\').getBoundingClientRect().bottom <= document.querySelector("[data-cbox-mobile-nav]").getBoundingClientRect().top', true)
        ->assertScript('document.documentElement.scrollWidth <= 390', true)
        ->assertNoAccessibilityIssues()
        ->screenshot(filename: 'fix-branding-logo-mobile');

    // Still there, and still above the navigation, at the far end of the page.
    $page->script('document.getElementById("main-content").scrollTo(0, 1e6)');
    $page->assertScript('(() => { const bar = document.querySelector(\''.SAVE_BAR.'\').getBoundingClientRect(); return bar.top >= 0 && bar.bottom <= document.querySelector("[data-cbox-mobile-nav]").getBoundingClientRect().top; })()', true)
        ->screenshot(filename: 'fix-branding-logo-mobile-bottom');

    $page->click(SAVE_BAR.' button.btn-primary')
        ->assertSee('Environment branding saved.')
        ->assertScript('document.querySelector(\''.SAVE_BAR.'\') === null', true);
})->group('a11y');
