<?php

declare(strict_types=1);

use App\Console\Commands\GenerateI18nTypesCommand;
use App\Platform\Locale\HostedLocale;

/**
 * A HOSTED PAGE, END TO END, IN ANOTHER LANGUAGE — no browser needed.
 *
 * The props are what React renders and the root view is what a screen reader and a
 * translation prompt read first, so both are asserted on the same response: the Danish
 * catalogue reached the page, the server's own words (the tab title) are Danish too, and
 * `<html lang>` says so.
 */
beforeEach(function (): void {
    installedDeployment();
});

it('renders the sign-in page in each supported translation', function (string $locale): void {
    $response = test()->withHeader('Accept-Language', 'en')->get('/login?ui_locales='.$locale);

    $response->assertOk()
        ->assertSee('<html lang="'.$locale.'"', false)
        ->assertHeader('Content-Language', $locale);

    $props = (array) $response->inertiaProps();

    expect($props['i18n']['locale'])->toBe($locale)
        ->and($props['i18n']['messages']['auth.login.title'])->toBe(trans('auth.login.title', [], $locale))
        ->and($props['i18n']['messages']['auth.login.title'])->not->toBe(trans('auth.login.title', [], 'en'))
        ->and($props['title'])->toBe(trans('auth.login.title', [], $locale))
        ->and($props['app']['tagline'])->toBe(trans('hosted.layout.hero_title', [], $locale))
        // Authentication and shared interface text, without Admin Portal or consent strings.
        ->and(collect(array_keys($props['i18n']['messages']))->every(
            fn (string $key): bool => str_starts_with($key, 'auth.') || str_starts_with($key, 'hosted.') || str_starts_with($key, 'console.') || str_starts_with($key, 'errors.'),
        ))->toBeTrue();
})->with(array_values(array_diff(HostedLocale::codes(), ['en'])));

it('renders the same page in English by default, unchanged', function (): void {
    $response = test()->get('/login');

    $response->assertOk()->assertSee('<html lang="en"', false);

    expect(((array) $response->inertiaProps())['title'])->toBe('Sign in');
});

it('keeps the committed TypeScript key type in step with the English catalogue', function (): void {
    expect(file_get_contents(resource_path('js/i18n/keys.ts')))
        ->toBe(GenerateI18nTypesCommand::render(), 'resources/js/i18n/keys.ts is stale — run `php artisan i18n:types`.');
});

it('keeps a deployment-authored headline when changing language', function (): void {
    config(['cbox-id.branding.tagline' => 'Our own identity service']);
    $response = test()->get('/login?ui_locales=da')->assertOk();
    expect(((array) $response->inertiaProps())['app']['tagline'])->toBe('Our own identity service');
});
