<?php

declare(strict_types=1);

use App\Http\Middleware\ResolveConsoleLocale;
use App\Platform\EnvironmentAdminAuth;
use App\Platform\Locale\HostedLocale;
use App\Platform\Locale\LocaleResolver;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Organization\Models\Environment;
use Illuminate\Testing\TestResponse;

/**
 * WHICH LANGUAGE A HOSTED PAGE IS DRAWN IN — each source, in order, and the two kinds of
 * answer that must be skipped rather than trusted.
 *
 * The order is the policy ({@see LocaleResolver}): the relying party's `ui_locales`, then
 * the same choice remembered for the rest of the authorization, then the person's own pick
 * (the cookie), then the browser, then the environment's default. Every test below sends
 * the LOSING sources too, so a test passes only if the winner beat them — not because it
 * was the only thing said.
 */
beforeEach(function (): void {
    installedDeployment();
    config()->set('cbox-id.locales.enabled', HostedLocale::codes());
});

/**
 * The environment an unmapped test host resolves to, with its language settings.
 *
 * @param  array<string, mixed>  $settings
 */
function hostedLanguages(array $settings): Environment
{
    $environment = platformRootEnvironment();
    $environment->settings = [...(is_array($environment->settings) ? $environment->settings : []), ...$settings];
    $environment->save();

    return $environment;
}

/** The sign-in page, as a browser with these preferences asks for it. */
function signInPageIn(string $acceptLanguage, ?string $cookie = null, string $query = ''): TestResponse
{
    $request = test()->withHeader('Accept-Language', $acceptLanguage);

    if ($cookie !== null) {
        $request = $request->withCookie(LocaleResolver::COOKIE, $cookie);
    }

    return $request->get('/login'.$query);
}

/** @return array<string, mixed> */
function i18nOf(TestResponse $response): array
{
    $props = (array) $response->assertOk()->inertiaProps();

    expect($props['i18n'] ?? null)->toBeArray('a hosted page shipped no i18n prop');

    /** @var array<string, mixed> */
    return $props['i18n'];
}

it('takes ui_locales over the cookie and the browser', function (): void {
    $response = signInPageIn('sv', cookie: 'de', query: '?ui_locales=da');

    expect(i18nOf($response)['locale'])->toBe('da');
    $response->assertHeader('Content-Language', 'da');
});

it('takes the first ui_locales entry this platform speaks, in the order given', function (): void {
    expect(i18nOf(signInPageIn('en', query: '?ui_locales='.rawurlencode('ja-JP fr-CA de')))['locale'])->toBe('fr');
});

it('keeps the ui_locales choice for the later hops of the same authorization', function (): void {
    signInPageIn('en', query: '?ui_locales=nb')->assertOk();

    // The next hop carries no parameter, and the browser and a cookie disagree.
    expect(i18nOf(signInPageIn('de', cookie: 'sv'))['locale'])->toBe('nb')
        ->and(session(LocaleResolver::SESSION_KEY))->toBe('nb');
});

it('does not let an unusable ui_locales wipe a good one an earlier hop stored', function (): void {
    signInPageIn('en', query: '?ui_locales=da')->assertOk();

    expect(i18nOf(signInPageIn('en', query: '?ui_locales=xx'))['locale'])->toBe('da');
});

it('takes the picker cookie over the browser', function (): void {
    expect(i18nOf(signInPageIn('de-DE,de;q=0.9', cookie: 'sv'))['locale'])->toBe('sv');
});

it('reads Accept-Language in q-order, by primary language', function (string $header, string $expected): void {
    expect(i18nOf(signInPageIn($header))['locale'])->toBe($expected);
})->with([
    'region subtag' => ['da-DK', 'da'],
    'q-values beat list order' => ['en;q=0.3, de;q=0.9', 'de'],
    'unknown first, known second' => ['ja, fr;q=0.5', 'fr'],
    'Norwegian macrolanguage' => ['no', 'nb'],
    'Bokmål' => ['nb-NO', 'nb'],
]);

it('falls back to the environment default when nobody said anything usable', function (): void {
    hostedLanguages(['default_locale' => 'de']);

    $response = signInPageIn('ja');

    expect(i18nOf($response)['locale'])->toBe('de');
    $response->assertSee('<html lang="de"', false);
});

it('ignores locales it has no catalogue for, from every source', function (): void {
    hostedLanguages(['default_locale' => 'sv']);

    expect(i18nOf(signInPageIn('xx-YY, zz', cookie: 'klingon', query: '?ui_locales=tlh'))['locale'])->toBe('sv');
});

it('ignores a real language the environment has switched off, from every source', function (): void {
    hostedLanguages(['enabled_locales' => ['en', 'da'], 'default_locale' => 'en']);

    // German, French and Swedish are all supported — and all switched off here.
    $response = signInPageIn('sv, da;q=0.5', cookie: 'fr', query: '?ui_locales=de');

    expect(i18nOf($response)['locale'])->toBe('da')
        ->and(collect(i18nOf($response)['locales'])->pluck('code')->all())->toBe(['en', 'da']);
});

it('keeps the default inside the enabled list', function (): void {
    hostedLanguages(['enabled_locales' => ['fr', 'de'], 'default_locale' => 'da']);

    // A default the environment does not offer would be chosen by everyone and offered by
    // nothing; the list wins.
    expect(i18nOf(signInPageIn('ja'))['locale'])->toBe('fr');
});

it('names each language in itself for the picker', function (): void {
    $locales = collect(i18nOf(signInPageIn('en'))['locales'])->pluck('name', 'code')->all();

    expect($locales)->toBe([
        'en' => 'English',
        'da' => 'Dansk',
        'de' => 'Deutsch',
        'sv' => 'Svenska',
        'nb' => 'Norsk bokmål',
        'fr' => 'Français',
        'fi' => 'Suomi',
        'es' => 'Español',
        'it' => 'Italiano',
        'nl' => 'Nederlands',
    ]);
});

it('renders the console in Danish when the browser prefers it', function (): void {
    ['subjectId' => $subjectId] = provisionAccount('owner@acme.example');
    signInAsMember($subjectId);

    $response = test()->withHeader('Accept-Language', 'da')->withCookie(LocaleResolver::COOKIE, 'da')->get(route('dashboard'));

    $response->assertOk()->assertSee('<html lang="da"', false)->assertHeader('Content-Language', 'da');
    $props = (array) $response->inertiaProps();
    expect($props['i18n']['locale'])->toBe('da')
        ->and(collect($props['i18n']['locales'])->pluck('code')->all())->toBe(HostedLocale::codes())
        ->and($props['i18n']['messages']['console.my_account_b53181a4'])->toBe('Min konto');
});

it('remembers a language picked on the page, and drops the authorization’s choice for it', function (): void {
    signInPageIn('en', query: '?ui_locales=da')->assertOk();

    test()->from('/login')
        ->post(route('locale.update'), ['locale' => 'fr'])
        ->assertRedirect('/login')
        ->assertCookie(LocaleResolver::COOKIE, 'fr');

    expect(session(LocaleResolver::SESSION_KEY))->toBeNull();
});

it('refuses to remember a language the environment does not offer', function (): void {
    hostedLanguages(['enabled_locales' => ['en', 'da']]);

    test()->from('/login')
        ->post(route('locale.update'), ['locale' => 'de'])
        ->assertRedirect('/login')
        ->assertCookieMissing(LocaleResolver::COOKIE);
});

it('narrows tags to the closed set', function (?string $tag, ?HostedLocale $expected): void {
    expect(HostedLocale::fromTag($tag))->toBe($expected);
})->with([
    ['da', HostedLocale::Danish],
    ['DA_dk', HostedLocale::Danish],
    ['de-AT', HostedLocale::German],
    ['no', HostedLocale::NorwegianBokmal],
    ['nn', null],
    ['fr-CA', HostedLocale::French],
    ['', null],
    [null, null],
    ['english', null],
]);

it('shares the console language independently of the hosted authorization language', function (): void {
    ['subjectId' => $subjectId] = provisionAccount('owner@acme.example');
    signInAsMember($subjectId);
    session()->put(LocaleResolver::SESSION_KEY, 'fr');

    test()->from(route('dashboard'))->post(route('console.locale.update'), ['locale' => 'da', 'user_id' => $subjectId])
        ->assertRedirect(route('dashboard'))
        ->assertCookieExpired(ResolveConsoleLocale::COOKIE);
    expect(session(LocaleResolver::SESSION_KEY))->toBe('fr');

    $response = test()->withHeader('Accept-Language', 'en')
        ->withHeader('Accept-Language', 'da')
        ->get(route('dashboard'));
    $response->assertOk()->assertHeader('Content-Language', 'da');
    expect(((array) $response->inertiaProps())['i18n']['locale'])->toBe('da');
});

it('does not let hosted language restrictions remove Danish from the console', function (): void {
    ['subjectId' => $subjectId] = provisionAccount('owner@acme.example');
    signInAsMember($subjectId);
    config()->set('cbox-id.locales.enabled', ['fr']);
    config()->set('cbox-id.locales.default', 'fr');

    $response = test()->withHeader('Accept-Language', 'da')->get(route('dashboard'));
    $response->assertOk()->assertHeader('Content-Language', 'da');
    expect(collect(((array) $response->inertiaProps())['i18n']['locales'])->pluck('code')->all())->toBe(HostedLocale::codes());
});

it('rejects unsupported console language choices without changing the preference', function (mixed $locale): void {
    ['subjectId' => $subjectId] = provisionAccount();
    signInAsMember($subjectId);
    test()->postJson(route('console.locale.update'), ['locale' => $locale, 'user_id' => $subjectId])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('locale')
        ->assertCookieMissing(ResolveConsoleLocale::COOKIE);
})->with(['ja', 'pl', '<script>alert(1)</script>', '', 'array' => [['da']]]);

it('falls back to English for unsupported browser and cookie languages in the console', function (): void {
    ['subjectId' => $subjectId] = provisionAccount('owner@acme.example');
    signInAsMember($subjectId);

    $response = test()->withHeader('Accept-Language', 'ja')
        ->withCookie(ResolveConsoleLocale::COOKIE, 'ja')
        ->get(route('dashboard'));
    $response->assertOk()->assertHeader('Content-Language', 'en')->assertSee('<html lang="en"', false);
    expect(((array) $response->inertiaProps())['i18n']['locale'])->toBe('en');
});

it('translates administration titles and help while preserving user data and identifiers', function (): void {
    actAsEnvironmentAdminOfATenant();
    $name = 'Delete <Save> & Environment';
    $person = app(Subjects::class)->create('language-data@example.test', $name, 'supersecret123');

    $response = test()->withHeader('Accept-Language', 'da')
        ->get(route('environment.users'));
    $response->assertOk()->assertHeader('Content-Language', 'da');
    $props = (array) $response->inertiaProps();
    expect($props['title'])->toBe('Brugere')
        ->and($props['help']['title'])->toBe('Alle, der kan logge ind her');
    $row = collect($props['users'])->firstWhere('id', $person->id);
    expect($row['name'])->toBe($name)->and($row['email'])->toBe('language-data@example.test');

    $english = test()->withHeader('Accept-Language', 'en')
        ->get(route('environment.users'));
    expect(((array) $english->inertiaProps())['title'])->toBe('Users');
});

it('validates an administrator form in the selected console language', function (): void {
    actAsEnvironmentAdminOfATenant();
    confirmConsoleStepUp();
    $response = test()->withHeader('Accept-Language', 'da')
        ->withCredentials()->postJson(route('environment.users.store'), ['name' => '', 'email' => 'not-an-email']);
    $response->assertUnprocessable()->assertJsonValidationErrors(['email']);
    expect($response->json('errors.email.0'))->toContain('gyldig');
});

it('translates built-in security rules and fields without changing their keys or conditions', function (): void {
    actAsEnvironmentAdminOfATenant();
    confirmConsoleStepUp();
    $response = test()->withHeader('Accept-Language', 'da')->get(route('environment.radar.rules'));
    $response->assertOk();
    $props = (array) $response->inertiaProps();
    $builtin = collect($props['settings']['builtin_rules'])->firstWhere('key', 'new_device');
    expect($builtin['name'])->toBe('Ny enhed')->and($builtin['applies_to'])->toBe('sign_in');

    $editor = test()->get(route('environment.radar.rules.create'));
    $editor->assertOk();
    $field = collect(((array) $editor->inertiaProps())['fields'])->firstWhere('value', 'country');
    expect($field['label'])->toBe('Land')->and($field['type'])->toBe('country')
        ->and($field['operators'][0]['value'])->toBe('eq')->and($field['operators'][0]['label'])->toBe('er');
});

it('persists every supported console language independently of hosted policy', function (string $locale): void {
    actAsEnvironmentAdminOfATenant();
    config()->set('cbox-id.locales.enabled', ['en']);
    test()->from(route('environment.users'))
        ->post(route('environment.locale.update'), ['locale' => $locale, 'user_id' => app(EnvironmentAdminAuth::class)->subjectId()])
        ->assertRedirect(route('environment.users'))
        ->assertCookieExpired(ResolveConsoleLocale::COOKIE);

    $response = test()->withCookie(ResolveConsoleLocale::COOKIE, $locale)->get(route('environment.users'));
    $response->assertOk()->assertHeader('Content-Language', $locale);
    $props = (array) $response->inertiaProps();
    expect($props['i18n']['locale'])->toBe($locale)
        ->and(array_column($props['i18n']['locales'], 'code'))->toBe(HostedLocale::codes())
        ->and($props['title'])->toBe(trans('console.users_6b0cc904', [], $locale));
})->with(HostedLocale::codes());

it('resolves regional tags for the additional hosted languages', function (string $header, string $expected): void {
    expect(i18nOf(signInPageIn($header))['locale'])->toBe($expected);
})->with([
    ['fi-FI', 'fi'], ['es-ES', 'es'], ['it-IT', 'it'], ['nl-NL', 'nl'], ['nl-BE', 'nl'],
]);
