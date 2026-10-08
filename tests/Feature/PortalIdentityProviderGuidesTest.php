<?php

declare(strict_types=1);

use App\Platform\Portal\IdpGuideDocs;
use App\Platform\Portal\PortalGuides;
use Cbox\Id\Federation\IdentityProviderGuides;
use Cbox\Id\Federation\ValueObjects\ServiceProviderValues;
use Illuminate\Support\Facades\App;

/*
|--------------------------------------------------------------------------
| The Admin Portal's guides are the framework's, in the visitor's language.
|--------------------------------------------------------------------------
|
| laravel-id 1.23's IdentityProviderGuides replaced the portal's own copy of eight guides:
| twenty identity providers, the SCIM half of nine, every field label read off the vendor's
| documentation. The portal keeps the step TEXT, translated per guide key, and falls back to
| the framework's English for a guide nobody has translated yet — and the IT-admin pages
| under docs/for-it-admins/idp are built from the same guides.
*/

it('offers every framework guide, and the generic SCIM one last', function (): void {
    expect(array_column(PortalGuides::sso(), 'key'))->toBe(IdentityProviderGuides::keys())
        ->and(PortalGuides::directoryKeys())->toBe([
            ...array_map(static fn ($guide): string => $guide->key, IdentityProviderGuides::directories()),
            PortalGuides::GENERIC_DIRECTORY,
        ]);
});

it('draws the labels the framework corrected upstream', function (): void {
    $sso = collect(PortalGuides::sso())->keyBy('key');
    $directories = collect(PortalGuides::directories())->keyBy('key');

    expect(array_column($sso['okta']['fields'], 'theirs'))->toContain('Application username format')
        ->not->toContain('Application username')
        ->and(collect($sso['google']['fields'])->firstWhere('theirs', 'Name ID format')['literal'])->toBe('Email')
        ->and($sso['google']['returns']['theirs'])->toBe('Download Metadata')
        ->and(collect($directories['jumpcloud']['fields'])->firstWhere('ours', 'scim_token')['theirs'])->toBe('Token');
});

it('has the steps translated for every guide in every language', function (string $locale): void {
    App::setLocale($locale);

    foreach ([...PortalGuides::sso(), ...PortalGuides::directories()] as $guide) {
        $kind = isset($guide['protocol']) ? 'sso' : 'directory';

        expect(trans("portal.guides.{$kind}.{$guide['key']}", [], $locale))->toBeArray("portal.guides.{$kind}.{$guide['key']} is not translated in {$locale}")
            ->and($guide['steps'])->not->toBe([]);
    }
})->with(['en', 'da', 'de', 'fr', 'nb', 'sv']);

it('falls back to the framework\'s English steps for a guide with no translation', function (): void {
    // The portal catalogue as it would be the day the framework adds a guide nobody has
    // translated yet: loaded, then that one guide's steps taken out.
    $translator = app('translator');
    trans('portal.guides', [], 'en');
    $loaded = new ReflectionProperty($translator, 'loaded');
    $lines = $loaded->getValue($translator);
    unset($lines['*']['portal']['en']['guides']['sso']['keycloak']);
    $loaded->setValue($translator, $lines);

    expect(collect(PortalGuides::sso())->firstWhere('key', 'keycloak')['steps'])
        ->toBe(IdentityProviderGuides::find('keycloak')?->setupSteps);
});

it('fills our values for every field a guide may ask for, the derived ones included', function (): void {
    $values = PortalGuides::values(new ServiceProviderValues(
        acsUrl: 'https://id.acme.test/sso/saml/c1/acs',
        entityId: 'https://id.acme.test/sso/saml/c1',
        sloUrl: 'https://id.acme.test/sso/saml/c1/slo',
        spMetadataUrl: 'https://id.acme.test/sso/saml/c1/metadata',
        loginUrl: 'https://id.acme.test/sso/saml/c1/login',
        scimBaseUrl: 'https://id.acme.test/scim/v2',
        scimToken: 'never-shown',
    ));

    expect($values)->toMatchArray([
        'acs_url' => 'https://id.acme.test/sso/saml/c1/acs',
        'acs_regex' => '^https\:\/\/id\.acme\.test\/sso\/saml\/c1\/acs$',
        'slo_url' => 'https://id.acme.test/sso/saml/c1/slo',
        'sp_metadata_url' => 'https://id.acme.test/sso/saml/c1/metadata',
        'login_url' => 'https://id.acme.test/sso/saml/c1/login',
        'scim_host' => 'id.acme.test',
        'scim_base_path' => '/scim/v2',
    ])->and($values)->not->toHaveKey('scim_token');
});

it('commits the identity provider pages the guides build', function (): void {
    $this->artisan('docs:idp-guides', ['--check' => true])->assertSuccessful();

    foreach (IdentityProviderGuides::all() as $guide) {
        expect(is_file(base_path(IdpGuideDocs::DIRECTORY.'/'.IdpGuideDocs::file($guide->key))))->toBeTrue("no page for {$guide->key}");
    }
});

it('fails the check when a generated page has drifted, and names it', function (): void {
    $path = base_path(IdpGuideDocs::DIRECTORY.'/keycloak.md');
    $committed = (string) file_get_contents($path);

    try {
        file_put_contents($path, $committed."\nA line nobody generated.\n");

        $this->artisan('docs:idp-guides', ['--check' => true])
            ->expectsOutputToContain('keycloak.md')
            ->assertFailed();
    } finally {
        file_put_contents($path, $committed);
    }
});
