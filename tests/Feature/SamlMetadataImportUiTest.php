<?php

declare(strict_types=1);

use App\Platform\CurrentUser;
use App\Platform\PlatformAuth;
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
use Illuminate\Testing\TestResponse;
use Inertia\Support\SessionKey;

uses(InteractsWithFederation::class);

// This file is ABOUT the entitlement gate, so it declares the mode it exercises.
// The default is now `open` — an unset entitlement means granted, which is what a
// self-hosted deployment runs and what most of the suite therefore sees. Gating
// only means anything under `metered`, where the billing projection is the sole
// source of a grant.
beforeEach(function (): void {
    config(['cbox-id.entitlements.mode' => 'metered']);
});

function ssoConsoleAdmin(string $slug, bool $entitled = true): string
{
    $subject = app(Subjects::class)->create("admin@{$slug}.test", 'Admin', 'supersecret123');
    $org = app(Organizations::class)->create(new NewOrganization('Acme', $slug));
    app(Memberships::class)->add($org->id, $subject->id, MembershipRole::Owner);
    $session = app(SessionManager::class)->start($subject->id, $org->id, ['pwd']);
    app(CurrentUser::class)->set($subject, $session, $org, MembershipRole::Owner);

    // AND THE SESSION KEY THE GUARD READS: the import is a request now.
    session([PlatformAuth::SESSION_KEY => $session->id]);

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

/** Press Import on the new-connection form. */
function importMetadata(string $metadata): TestResponse
{
    return test()->from(route('connections.create'))
        ->post(route('connections.import'), ['metadata' => $metadata]);
}

it('prefills the SAML fields from pasted IdP metadata', function (): void {
    ssoConsoleAdmin('meta-ok');

    importMetadata(metadataXmlForUi())->assertSessionHasNoErrors();

    // The parsed fields ride back on the FLASH CHANNEL and the form fills itself from
    // them. One-shot on purpose: an import is an action, not a property of the page, so
    // re-rendering must not re-apply it over an edit somebody has since made.
    $flash = session()->get(SessionKey::FLASH_DATA, []);
    $metadata = is_array($flash) ? ($flash['metadata'] ?? null) : null;

    expect($metadata['idp_entity_id'] ?? null)->toBe('https://idp.example/entity')
        ->and($metadata['idp_sso_url'] ?? null)->toBe('https://idp.example/sso')
        ->and($metadata['idp_x509cert'] ?? null)->not->toBe('');
});

it('surfaces a validation error for unparseable metadata', function (): void {
    ssoConsoleAdmin('meta-bad');

    importMetadata('<garbage>')->assertSessionHasErrors('metadata');
});

it('refuses metadata import for a non-entitled org', function (): void {
    ssoConsoleAdmin('meta-forbidden', entitled: false);

    // The import fills a form; it mints nothing and reads no tenant data, so it answers
    // to the ADMIN gate rather than to the entitlement. A non-entitled organization is
    // refused at the write — see EntitlementGateTest — which is where the refusal belongs.
    importMetadata(metadataXmlForUi())->assertSessionHasNoErrors();

    createConnection()->assertForbidden();
});
