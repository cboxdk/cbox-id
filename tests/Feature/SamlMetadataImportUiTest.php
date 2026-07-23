<?php

declare(strict_types=1);

use App\Platform\CurrentUser;
use Cbox\Id\Federation\Testing\InteractsWithFederation;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Kernel\Authorization\Contracts\EntitlementWriter;
use Cbox\Id\Kernel\Authorization\Enums\EntitlementSource;
use Cbox\Id\Kernel\Authorization\ValueObjects\EntitlementInput;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Livewire\Volt\Volt;

uses(InteractsWithFederation::class);

function ssoConsoleAdmin(string $slug, bool $entitled = true): string
{
    $subject = app(Subjects::class)->create("admin@{$slug}.test", 'Admin', 'supersecret123');
    $org = app(Organizations::class)->create(new NewOrganization('Acme', $slug));
    app(Memberships::class)->add($org->id, $subject->id, 'owner');
    $session = app(SessionManager::class)->start($subject->id, $org->id, ['pwd']);
    app(CurrentUser::class)->set($subject, $session, $org, MembershipRole::Owner);

    if ($entitled) {
        app(EntitlementWriter::class)->set(
            $org->id,
            new EntitlementInput('cbox-id-sso', ['enabled' => true]),
            EntitlementSource::Manual,
        );
    }

    return $org->id;
}

function metadataXmlForUi(): string
{
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    $csr = openssl_csr_new(['commonName' => 'idp.example'], $key);
    $x509 = openssl_csr_sign($csr, null, $key, 1);
    openssl_x509_export($x509, $pem);
    $cert = preg_replace('/-----(BEGIN|END) CERTIFICATE-----|\s+/', '', $pem);

    return '<md:EntityDescriptor xmlns:md="urn:oasis:names:tc:SAML:2.0:metadata" entityID="https://idp.example/entity">'
        .'<md:IDPSSODescriptor protocolSupportEnumeration="urn:oasis:names:tc:SAML:2.0:protocol">'
        .'<md:KeyDescriptor use="signing"><ds:KeyInfo xmlns:ds="http://www.w3.org/2000/09/xmldsig#">'
        ."<ds:X509Data><ds:X509Certificate>{$cert}</ds:X509Certificate></ds:X509Data></ds:KeyInfo></md:KeyDescriptor>"
        .'<md:SingleSignOnService Binding="urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect" Location="https://idp.example/sso"/>'
        .'</md:IDPSSODescriptor></md:EntityDescriptor>';
}

it('prefills the SAML fields from pasted IdP metadata', function (): void {
    ssoConsoleAdmin('meta-ok');

    $component = Volt::test('connections')
        ->set('creating', true)
        ->set('type', 'saml')
        ->set('metadataInput', metadataXmlForUi())
        ->call('importMetadata')
        ->assertHasNoErrors();

    expect($component->get('idp_entity_id'))->toBe('https://idp.example/entity')
        ->and($component->get('idp_sso_url'))->toBe('https://idp.example/sso')
        ->and($component->get('idp_x509cert'))->not->toBe('')
        // The input is cleared after a successful import.
        ->and($component->get('metadataInput'))->toBe('');
});

it('surfaces a validation error for unparseable metadata', function (): void {
    ssoConsoleAdmin('meta-bad');

    Volt::test('connections')
        ->set('creating', true)
        ->set('metadataInput', '<garbage>')
        ->call('importMetadata')
        ->assertHasErrors('metadataInput');
});

it('refuses metadata import for a non-entitled org', function (): void {
    ssoConsoleAdmin('meta-forbidden', entitled: false);

    Volt::test('connections')
        ->set('creating', true)
        ->set('metadataInput', metadataXmlForUi())
        ->call('importMetadata')
        ->assertForbidden();
});
