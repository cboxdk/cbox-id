<?php

declare(strict_types=1);

use Cbox\Id\Federation\Validators\SamlAssertionValidator;

/*
|--------------------------------------------------------------------------
| The Admin Portal's SAML steps say what the validator actually requires.
|--------------------------------------------------------------------------
|
| The generic guide once told an IT administrator to "sign the assertion or the response —
| both work". The framework's validator wants the ASSERTION signed (a signed response around
| an unsigned assertion is refused). Since laravel-id 1.23 it reads the email from an email
| attribute OR, when none is sent, from a NameID in the `emailAddress` format — the default
| for Okta and Google Workspace — and the copy says exactly that: either one, the attribute
| winning when both arrive. This keeps the attribute it names one the validator reads, and
| the copy from going back to "the NameID is not read".
*/

/** The attribute names the framework's SAML validator reads an email from. */
function samlEmailClaims(): array
{
    $claims = (new ReflectionClass(SamlAssertionValidator::class))->getConstant('EMAIL_CLAIMS');

    return is_array($claims) ? $claims : [];
}

it('names an email attribute the validator reads, in every language and every guide that sends one', function (string $locale): void {
    expect(samlEmailClaims())->toContain('email');

    foreach (['saml', 'onelogin', 'pingfederate'] as $provider) {
        $steps = (array) __('portal.guides.sso.'.$provider, [], $locale);

        expect(collect($steps)->contains(fn (mixed $step): bool => is_string($step) && preg_match('/\bemail\b/', $step) === 1))
            ->toBeTrue("portal.guides.sso.{$provider} ({$locale}) never names the email attribute");
    }
})->with(['en', 'da', 'de', 'fr', 'nb', 'sv']);

it('tells the generic SAML reader the assertion must be signed, and the email is an attribute or an emailAddress NameID', function (): void {
    $steps = implode(' ', (array) __('portal.guides.sso.saml', [], 'en'));

    expect($steps)->toContain('attribute named email')
        ->toContain('NameID in the emailAddress format')
        ->toContain('Sign the assertion itself')
        ->not->toContain('is not read as the email address')
        ->not->toContain('Both work');

    // OneLogin's nameID format Email is enough on its own now; the attribute is optional.
    expect(implode(' ', (array) __('portal.guides.sso.onelogin', [], 'en')))->toContain('add a parameter named email')
        ->not->toContain('is not read as the email address')
        ->and(implode(' ', (array) __('portal.guides.sso.pingfederate', [], 'en')))->toContain('Always sign the SAML Assertion');
});

it('says nowhere, in any language, that an emailAddress NameID is ignored', function (string $locale): void {
    $lines = collect((array) __('portal.guides.sso', [], $locale))->flatten()->implode(' ');

    expect($lines)->not->toContain('is not read as the email address')
        ->not->toContain('bliver ikke læst som e-mailadressen')
        ->not->toContain('nicht als E-Mail-Adresse gelesen')
        ->not->toContain('n’est pas lu comme adresse e-mail')
        ->not->toContain('blir ikke lest som e-postadressen')
        ->not->toContain('läses inte som e-postadressen');
})->with(['en', 'da', 'de', 'fr', 'nb', 'sv']);
