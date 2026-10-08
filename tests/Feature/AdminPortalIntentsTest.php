<?php

declare(strict_types=1);

use App\Actions\Organizations\AddOrganizationDomain;
use App\Actions\Organizations\DeleteOrganization;
use App\Mail\PortalLinkMail;
use App\Models\AdminPortalLink;
use App\Platform\Actions\ActionRefused;
use App\Platform\Actions\ActionRunner;
use App\Platform\Actions\ActionTrail;
use App\Platform\Actions\ActionVia;
use App\Platform\Actions\Principal\PortalPrincipal;
use App\Platform\AdminPortal;
use App\Platform\Enums\PortalIntent;
use App\Platform\Enums\PortalScope;
use App\Platform\Sso\SamlCertificate;
use Cbox\Id\AuditStreaming\Models\AuditStream;
use Cbox\Id\Directory\Enums\DirectoryProvider;
use Cbox\Id\Directory\Enums\DirectoryStatus;
use Cbox\Id\Directory\Models\Directory;
use Cbox\Id\Federation\Contracts\Connections;
use Cbox\Id\Federation\Contracts\DomainVerification;
use Cbox\Id\Federation\Enums\ConnectionStatus;
use Cbox\Id\Federation\Enums\ConnectionType;
use Cbox\Id\Federation\Models\Connection;
use Cbox\Id\Federation\Models\VerifiedDomain;
use Cbox\Id\Federation\Testing\InteractsWithFederation;
use Cbox\Id\Kernel\Audit\Enums\ActorType;
use Cbox\Id\Kernel\Audit\Models\AuditEntry;
use Cbox\Id\Kernel\Authorization\Contracts\EntitlementWriter;
use Cbox\Id\Kernel\Authorization\Enums\EntitlementSource;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Cbox\Id\Platform\Contracts\EnvironmentApiKeys;
use Cbox\LaravelSiem\Testing\FakeStreamSink;
use Cbox\Siem\Contracts\StreamSink;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/*
|--------------------------------------------------------------------------
| THE ADMIN PORTAL, WIDENED: a link carries a SET of intents, and every write the portal
| makes is an action run as the portal session's own principal.
|--------------------------------------------------------------------------
|
| Each flow is walked end to end the way the customer's IT administrator walks it — through
| the portal's own routes, with nothing but the portal session — and the refusals are asked
| of the principal directly too, because a route is only one door to an action.
*/

uses(InteractsWithFederation::class);

beforeEach(function (): void {
    installedDeployment();
});

function intentOrg(string $slug = 'portal-intents'): string
{
    return app(Organizations::class)->create(new NewOrganization('Acme', $slug.'-'.Str::lower(Str::random(4))))->id;
}

/**
 * Open a portal session for $intents on $organizationId, as redeeming the link does.
 *
 * @param  list<PortalIntent>  $intents
 */
function openPortal(string $organizationId, array $intents, string $minter = 'sub_minter'): AdminPortalLink
{
    $token = app(AdminPortal::class)->generate($organizationId, PortalScope::of($intents), $minter);
    $link = app(AdminPortal::class)->redeem($token);

    expect($link)->not->toBeNull();

    return $link;
}

/** A POST/PATCH/DELETE from a portal page, as the page's form sends it. */
function portalWrite(string $method, string $from, string $url, array $data = []): TestResponse
{
    return inertiaRequest(fn (): TestResponse => test()->from($from)->{$method}($url, $data));
}

/** A real, self-signed X.509 certificate valid for $days from now. */
function portalCertificate(int $days, string $commonName = 'idp.acme.example'): string
{
    static $key = null;
    $key ??= openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);

    $csr = openssl_csr_new(['commonName' => $commonName], $key);
    $x509 = openssl_csr_sign($csr, null, $key, $days, [], random_int(1, PHP_INT_MAX));
    openssl_x509_export($x509, $pem);

    return $pem;
}

function portalMetadata(string $entityId, string $pem): string
{
    $body = preg_replace('/-----(BEGIN|END) CERTIFICATE-----|\s+/', '', $pem);

    return '<md:EntityDescriptor xmlns:md="urn:oasis:names:tc:SAML:2.0:metadata" entityID="'.$entityId.'">'
        .'<md:IDPSSODescriptor protocolSupportEnumeration="urn:oasis:names:tc:SAML:2.0:protocol">'
        .'<md:KeyDescriptor use="signing"><ds:KeyInfo xmlns:ds="http://www.w3.org/2000/09/xmldsig#">'
        ."<ds:X509Data><ds:X509Certificate>{$body}</ds:X509Certificate></ds:X509Data></ds:KeyInfo></md:KeyDescriptor>"
        .'<md:SingleSignOnService Binding="urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect" Location="https://idp.acme.example/sso"/>'
        .'</md:IDPSSODescriptor></md:EntityDescriptor>';
}

/** An active SAML connection whose IdP certificate expires in $days. */
function samlConnectionExpiring(string $organizationId, int $days, string $name = 'Okta'): Connection
{
    $connection = app(Connections::class)->create($organizationId, ConnectionType::Saml, $name, [
        'idp_entity_id' => 'https://idp.acme.example/entity',
        'idp_sso_url' => 'https://idp.acme.example/sso',
        'idp_x509cert' => portalCertificate($days),
        'sp_entity_id' => 'https://sp.acme.example',
        'sp_acs_url' => 'https://sp.acme.example/acs',
    ]);

    app(Connections::class)->activate($organizationId, $connection->id);

    return $connection->refresh();
}

/** @return array{0: string, 1: string} plaintext key, key id */
function intentKey(array $scopes): array
{
    $issued = app(EnvironmentApiKeys::class)->issue('env_test', 'Portal worker', $scopes);

    return [$issued->plaintext, (string) $issued->key->id];
}

// ── Minting a link with intents ─────────────────────────────────────────────

it('mints a link over REST with intents, a lifetime of its own, and mails it in the chosen language', function (): void {
    Mail::fake();
    [$key, $keyId] = intentKey(['portal_links:write']);
    $org = intentOrg();

    $response = $this->withToken($key)->postJson("/api/v1/organizations/{$org}/portal-links", [
        'intents' => ['log_streams', 'sso', 'domain_verification'],
        'expires_in_minutes' => 1440,
        'email' => 'it@acme.example',
        'locale' => 'da',
    ])->assertCreated()
        // In display order, whatever order they were sent in.
        ->assertJsonPath('data.intents', ['sso', 'domain_verification', 'log_streams'])
        ->assertJsonPath('data.emailed_to', 'it@acme.example');

    $link = AdminPortalLink::query()->findOrFail($response->json('data.id'));

    expect($link->intents)->toBe(['sso', 'domain_verification', 'log_streams'])
        ->and($link->emailed_to)->toBe('it@acme.example')
        ->and((int) round(now()->diffInMinutes($link->expires_at)))->toBe(1440);

    Mail::assertSent(PortalLinkMail::class, fn (PortalLinkMail $mail): bool => $mail->hasTo('it@acme.example')
        && $mail->locale === 'da'
        && $mail->url === $response->json('data.url')
        && $mail->intents === ['sso', 'domain_verification', 'log_streams']);

    $entry = AuditEntry::query()->where('action', 'portal_link.created')->sole();

    expect($entry->actor_type)->toBe(ActorType::Service)
        ->and($entry->actor_id)->toBe($keyId)
        ->and($entry->context['intents'])->toBe(['sso', 'domain_verification', 'log_streams'])
        ->and($entry->context['emailed_to'])->toBe('it@acme.example');
});

it('refuses an empty, unknown or out-of-range link request, and the old `covers` field', function (): void {
    [$key] = intentKey(['portal_links:write']);
    $org = intentOrg();

    $this->withToken($key)->postJson("/api/v1/organizations/{$org}/portal-links", ['intents' => []])->assertUnprocessable();
    $this->withToken($key)->postJson("/api/v1/organizations/{$org}/portal-links", ['intents' => ['scim']])->assertUnprocessable();
    $this->withToken($key)->postJson("/api/v1/organizations/{$org}/portal-links", ['covers' => 'sso'])->assertUnprocessable();
    $this->withToken($key)->postJson("/api/v1/organizations/{$org}/portal-links", ['intents' => ['sso'], 'expires_in_minutes' => 20000])->assertUnprocessable();
    $this->withToken($key)->postJson("/api/v1/organizations/{$org}/portal-links", ['intents' => ['sso'], 'email' => 'not-an-address'])->assertUnprocessable();

    expect(AdminPortalLink::query()->count())->toBe(0);
});

it('refuses a link covering an intent the plan lacks, but not one no plan gates', function (): void {
    config(['cbox-id.entitlements.mode' => 'metered']);
    [$key] = intentKey(['portal_links:write']);
    $org = intentOrg();

    $this->withToken($key)->postJson("/api/v1/organizations/{$org}/portal-links", ['intents' => ['domain_verification', 'certificate_renewal']])
        ->assertForbidden()->assertJsonPath('error', 'not_entitled');

    // Domain verification and log streams are every organization's.
    $this->withToken($key)->postJson("/api/v1/organizations/{$org}/portal-links", ['intents' => ['domain_verification', 'log_streams']])
        ->assertCreated();
});

it('mints a link over MCP with the same intents', function (): void {
    [$key] = intentKey(['portal_links:write']);
    $org = intentOrg();

    $result = mcpCall($key, 'organizations_portal_links_create', ['organization_id' => $org, 'intents' => ['dsync', 'certificate_renewal']]);

    expect($result['isError'] ?? false)->toBeFalse()
        ->and(AdminPortalLink::query()->where('organization_id', $org)->sole()->intents)->toBe(['dsync', 'certificate_renewal'])
        ->and(AuditEntry::query()->where('action', 'portal_link.created')->sole()->context[ActionTrail::VIA])->toBe('mcp');
});

it('renders the link mail in the recipient\'s language', function (): void {
    $mail = new PortalLinkMail('Acme', 'https://id.example/setup/abc', ['sso', 'log_streams'], now()->addDay()->toImmutable());
    $mail->locale('de');

    $html = $mail->render();

    expect($html)->toContain('https://id.example/setup/abc')
        ->and($html)->toContain(trans('mail.portal_link.intents.sso', [], 'de'))
        ->and($html)->toContain(trans('mail.portal_link.button', [], 'de'));
});

// ── The principal ───────────────────────────────────────────────────────────

it('confines a portal principal to its intents and to its organization, whatever the door', function (): void {
    $mine = intentOrg('portal-mine');
    $theirs = intentOrg('portal-theirs');
    $runner = app(ActionRunner::class);

    $dsyncOnly = new PortalPrincipal('link_1', $mine, PortalScope::only(PortalIntent::Dsync), 'sub_minter');
    $domains = new PortalPrincipal('link_2', $mine, PortalScope::only(PortalIntent::DomainVerification), 'sub_minter');

    // An intent that does not list the action: refused before anything runs.
    expect(fn () => $runner->run(AddOrganizationDomain::class, $dsyncOnly, ['organization_id' => $mine, 'domain' => 'acme.com']))
        ->toThrow(AuthorizationException::class);

    // An action no intent lists at all — deleting the organization.
    expect(fn () => $runner->run(DeleteOrganization::class, $domains, ['organization_id' => $mine]))
        ->toThrow(AuthorizationException::class);

    // The right intent, the wrong organization: not found, as an unknown one would be.
    expect(fn () => $runner->run(AddOrganizationDomain::class, $domains, ['organization_id' => $theirs, 'domain' => 'acme.com']))
        ->toThrow(ActionRefused::class, 'Organization not found.');

    expect(VerifiedDomain::query()->count())->toBe(0);

    // The right intent and its own organization: allowed, named as the portal session.
    $runner->run(AddOrganizationDomain::class, $domains, ['organization_id' => $mine, 'domain' => 'acme.com'], via: ActionVia::Portal);

    $entry = AuditEntry::query()->where('action', 'domain.added')->latest('sequence')->first();

    expect(VerifiedDomain::query()->where('organization_id', $mine)->exists())->toBeTrue()
        ->and($entry?->context[ActionTrail::VIA] ?? null)->toBe('portal')
        ->and($entry?->context[PortalPrincipal::LINK] ?? null)->toBe('link_2')
        ->and($entry?->context[PortalPrincipal::CREATED_BY] ?? null)->toBe('sub_minter');
})->group('security');

it('drops an intent the plan stopped including from a live session', function (): void {
    config(['cbox-id.entitlements.mode' => 'metered']);
    $org = intentOrg();
    grantFeature($org, 'cbox-id-sso');

    openPortal($org, [PortalIntent::Sso, PortalIntent::DomainVerification]);

    $this->get(route('portal.sso'))->assertOk();

    app(EntitlementWriter::class)
        ->revoke($org, 'cbox-id-sso', EntitlementSource::Manual);

    // The session lives on what is still usable — and only that.
    $this->get(route('portal.sso'))->assertNotFound();
    $this->get(route('portal.domains'))->assertOk();
    expect(app(AdminPortal::class)->principal()?->scope()->values())->toBe(['domain_verification']);
});

it('404s every page and write of an intent the link does not cover', function (): void {
    $org = intentOrg();
    openPortal($org, [PortalIntent::LogStreams]);

    $this->get(route('portal.domains'))->assertNotFound();
    $this->get(route('portal.sso'))->assertNotFound();
    $this->get(route('portal.directories'))->assertNotFound();
    $this->get(route('portal.certificates'))->assertNotFound();
    portalWrite('post', route('portal.setup'), route('portal.domains.store'), ['domain' => 'acme.com'])->assertNotFound();
    portalWrite('post', route('portal.setup'), route('portal.directories.store'), ['name' => 'Okta'])->assertNotFound();

    expect(VerifiedDomain::query()->count())->toBe(0)
        ->and(Directory::query()->count())->toBe(0);
})->group('security');

// ── The checklist ───────────────────────────────────────────────────────────

it('shows the link\'s intents as a checklist whose progress is read from the system', function (): void {
    $org = intentOrg();
    openPortal($org, [PortalIntent::DomainVerification, PortalIntent::LogStreams]);

    $tasks = $this->get(route('portal.setup'))->assertOk()->inertiaProps('tasks');

    expect(array_column($tasks, 'intent'))->toBe(['domain_verification', 'log_streams'])
        ->and($tasks[0]['started'])->toBeFalse()
        ->and($tasks[0]['href'])->toBe(route('portal.domains'));

    $domain = app(DomainVerification::class)->add($org, 'acme.com');

    $tasks = $this->get(route('portal.setup'))->inertiaProps('tasks');
    expect($tasks[0]['steps'])->toBe([['key' => 'domain_added', 'done' => true], ['key' => 'domain_verified', 'done' => false]])
        ->and($tasks[0]['done'])->toBeFalse();

    VerifiedDomain::query()->whereKey($domain->id)->update(['verified_at' => now()]);

    expect($this->get(route('portal.setup'))->inertiaProps('tasks')[0]['done'])->toBeTrue();
});

/*
 * "FIRST UPDATE RECEIVED" FOR BOTH KINDS OF DIRECTORY. The step waited on
 * `last_synced_at`, which only the pull job stamps, so a SCIM directory — the kind the
 * portal walks people through — never completed however many people it pushed.
 */
it('completes directory sync when a SCIM push lands, and when a pull directory syncs', function (): void {
    $org = intentOrg();
    openPortal($org, [PortalIntent::Dsync]);

    $steps = fn (): array => $this->get(route('portal.setup'))->assertOk()->inertiaProps('tasks')[0]['steps'];

    portalWrite('post', route('portal.directories'), route('portal.directories.store'), ['name' => 'Okta SCIM'])->assertSessionHasNoErrors();
    $token = (string) flashed('newToken');

    expect($steps())->toBe([['key' => 'directory_created', 'done' => true], ['key' => 'directory_synced', 'done' => false]]);

    // The identity provider pushes its first person.
    $this->withToken($token)->postJson('/scim/v2/Users', [
        'schemas' => ['urn:ietf:params:scim:schemas:core:2.0:User'],
        'userName' => 'ada@acme.example',
        'emails' => [['value' => 'ada@acme.example', 'primary' => true]],
        'active' => true,
    ])->assertCreated();
    $this->flushHeaders();

    expect(Directory::query()->where('organization_id', $org)->sole()->last_synced_at)->toBeNull()
        ->and($steps()[1])->toBe(['key' => 'directory_synced', 'done' => true])
        // …and the directory list says an update arrived, rather than "No updates received yet".
        ->and($this->get(route('portal.directories'))->assertOk()->inertiaProps('directories')[0]['lastSyncedAt'])->toBeString();

    // A pull directory, on its own: done once its scheduled sync has run, not before.
    $pulled = intentOrg('portal-pull');
    openPortal($pulled, [PortalIntent::Dsync]);
    $directory = new Directory;
    $directory->forceFill([
        'organization_id' => $pulled,
        'name' => 'Google',
        'provider' => DirectoryProvider::GoogleWorkspace,
        'bearer_token_hash' => hash('sha256', 'unused by a pull directory'),
        'status' => DirectoryStatus::Active,
        'mappings' => [],
    ])->save();

    expect($steps()[1]['done'])->toBeFalse();

    $directory->forceFill(['last_synced_at' => now()])->save();

    expect($steps()[1]['done'])->toBeTrue();
});

// ── Domain verification ─────────────────────────────────────────────────────

it('walks domain verification end to end: add, publish, check, verified, remove', function (): void {
    app()->setLocale('en');
    $dns = $this->fakeDns();
    $org = intentOrg();
    openPortal($org, [PortalIntent::DomainVerification]);

    portalWrite('post', route('portal.domains'), route('portal.domains.store'), ['domain' => 'ACME.com'])
        ->assertRedirect(route('portal.domains'))
        ->assertSessionHasNoErrors();

    $row = $this->get(route('portal.domains'))->assertOk()->inertiaProps('domains')[0];

    expect($row['domain'])->toBe('acme.com')->and($row['verified'])->toBeFalse();

    // Not published yet: the refusal is the portal's own sentence, not the API's.
    portalWrite('post', route('portal.domains'), $row['verifyHref'])
        ->assertSessionHasErrors(['domain' => __('portal.errors.record_not_found')]);

    $dns->publish($row['recordHost'], $row['recordValue']);

    portalWrite('post', route('portal.domains'), $row['verifyHref'])->assertSessionHasNoErrors();

    expect(VerifiedDomain::query()->whereKey($row['id'])->sole()->isVerified())->toBeTrue()
        ->and(AuditEntry::query()->where('action', 'domain.verified')->sole()->context[ActionTrail::VIA])->toBe('portal');

    portalWrite('delete', route('portal.domains'), $row['removeHref'])->assertSessionHasNoErrors();

    expect(VerifiedDomain::query()->whereKey($row['id'])->exists())->toBeFalse();
});

it('says a refusal in the visitor\'s language', function (): void {
    $org = intentOrg();
    $other = intentOrg('portal-rival');
    app(DomainVerification::class)->add($other, 'taken.example');
    openPortal($org, [PortalIntent::DomainVerification]);

    $this->withHeader('Accept-Language', 'da');

    portalWrite('post', route('portal.domains'), route('portal.domains.store'), ['domain' => 'taken.example'])
        ->assertSessionHasErrors(['domain' => trans('portal.errors.domain_taken', [], 'da')]);
});

// ── Single sign-on ──────────────────────────────────────────────────────────

it('walks single sign-on end to end: start, paste our values, import metadata, verify a domain, activate', function (): void {
    $dns = $this->fakeDns();
    $org = intentOrg();
    openPortal($org, [PortalIntent::Sso]);

    // The guides, with the providers an IT administrator actually runs.
    $guides = $this->get(route('portal.sso', ['provider' => 'entra']))->assertOk()->inertiaProps('guides');
    expect(array_column($guides, 'key'))->toBe(['okta', 'entra', 'google', 'onelogin', 'jumpcloud', 'pingfederate', 'saml', 'oidc']);

    // Start: a draft with only OUR half — which is what exists to be pasted.
    portalWrite('post', route('portal.sso', ['provider' => 'entra']), route('portal.connections.store'), ['provider' => 'entra', 'name' => 'Entra ID'])
        ->assertRedirect(route('portal.sso', ['provider' => 'entra']));

    $connection = Connection::query()->where('organization_id', $org)->sole();
    $row = $this->get(route('portal.sso', ['provider' => 'entra']))->inertiaProps('connections')[0];

    expect($connection->status)->toBe(ConnectionStatus::Draft)
        ->and($row['complete'])->toBeFalse()
        ->and($row['values']['acs_url'])->toBe(route('sso.saml.acs', $connection->id))
        ->and($row['values']['entity_id'])->toBe(url('/sso/saml/'.$connection->id));

    // Not complete: activation is refused, whoever asks.
    portalWrite('post', route('portal.sso'), $row['urls']['activate'])->assertSessionHasErrors('activate');
    expect($connection->refresh()->status)->toBe(ConnectionStatus::Draft);

    // The identity provider's metadata, pasted — its three values land on the connection.
    portalWrite('post', route('portal.sso'), $row['urls']['metadata'], ['metadata' => portalMetadata('https://sts.windows.net/tenant/', portalCertificate(365))])
        ->assertSessionHasNoErrors();

    $row = $this->get(route('portal.sso', ['provider' => 'entra']))->inertiaProps('connections')[0];
    expect($row['complete'])->toBeTrue()
        ->and($row['idp']['idp_entity_id'])->toBe('https://sts.windows.net/tenant/')
        // Ours survived the update.
        ->and($row['values']['acs_url'])->toBe(route('sso.saml.acs', $connection->id));

    // A domain, proved.
    portalWrite('post', route('portal.sso'), route('portal.domains.store'), ['domain' => 'acme.com'])->assertSessionHasNoErrors();
    $domain = VerifiedDomain::query()->where('organization_id', $org)->sole();
    $dns->publish(app(DomainVerification::class)->challengeHost('acme.com'), $domain->verification_token);
    portalWrite('post', route('portal.sso'), route('portal.domains.verify', $domain->id))->assertSessionHasNoErrors();

    portalWrite('post', route('portal.sso'), $row['urls']['activate'])->assertSessionHasNoErrors();

    expect($connection->refresh()->status)->toBe(ConnectionStatus::Active)
        ->and(AuditEntry::query()->where('action', 'sso_connection.created')->sole()->actor_type)->toBe(ActorType::System);

    $tasks = $this->get(route('portal.setup'))->inertiaProps('tasks');
    expect($tasks[0]['done'])->toBeTrue();
});

it('refuses a portal write against another organization\'s connection', function (): void {
    $org = intentOrg();
    $theirs = samlConnectionExpiring(intentOrg('portal-other'), 200);
    openPortal($org, [PortalIntent::Sso, PortalIntent::CertificateRenewal]);

    // Looked up inside the session's own organization, so another one's is not found at all.
    portalWrite('post', route('portal.sso'), route('portal.connections.activate', $theirs->id))->assertNotFound();
    portalWrite('post', route('portal.certificates'), route('portal.certificates.stage', $theirs->id), ['certificate' => portalCertificate(400)])
        ->assertNotFound();

    expect($theirs->refresh()->status)->toBe(ConnectionStatus::Active);
})->group('security');

// ── Directory sync ──────────────────────────────────────────────────────────

it('walks directory sync: create a directory, see the token once, rotate it', function (): void {
    $org = intentOrg();
    openPortal($org, [PortalIntent::Dsync]);

    $props = (array) $this->get(route('portal.directories', ['provider' => 'okta']))->assertOk()->inertiaProps();

    expect(array_column($props['guides'], 'key'))->toBe(['okta', 'entra', 'onelogin', 'jumpcloud', 'scim'])
        ->and($props['scimBaseUrl'])->toBe(url('/scim/v2'));

    portalWrite('post', route('portal.directories'), route('portal.directories.store'), ['name' => 'Okta SCIM'])
        ->assertSessionHasNoErrors();

    $token = flashed('newToken');
    $directory = Directory::query()->where('organization_id', $org)->sole();

    expect($token)->toBeString()->not->toBe('')
        ->and($directory->bearer_token_hash)->not->toBeNull();

    portalWrite('post', route('portal.directories'), route('portal.directories.rotate', $directory->id))->assertSessionHasNoErrors();

    expect(flashed('newToken'))->toBeString()->not->toBe($token)
        ->and(AuditEntry::query()->where('action', 'directory.token_rotated')->sole()->context[ActionTrail::VIA])->toBe('portal');
});

// ── Log streams ─────────────────────────────────────────────────────────────

it('walks log streams: add their own destination, test it, see a failure, remove it', function (): void {
    $sink = new FakeStreamSink;
    app()->instance(StreamSink::class, $sink);
    $org = intentOrg();
    openPortal($org, [PortalIntent::LogStreams]);

    portalWrite('post', route('portal.log-streams'), route('portal.log-streams.store'), [
        'name' => 'Our Splunk',
        'destination' => 'splunk_hec',
        'endpoint_url' => 'https://splunk.acme.example:8088',
        'auth' => 'splunk',
        'secret' => 'hec-token',
    ])->assertSessionHasNoErrors();

    $stream = AuditStream::query()->ownedByOrganization($org)->sole();
    $row = $this->get(route('portal.log-streams'))->assertOk()->inertiaProps('streams')[0];

    expect($row['name'])->toBe('Our Splunk');

    portalWrite('post', route('portal.log-streams'), $row['testHref'])->assertSessionHasNoErrors();
    expect(flashed('streamTest'))->toBe(['id' => $stream->id, 'delivered' => true, 'error' => null])
        ->and($sink->batches()[0]['records'][0] ?? '')->toContain('siem.stream.test');

    $sink->failEverything();
    portalWrite('post', route('portal.log-streams'), $row['testHref'])->assertSessionHasNoErrors();
    expect(flashed('streamTest')['delivered'])->toBeFalse();

    portalWrite('delete', route('portal.log-streams'), $row['removeHref'])->assertSessionHasNoErrors();
    expect(AuditStream::query()->whereKey($stream->id)->exists())->toBeFalse();
});

it('shows a generated signing key once, and never echoes a token the IT admin typed', function (): void {
    app()->instance(StreamSink::class, new FakeStreamSink);
    $org = intentOrg();
    openPortal($org, [PortalIntent::LogStreams]);

    portalWrite('post', route('portal.log-streams'), route('portal.log-streams.store'), [
        'name' => 'Typed token',
        'destination' => 'splunk_hec',
        'endpoint_url' => 'https://splunk.acme.example:8088',
        'auth' => 'splunk',
        'secret' => 'hec-token-typed-by-them',
    ])->assertSessionHasNoErrors();

    expect(flashed('newSecret'))->toBeNull()
        ->and((string) $this->get(route('portal.log-streams'))->assertOk()->getContent())->not->toContain('hec-token-typed-by-them');

    portalWrite('post', route('portal.log-streams'), route('portal.log-streams.store'), [
        'name' => 'Signed',
        'destination' => 'generic_json',
        'endpoint_url' => 'https://siem.acme.example/collector',
        'auth' => 'hmac',
    ])->assertSessionHasNoErrors();

    expect(flashed('newSecret'))->toBeString()->not->toBe('');
});

it('never shows or touches the environment\'s own streams, or another organization\'s', function (): void {
    $org = intentOrg();
    [$key] = intentKey(['log_streams:write']);
    $environmentWide = $this->withToken($key)->postJson('/api/v1/log-streams', [
        'name' => 'Operator SIEM', 'destination' => 'generic_json', 'endpoint_url' => 'https://siem.operator.example', 'auth' => 'none', 'environment_wide' => true,
    ])->assertCreated()->json('data.id');
    $this->flushHeaders();

    openPortal($org, [PortalIntent::LogStreams]);

    expect($this->get(route('portal.log-streams'))->inertiaProps('streams'))->toBe([]);

    portalWrite('post', route('portal.log-streams'), route('portal.log-streams.test', $environmentWide))->assertNotFound();
    portalWrite('delete', route('portal.log-streams'), route('portal.log-streams.destroy', $environmentWide))->assertNotFound();

    expect(AuditStream::query()->whereKey($environmentWide)->exists())->toBeTrue();
})->group('security');

it('adds an S3 bucket through an assumed role, and hands the IT admin the trust policy with its external ID', function (): void {
    config([
        'siem.aws.access_key_id' => 'AKIAPLATFORMEXAMPLE',
        'siem.aws.secret_access_key' => 'platform-secret',
        'cbox-id.log_streams.aws_principal_arn' => 'arn:aws:iam::111122223333:user/cbox-siem',
    ]);
    $org = intentOrg();
    openPortal($org, [PortalIntent::LogStreams]);

    $props = $this->get(route('portal.log-streams'))->assertOk()->inertiaProps();

    expect(array_column($props['destinations'], 'value'))->toContain('datadog', 's3', 'gcs')
        ->and($props['assumedRoleAvailable'])->toBeTrue();

    portalWrite('post', route('portal.log-streams'), route('portal.log-streams.store'), [
        'name' => 'Audit bucket',
        'destination' => 's3',
        'options' => [
            'bucket' => 'acme-audit',
            'region' => 'eu-west-1',
            'prefix' => 'cbox',
            'role_arn' => 'arn:aws:iam::444455556666:role/cbox-writer',
            'access_key_id' => '',
            'gzip' => true,
        ],
    ])->assertSessionHasNoErrors();

    $stream = AuditStream::query()->ownedByOrganization($org)->sole();
    $externalId = $stream->destinationOptions()['external_id'] ?? null;

    expect($externalId)->toBeString()->toMatch('/^[0-9a-f]{32}$/')
        ->and($stream->secret)->toBeNull()
        ->and(flashed('awsSetup'))->toBe($stream->id)
        ->and(flashed('newSecret'))->toBeNull();

    $row = $this->get(route('portal.log-streams'))->inertiaProps('streams')[0];

    expect($row['aws']['externalId'])->toBe($externalId)
        ->and(json_decode((string) $row['aws']['trustPolicy'], true)['Statement'][0])->toBe([
            'Effect' => 'Allow',
            'Principal' => ['AWS' => 'arn:aws:iam::111122223333:user/cbox-siem'],
            'Action' => 'sts:AssumeRole',
            'Condition' => ['StringEquals' => ['sts:ExternalId' => $externalId]],
        ])
        ->and(json_decode((string) $row['aws']['permissionsPolicy'], true)['Statement'][0]['Resource'])->toBe('arn:aws:s3:::acme-audit/cbox/*');
});

it('adds a Datadog destination from the portal without ever showing the API key again', function (): void {
    $org = intentOrg();
    openPortal($org, [PortalIntent::LogStreams]);

    // A refused setting lands on its own field, in the IT admin's language.
    portalWrite('post', route('portal.log-streams'), route('portal.log-streams.store'), [
        'name' => 'Datadog',
        'destination' => 'datadog',
        'secret' => 'dd-api-key-typed-by-them',
        'options' => ['site' => 'datadoghq.eu', 'tags' => 'env:prod, 9bad'],
    ])->assertSessionHasErrors(['options.tags' => __('portal.errors.invalid_stream_configuration')]);

    portalWrite('post', route('portal.log-streams'), route('portal.log-streams.store'), [
        'name' => 'Datadog',
        'destination' => 'datadog',
        'secret' => 'dd-api-key-typed-by-them',
        'options' => ['site' => 'datadoghq.eu', 'service' => 'acme', 'tags' => 'env:prod, team:sec'],
    ])->assertSessionHasNoErrors();

    $stream = AuditStream::query()->ownedByOrganization($org)->sole();

    expect($stream->endpoint_url)->toBe('https://http-intake.logs.datadoghq.eu/api/v2/logs')
        ->and($stream->destinationOptions())->toBe(['site' => 'datadoghq.eu', 'service' => 'acme', 'tags' => 'env:prod,team:sec'])
        ->and(flashed('newSecret'))->toBeNull()
        ->and((string) $this->get(route('portal.log-streams'))->assertOk()->getContent())->not->toContain('dd-api-key-typed-by-them');
})->group('security');

// ── SAML certificate renewal ────────────────────────────────────────────────

it('walks certificate renewal: see the expiry, upload the new one, see the checks, activate', function (): void {
    $org = intentOrg();
    $connection = samlConnectionExpiring($org, 12);
    $old = SamlCertificate::parse((string) (app(Connections::class)->config($connection)['idp_x509cert'] ?? ''));
    openPortal($org, [PortalIntent::CertificateRenewal]);

    $row = $this->get(route('portal.certificates'))->assertOk()->inertiaProps('connections')[0];

    expect($row['daysRemaining'])->toBeIn([11, 12])
        ->and($row['certificates'][0]['role'])->toBe('primary')
        ->and($row['certificates'][0]['fingerprint_sha256'])->toBe($old?->fingerprint);

    // An expired certificate is refused, in the visitor's language.
    portalWrite('post', route('portal.certificates'), $row['stageHref'], ['certificate' => portalCertificate(0)])
        ->assertSessionHasErrors(['certificate' => __('portal.errors.certificate_expired')]);

    // Metadata for a DIFFERENT identity provider is somebody else's certificate.
    portalWrite('post', route('portal.certificates'), $row['stageHref'], ['metadata' => portalMetadata('https://evil.example/entity', portalCertificate(400))])
        ->assertSessionHasErrors(['metadata' => __('portal.errors.entity_mismatch')]);

    // The real one, from the same identity provider's metadata.
    $new = portalCertificate(730);
    portalWrite('post', route('portal.certificates'), $row['stageHref'], ['metadata' => portalMetadata('https://idp.acme.example/entity', $new)])
        ->assertSessionHasNoErrors();

    expect(array_column(flashed('certificateChecks')['checks'], 'passed'))->each->toBeTrue();

    $row = $this->get(route('portal.certificates'))->inertiaProps('connections')[0];
    $staged = $row['certificates'][1];

    // Both trusted now, so the connection no longer stops working in twelve days.
    expect($staged['role'])->toBe('staged')
        ->and($row['daysRemaining'])->toBeGreaterThan(700);

    // Uploading it again is not a second copy.
    portalWrite('post', route('portal.certificates'), $row['stageHref'], ['certificate' => $new])
        ->assertSessionHasErrors(['certificate' => __('portal.errors.certificate_already_present')]);

    portalWrite('post', route('portal.certificates'), $row['activateHref'], ['fingerprint' => $staged['fingerprint_sha256']])
        ->assertSessionHasNoErrors();

    $config = app(Connections::class)->config($connection->refresh());

    expect(SamlCertificate::parse((string) $config['idp_x509cert'])?->fingerprint)->toBe($staged['fingerprint_sha256'])
        ->and($config['idp_x509cert_extra'] ?? [])->toBe([])
        ->and(AuditEntry::query()->where('action', 'sso_connection.certificate_activated')->sole()->context['replaced'])->toBe($old?->fingerprint);
});

it('manages certificates over REST, with the checks on the answer', function (): void {
    [$key] = intentKey(['sso:read', 'sso:write']);
    $org = intentOrg();
    $connection = samlConnectionExpiring($org, 20);

    $this->withToken($key)->getJson("/api/v1/sso/connections/{$connection->id}/certificates")
        ->assertOk()
        ->assertJsonPath('data.certificates.0.role', 'primary')
        ->assertJsonPath('data.certificates.0.readable', true);

    $staged = $this->withToken($key)->postJson("/api/v1/sso/connections/{$connection->id}/certificates", ['certificate' => portalCertificate(500)])
        ->assertOk()
        ->assertJsonPath('data.certificates.1.role', 'staged')
        ->assertJsonPath('data.checks.0', ['check' => 'readable', 'passed' => true])
        ->json('data.certificates.1.fingerprint_sha256');

    $this->withToken($key)->postJson("/api/v1/sso/connections/{$connection->id}/certificates", ['certificate' => 'not a certificate'])
        ->assertUnprocessable()->assertJsonPath('error', 'invalid_certificate');

    $this->withToken($key)->postJson("/api/v1/sso/connections/{$connection->id}/certificates/activate", ['fingerprint_sha256' => 'AA:BB'])
        ->assertUnprocessable()->assertJsonPath('error', 'certificate_not_staged');

    $this->withToken($key)->postJson("/api/v1/sso/connections/{$connection->id}/certificates/activate", ['fingerprint_sha256' => $staged])
        ->assertOk()
        ->assertJsonPath('data.certificates.0.fingerprint_sha256', $staged)
        ->assertJsonCount(1, 'data.certificates');
});

it('creates an SSO draft before the identity provider\'s details over REST, and will not activate it', function (): void {
    [$key] = intentKey(['sso:read', 'sso:write']);
    $org = intentOrg();

    $created = $this->withToken($key)->postJson('/api/v1/sso/connections', [
        'organization_id' => $org, 'name' => 'Okta', 'type' => 'saml', 'pending_idp' => true,
    ])->assertCreated()
        ->assertJsonPath('data.complete', false)
        ->json('data');

    expect($created['service_provider']['sp_acs_url'])->toBe(route('sso.saml.acs', $created['id']));

    $this->withToken($key)->postJson("/api/v1/sso/connections/{$created['id']}/activate", ['organization_id' => $org])
        ->assertUnprocessable()->assertJsonPath('error', 'incomplete_connection');
});

it('tests a log stream over REST and reports the destination\'s refusal', function (): void {
    $sink = (new FakeStreamSink)->failEverything();
    app()->instance(StreamSink::class, $sink);
    [$key] = intentKey(['log_streams:write', 'log_streams:read']);

    $id = $this->withToken($key)->postJson('/api/v1/log-streams', [
        'name' => 'JSON', 'destination' => 'generic_json', 'endpoint_url' => 'https://siem.acme.example', 'auth' => 'none', 'environment_wide' => true,
    ])->assertCreated()->json('data.id');

    $this->withToken($key)->postJson("/api/v1/log-streams/{$id}/test")
        ->assertOk()
        ->assertJsonPath('data.delivered', false)
        ->assertJsonPath('data.error', 'fake sink: delivery to [JSON] failed');
});

// ── Finishing ───────────────────────────────────────────────────────────────

it('finishes from the checklist, recorded as the portal session with its minter named', function (): void {
    $org = intentOrg();
    $link = openPortal($org, [PortalIntent::LogStreams], 'sub_the_minter');

    portalWrite('post', route('portal.setup'), route('portal.finish'))->assertRedirect(route('portal.done'));

    $entry = AuditEntry::query()->where('action', 'portal_link.completed')->sole();

    expect($entry->actor_type)->toBe(ActorType::System)
        ->and($entry->actor_id)->toBe($link->id)
        ->and($entry->context[PortalPrincipal::CREATED_BY])->toBe('sub_the_minter');

    $this->get(route('portal.setup'))->assertRedirect(route('portal.expired'));
});
