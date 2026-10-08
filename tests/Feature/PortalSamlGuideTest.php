<?php

declare(strict_types=1);

use Cbox\Id\Federation\Validators\SamlAssertionValidator;

/*
|--------------------------------------------------------------------------
| The Admin Portal's SAML steps say what the validator actually requires.
|--------------------------------------------------------------------------
|
| The generic guide told an IT administrator to "sign the assertion or the response — both
| work" and to send the email as the NameID. The framework's validator wants the ASSERTION
| signed (a signed response around an unsigned assertion is refused) and reads the email
| only from an attribute, never from the NameID — so a provider set up exactly as the steps
| said signed people in with no email, or not at all. The copy now states both; this keeps
| the attribute it names one the validator reads.
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

it('tells the generic SAML reader the assertion must be signed and the NameID is not the email', function (): void {
    $steps = implode(' ', (array) __('portal.guides.sso.saml', [], 'en'));

    expect($steps)->toContain('attribute named email')
        ->toContain('the NameID is not read as the email address')
        ->toContain('Sign the assertion itself')
        ->not->toContain('Both work');

    // The other guides that leaned on the NameID alone now send the attribute too.
    expect(implode(' ', (array) __('portal.guides.sso.onelogin', [], 'en')))->toContain('add a parameter named email')
        ->and(implode(' ', (array) __('portal.guides.sso.pingfederate', [], 'en')))->toContain('Always sign the SAML Assertion');
});
