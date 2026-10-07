<?php

declare(strict_types=1);

use App\Console\Commands\GenerateI18nTypesCommand;

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

it('renders the sign-in page in Danish for ui_locales=da', function (): void {
    $response = test()->withHeader('Accept-Language', 'en')->get('/login?ui_locales=da');

    $response->assertOk()
        ->assertSee('<html lang="da"', false)
        ->assertHeader('Content-Language', 'da');

    $props = (array) $response->inertiaProps();

    expect($props['i18n']['locale'])->toBe('da')
        ->and($props['i18n']['messages']['auth.login.title'])->toBe(trans('auth.login.title', [], 'da'))
        ->and($props['i18n']['messages']['auth.login.title'])->not->toBe(trans('auth.login.title', [], 'en'))
        ->and($props['title'])->toBe(trans('auth.login.title', [], 'da'))
        // Its own group and the shared chrome — not the Admin Portal's or consent's strings.
        ->and(collect(array_keys($props['i18n']['messages']))->every(
            fn (string $key): bool => str_starts_with($key, 'auth.') || str_starts_with($key, 'hosted.'),
        ))->toBeTrue();
});

it('renders the same page in English by default, unchanged', function (): void {
    $response = test()->get('/login');

    $response->assertOk()->assertSee('<html lang="en"', false);

    expect(((array) $response->inertiaProps())['title'])->toBe('Sign in');
});

it('keeps the committed TypeScript key type in step with the English catalogue', function (): void {
    expect(file_get_contents(resource_path('js/i18n/keys.ts')))
        ->toBe(GenerateI18nTypesCommand::render(), 'resources/js/i18n/keys.ts is stale — run `php artisan i18n:types`.');
});
