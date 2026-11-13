<?php

declare(strict_types=1);

use App\Platform\PlatformAuth;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Cbox\Id\SamlIdp\Enums\NameIdFormat;
use Cbox\Id\SamlIdp\Models\SamlIdpSession;
use Cbox\Id\SamlIdp\Testing\InteractsWithSamlIdp;
use Illuminate\Testing\TestResponse;

uses(InteractsWithSamlIdp::class);

/**
 * THE IDP NEVER ASSERTS AN EMAIL ADDRESS NOBODY PROVED.
 *
 * A SAML assertion is a signed statement relying parties act on, and most SPs key their
 * accounts by the email in the NameID or the `email` attribute. An address the person
 * typed at sign-up but never confirmed is a claim, not an identity — signing it would
 * vouch for an address the person may not own.
 */
function unverifiedSamlSession(string $email, bool $verified = false): string
{
    $subjects = app(Subjects::class);
    $subject = $subjects->create($email, 'Unverified Person', 'supersecret123');

    if ($verified) {
        $subjects->markEmailVerified($subject->id, $email);
    }

    $org = app(Organizations::class)->create(new NewOrganization('Acme', 'unv-'.substr(md5($email), 0, 8)));
    app(Memberships::class)->add($org->id, $subject->id, MembershipRole::Owner);

    return app(SessionManager::class)->start($subject->id, $org->id, ['pwd'])->id;
}

function unverifiedSamlSso(string $sessionId, string $entityId, ?string $nameIdFormat = null): TestResponse
{
    return test()->withSession([PlatformAuth::SESSION_KEY => $sessionId])
        ->get('/sso/saml/idp/sso?'.http_build_query([
            'SAMLRequest' => test()->makeRedirectAuthnRequest($entityId, nameIdFormat: $nameIdFormat),
        ]));
}

function samlXmlFrom(TestResponse $response): string
{
    preg_match('/name="SAMLResponse" value="([^"]+)"/', (string) $response->getContent(), $m);

    return base64_decode($m[1] ?? '', true) ?: '';
}

it('refuses to assert an unverified email as the NameID', function (): void {
    $sp = $this->registerSamlServiceProvider(
        attributeMappings: ['displayName' => 'name'],
        nameIdFormat: NameIdFormat::EmailAddress,
        nameIdAttribute: 'email',
    );

    $response = unverifiedSamlSso(unverifiedSamlSession('claimed@victim.test'), $sp->entity_id, NameIdFormat::EmailAddress->value);

    $response->assertForbidden();
    $response->assertDontSee('name="SAMLResponse"', false);
    $response->assertDontSee('claimed@victim.test', false);
    expect(SamlIdpSession::query()->count())->toBe(0);
});

it('refuses to release an unverified email as an attribute', function (): void {
    $sp = $this->registerSamlServiceProvider(
        attributeMappings: ['mail' => 'email'],
        nameIdFormat: NameIdFormat::Persistent,
    );

    $response = unverifiedSamlSso(unverifiedSamlSession('claimed2@victim.test'), $sp->entity_id);

    $response->assertForbidden();
    $response->assertDontSee('name="SAMLResponse"', false);
    $response->assertDontSee('claimed2@victim.test', false);
});

it('still signs in an unverified person to an SP that never sees the email', function (): void {
    $sp = $this->registerSamlServiceProvider(
        attributeMappings: ['displayName' => 'name'],
        nameIdFormat: NameIdFormat::Persistent,
    );

    $response = unverifiedSamlSso(unverifiedSamlSession('quiet@victim.test'), $sp->entity_id);

    $response->assertOk();
    expect(samlXmlFrom($response))
        ->toContain('Unverified Person')
        ->not->toContain('quiet@victim.test');
});

it('asserts the email once it is verified', function (): void {
    $sp = $this->registerSamlServiceProvider();

    $response = unverifiedSamlSso(unverifiedSamlSession('owner@verified.test', verified: true), $sp->entity_id, NameIdFormat::EmailAddress->value);

    $response->assertOk();
    expect(samlXmlFrom($response))->toContain('owner@verified.test');
});
