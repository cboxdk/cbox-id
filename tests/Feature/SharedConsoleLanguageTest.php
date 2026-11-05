<?php

declare(strict_types=1);

use App\Http\Middleware\ResolveConsoleLocale;
use App\Platform\EnvironmentAdminAuth;
use App\Platform\Locale\HostedLocale;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Kernel\Events\Models\Event;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\ValueObjects\NewClient;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Platform\PlatformRoot;
use Cbox\Id\UserProperties\Contracts\PropertyNamespaces;
use Cbox\Id\UserProperties\Contracts\UserProperties;
use Cbox\Id\UserProperties\Jobs\RelayPropertyChanges;
use Cbox\Id\UserProperties\Models\PropertyDocument;
use Cbox\Id\UserProperties\Models\PropertyNamespace;
use Cbox\Id\UserProperties\ValueObjects\PropertyPatch;
use Illuminate\Support\Facades\Queue;

it('publishes every console language to the document consuming apps read', function (string $locale): void {
    ['subjectId' => $person] = provisionAccount();
    signInAsMember($person);
    app(EnvironmentContext::class)->set(platformRootEnvironment());
    Queue::fake();
    $client = app(ClientRegistry::class)->register(new NewClient('Assistant', scopes: ['user_properties:read', 'user_properties:write']))->client;
    $namespace = app(PropertyNamespaces::class)->configure('preferences', [$client->client_id], [$client->client_id]);
    app(UserProperties::class)->patch($client->client_id, $person, 'preferences', new PropertyPatch(0, (object) ['theme' => 'dark', 'timezone' => 'Europe/Copenhagen']));
    $grants = $namespace->fresh()->getAttributes();

    $this->from(route('dashboard'))->post(route('console.locale.update'), ['locale' => $locale, 'user_id' => $person])
        ->assertRedirect(route('dashboard'))->assertCookieExpired(ResolveConsoleLocale::COOKIE);

    $document = app(UserProperties::class)->read($client->client_id, $person, 'preferences');
    expect($document->data)->toEqual((object) ['theme' => 'dark', 'timezone' => 'Europe/Copenhagen', 'language' => $locale])
        ->and($document->version)->toBe(2)
        ->and($namespace->fresh()->getAttributes())->toBe($grants);
    $event = Event::query()->where('type', 'user.preferences.updated')->orderByDesc('id')->firstOrFail();
    expect($event->payload['user_id'])->toBe($person)
        ->and($event->payload['version'])->toBe(2)
        ->and($event->payload['changed_keys'])->toBe(['language'])
        ->and($event->payload['client_id'])->toBeNull()
        ->and($event->payload)->not->toHaveKey('data');
    Queue::assertPushed(RelayPropertyChanges::class);

    $page = $this->withCookie(ResolveConsoleLocale::COOKIE, 'ja')->withHeader('Accept-Language', 'ja')->get(route('dashboard'));
    $page->assertOk()->assertHeader('Content-Language', $locale);
    expect($page->inertiaProps('i18n.locale'))->toBe($locale);

    $this->post(route('console.locale.update'), ['locale' => $locale, 'user_id' => $person])->assertRedirect();
    expect(app(UserProperties::class)->read($client->client_id, $person, 'preferences')->version)->toBe(2)
        ->and(Event::query()->where('type', 'user.preferences.updated')->count())->toBe(2);
})->with(HostedLocale::codes());

it('reads a shared regional preference ahead of cookies without rewriting it', function (string $preference, string $expected): void {
    ['subjectId' => $person] = provisionAccount();
    signInAsMember($person);
    app(EnvironmentContext::class)->set(platformRootEnvironment());
    PropertyDocument::query()->create(['user_id' => $person, 'namespace' => 'preferences', 'data' => (object) ['language' => $preference], 'version' => 7]);

    $page = $this->withCookie(ResolveConsoleLocale::COOKIE, 'it')->withHeader('Accept-Language', 'de')->get(route('dashboard'));
    $page->assertOk()->assertHeader('Content-Language', $expected);
    expect($page->inertiaProps('i18n.locale'))->toBe($expected)
        ->and(PropertyDocument::query()->sole()->data->language)->toBe($preference)
        ->and(PropertyDocument::query()->sole()->version)->toBe(7)
        ->and(Event::query()->where('type', 'user.preferences.updated')->count())->toBe(0);
})->with([['da-DK', 'da'], ['no', 'nb'], ['fr-CA', 'fr'], ['ja', 'en']]);

it('does not seed defaults or reuse the previous accounts cookie', function (): void {
    ['subjectId' => $person] = provisionAccount();
    signInAsMember($person);
    app(EnvironmentContext::class)->set(platformRootEnvironment());
    $this->withCookie(ResolveConsoleLocale::COOKIE, 'de')->withHeader('Accept-Language', 'sv')->get(route('dashboard'))
        ->assertOk()->assertHeader('Content-Language', 'sv');
    expect(PropertyDocument::query()->count())->toBe(0);
});

it('refuses a stale tab writing after the signed in account changed', function (): void {
    ['subjectId' => $first] = provisionAccount('first@example.test');
    ['subjectId' => $second] = provisionAccount('second@example.test');
    signInAsMember($second);

    $this->postJson(route('console.locale.update'), ['locale' => 'de', 'user_id' => $first])->assertConflict();
    expect(PropertyDocument::query()->count())->toBe(0);
});

it('does not accept a guest or revoked session as a preference writer', function (): void {
    ['subjectId' => $person] = provisionAccount();
    $this->postJson(route('console.locale.update'), ['locale' => 'de', 'user_id' => $person])->assertUnauthorized();
    signInAsMember($person);
    app(EnvironmentContext::class)->set(platformRootEnvironment());
    app(SessionManager::class)->revokeAllForUser($person);
    $this->postJson(route('console.locale.update'), ['locale' => 'de', 'user_id' => $person])->assertUnauthorized();
    expect(PropertyDocument::query()->count())->toBe(0);
});

it('stores an environment administrators language on their own root identity', function (): void {
    $tenant = actAsEnvironmentAdminOfATenant();
    $person = app(EnvironmentAdminAuth::class)->subjectId();
    Queue::fake();
    $this->from(route('environment.home'))->post(route('environment.locale.update'), ['locale' => 'fi', 'user_id' => $person])->assertRedirect(route('environment.home'));
    expect(app(EnvironmentContext::class)->current()?->environmentKey())->toBe($tenant)
        ->and(PropertyDocument::query()->count())->toBe(0)
        ->and(PropertyNamespace::query()->count())->toBe(0);
    app(PlatformRoot::class)->run(function () use ($person): void {
        expect(PropertyDocument::query()->sole()->user_id)->toBe($person)
            ->and(PropertyDocument::query()->sole()->data->language)->toBe('fi');
    });
    $this->get(route('environment.users'))->assertOk()->assertHeader('Content-Language', 'fi');
});

it('reads the operators own language while administering another environment', function (): void {
    $operator = actAsOperator();
    app(PlatformRoot::class)->run(function () use ($operator): void {
        PropertyDocument::query()->create([
            'user_id' => $operator->subject_id, 'namespace' => 'preferences',
            'data' => (object) ['language' => 'de'], 'version' => 1,
        ]);
    });

    $tenant = Environment::query()->create([
        'name' => 'Tenant', 'slug' => 'language-tenant', 'type' => 'production',
        'status' => 'active', 'is_default' => false, 'settings' => [],
    ]);
    targetEnvironment($tenant->slug);
    $this->get(route('platform.organizations'))->assertOk()->assertHeader('Content-Language', 'de');
    expect(app(EnvironmentContext::class)->current()?->environmentKey())->toBe($tenant->id)
        ->and(PropertyDocument::query()->count())->toBe(0);
    expect(app(PlatformRoot::class)->run(fn (): string => PropertyDocument::query()->sole()->data->language))->toBe('de');
});
