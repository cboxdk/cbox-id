<?php

declare(strict_types=1);

use App\Platform\EnvironmentAdminAuth;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Platform\PlatformRoot;
use Cbox\Id\UserProperties\Contracts\UserPreferences;
use Cbox\Id\UserProperties\Models\PropertyDocument;
use Cbox\Id\UserProperties\ValueObjects\PropertyPatch;

it('uses the shared preference ahead of browser and hosted authorization choices', function (): void {
    [$person] = actingAsRole(MembershipRole::Owner);
    app(UserPreferences::class)->patch($person, new PropertyPatch(0, (object) ['language' => 'da', 'theme' => 'dark']));
    $response = $this->withHeader('Accept-Language', 'de')->get('/dashboard?ui_locales=fr')->assertOk();
    $props = $response->inertiaProps()['i18n'];
    expect($props['locale'])->toBe('da')->and($props['locales'])->toHaveCount(21)
        ->and($props['preference']['subjectId'])->toBe($person)
        ->and($props['preference']['version'])->toBe(1);
    $response->assertHeader('Content-Language', 'da');
    $this->post('/interface-language', ['subject_id' => $person, 'version' => 1, 'language' => 'fr'])->assertStatus(303);
    $snapshot = app(UserPreferences::class)->read($person);
    expect($snapshot->data->language)->toBe('fr')->and($snapshot->data->theme)->toBe('dark');
    $this->get('/dashboard')->assertHeader('Content-Language', 'fr');
});

it('refuses stale tabs and invalid languages without overwriting the preference', function (): void {
    [$person] = actingAsRole(MembershipRole::Owner);
    app(UserPreferences::class)->patch($person, new PropertyPatch(0, (object) ['language' => 'da']));
    $this->post('/interface-language', ['subject_id' => $person, 'version' => 0, 'language' => 'fr'])->assertSessionHasErrors('language');
    $this->post('/interface-language', ['subject_id' => 'another-person', 'version' => 1, 'language' => 'fr'])->assertSessionHasErrors('language');
    $this->post('/interface-language', ['subject_id' => $person, 'version' => 1, 'language' => 'xx'])->assertSessionHasErrors('language');
    expect(app(UserPreferences::class)->read($person)->data->language)->toBe('da');
});

it('stores an environment administrator preference in the actors home environment', function (): void {
    multiTenantDeployment();
    $tenant = actAsEnvironmentAdminOfATenant();
    $before = app(EnvironmentContext::class)->requireEnvironment()->environmentKey();
    $response = $this->get(route('environment.home'))->assertOk();
    $preference = $response->inertiaProps()['i18n']['preference'];
    $this->post($preference['updateUrl'], ['subject_id' => $preference['subjectId'], 'version' => $preference['version'], 'language' => 'pl'])->assertStatus(303);
    expect(app(EnvironmentContext::class)->requireEnvironment()->environmentKey())->toBe($before);
    expect(PropertyDocument::query()->where('user_id', $preference['subjectId'])->count())->toBe(0);
    $this->get(route('environment.home'))->assertHeader('Content-Language', 'pl');
});

it('does not accept a preference write from an unauthenticated visitor', function (): void {
    installedDeployment();
    $this->post('/interface-language', ['subject_id' => 'somebody', 'version' => 0, 'language' => 'da'])->assertRedirect();
    expect(PropertyDocument::query()->count())->toBe(0);
});

it('keeps one preference per person: the environment console offers the signed-in administrator\'s own', function (): void {
    multiTenantDeployment();
    actAsEnvironmentAdminOfATenant();
    $admin = (string) app(EnvironmentAdminAuth::class)->subjectId();

    // One session on the app host now serves both consoles, so the preference the
    // environment console writes is the same person's the organization console reads —
    // and never a tenant subject's of the environment being administered.
    $response = $this->get(route('environment.home'))->assertOk();
    $preference = $response->inertiaProps()['i18n']['preference'];

    expect($admin)->not->toBe('')
        ->and($preference['subjectId'])->toBe($admin);

    $this->post($preference['updateUrl'], ['subject_id' => $admin, 'version' => $preference['version'], 'language' => 'fi'])->assertStatus(303);
    expect(app(PlatformRoot::class)->run(fn () => app(UserPreferences::class)->read($admin)->data->language))->toBe('fi');

    // Somebody else's id is not a preference this session may write.
    $this->post($preference['updateUrl'], ['subject_id' => 'another-person', 'version' => 1, 'language' => 'da'])->assertSessionHasErrors('language');
});

it('localizes radar list filter labels while preserving stable filter values', function (): void {
    multiTenantDeployment();
    actAsEnvironmentAdminOfATenant();
    $response = $this->get(route('environment.home'))->assertOk();
    $preference = $response->inertiaProps()['i18n']['preference'];
    $this->post($preference['updateUrl'], ['subject_id' => $preference['subjectId'], 'version' => $preference['version'], 'language' => 'da'])->assertStatus(303);
    $response = $this->get(route('environment.radar'))->assertOk();
    $options = collect($response->inertiaProps()['ruleOptions'])->keyBy('value');
    expect($options['allow_list:email']['label'])->toBe('Tilladelsesliste · E-mailadresser')
        ->and($options['deny_list:email_domain']['label'])->toBe('Blokeringsliste · E-maildomæner');
});
