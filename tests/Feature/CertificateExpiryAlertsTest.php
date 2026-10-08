<?php

declare(strict_types=1);

use App\Mail\CertificateExpiringMail;
use App\Models\CertificateAlert;
use App\Platform\Console\WebhookEventCatalogue;
use App\Platform\Sso\CertificateExpiryAlerts;
use Cbox\Id\Federation\Contracts\Connections;
use Cbox\Id\Federation\Enums\ConnectionType;
use Cbox\Id\Federation\Models\Connection;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Kernel\Audit\Enums\ActorType;
use Cbox\Id\Kernel\Audit\Models\AuditEntry;
use Cbox\Id\Kernel\Crypto\Contracts\SecretBox;
use Cbox\Id\Kernel\Events\Models\Event;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\EnvironmentStatus;
use Cbox\Id\Organization\Enums\EnvironmentType;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Cbox\Id\Platform\Contracts\EnvironmentApiKeys;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| THE DAILY SAML CERTIFICATE SCAN: a webhook, a trail entry and a mail at 30 and 7 days —
| each once per certificate — and the console warning that reads the same answer live.
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    // The scan walks real environments; the suite's ambient one is a row here too.
    Environment::query()->forceCreate([
        'id' => 'env_test', 'name' => 'Test', 'slug' => 'env-test-'.Str::lower(Str::random(4)),
        'type' => EnvironmentType::Production, 'status' => EnvironmentStatus::Active, 'is_default' => false, 'settings' => [],
    ]);
});

function expiringCertificate(int $days): string
{
    static $key = null;
    $key ??= openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);

    $csr = openssl_csr_new(['commonName' => 'idp.acme.example'], $key);
    openssl_x509_export(openssl_csr_sign($csr, null, $key, $days, [], random_int(1, PHP_INT_MAX)), $pem);

    return $pem;
}

/**
 * An organization with an owner, and an active SAML connection whose certificate expires in $days.
 *
 * @return array{0: string, 1: Connection}
 */
function organizationWithExpiringSaml(int $days, bool $active = true): array
{
    $org = app(Organizations::class)->create(new NewOrganization('Acme', 'cert-'.Str::lower(Str::random(6))))->id;
    $owner = app(Subjects::class)->create('owner-'.Str::lower(Str::random(4)).'@acme.example', 'Owner', 'supersecret123');
    app(Memberships::class)->add($org, $owner->id, MembershipRole::Owner);

    $connection = app(Connections::class)->create($org, ConnectionType::Saml, 'Okta', [
        'idp_entity_id' => 'https://idp.acme.example/entity',
        'idp_sso_url' => 'https://idp.acme.example/sso',
        'idp_x509cert' => expiringCertificate($days),
        'sp_entity_id' => 'https://sp.acme.example',
        'sp_acs_url' => 'https://sp.acme.example/acs',
    ]);

    if ($active) {
        app(Connections::class)->activate($org, $connection->id);
    }

    return [$org, $connection->refresh()];
}

it('alerts once at 30 days: a webhook event, a trail entry and a mail to the organization\'s owners', function (): void {
    Mail::fake();
    [$org, $connection] = organizationWithExpiringSaml(20);

    $this->artisan('cbox-id:sso:certificate-expiry')->assertSuccessful();

    $event = Event::query()->where('type', 'connection.certificate_expiring')->sole();

    expect($event->organization_id)->toBe($org)
        ->and($event->payload['id'])->toBe($connection->id)
        ->and($event->payload['threshold_days'])->toBe(30)
        ->and($event->payload['days_remaining'])->toBeIn([19, 20])
        ->and($event->payload['certificate']['fingerprint_sha256'])->toBeString();

    $entry = AuditEntry::query()->where('action', 'connection.certificate_expiring')->sole();

    expect($entry->actor_type)->toBe(ActorType::System)
        ->and($entry->organization_id)->toBe($org);

    Mail::assertSent(CertificateExpiringMail::class, fn (CertificateExpiringMail $mail): bool => $mail->connectionName === 'Okta' && $mail->locale === 'en');

    // The next morning: nothing new to say.
    $this->artisan('cbox-id:sso:certificate-expiry')->assertSuccessful();

    expect(Event::query()->where('type', 'connection.certificate_expiring')->count())->toBe(1)
        ->and(CertificateAlert::query()->count())->toBe(1);
    Mail::assertSentCount(1);
});

it('says it again, once, at 7 days — and for a lapsed certificate', function (): void {
    Mail::fake();
    [, $soon] = organizationWithExpiringSaml(5);
    [, $lapsed] = organizationWithExpiringSaml(0);

    expect(app(CertificateExpiryAlerts::class)->run())->toBe(2);

    expect(CertificateAlert::query()->where('connection_id', $soon->id)->sole()->threshold_days)->toBe(7)
        ->and(CertificateAlert::query()->where('connection_id', $lapsed->id)->sole()->threshold_days)->toBe(7)
        ->and(app(CertificateExpiryAlerts::class)->run())->toBe(0);
});

it('stays quiet for a healthy certificate, a renewal already staged, a draft, or with mail switched off', function (): void {
    Mail::fake();
    config(['cbox-id.portal.certificate_alerts.mail_admins' => false]);

    organizationWithExpiringSaml(400);
    organizationWithExpiringSaml(10, active: false);
    [$org, $renewed] = organizationWithExpiringSaml(10);

    $config = app(Connections::class)->config($renewed);
    $config['idp_x509cert_extra'] = [expiringCertificate(700)];
    $renewed->config_encrypted = app(SecretBox::class)->seal(json_encode($config, JSON_THROW_ON_ERROR), $renewed->secretContext());
    $renewed->save();

    expect(app(CertificateExpiryAlerts::class)->run())->toBe(0);

    [, $alone] = organizationWithExpiringSaml(15);

    expect(app(CertificateExpiryAlerts::class)->run())->toBe(1)
        ->and(Event::query()->where('type', 'connection.certificate_expiring')->sole()->payload['id'])->toBe($alone->id);
    Mail::assertNothingSent();
});

it('offers connection.certificate_expiring to webhooks and accepts a subscription to it', function (): void {
    expect(WebhookEventCatalogue::offered())->toContain('connection.certificate_expiring');

    $key = app(EnvironmentApiKeys::class)->issue('env_test', 'Webhooks', ['webhooks:write', 'webhooks:read'])->plaintext;

    $this->withToken($key)->postJson('/api/v1/webhooks', [
        'url' => 'https://hooks.acme.example/cbox',
        'event_types' => ['connection.certificate_expiring'],
        'environment_wide' => true,
    ])->assertCreated()->assertJsonPath('data.event_types', ['connection.certificate_expiring']);
});

it('warns on the organization\'s console pages while a certificate is about to expire', function (): void {
    [$org, $connection] = organizationWithExpiringSaml(9);

    $warnings = app(CertificateExpiryAlerts::class)->warningsFor($org);

    expect($warnings)->toHaveCount(1)
        ->and($warnings[0]['connection_id'])->toBe($connection->id)
        ->and($warnings[0]['days_remaining'])->toBeIn([8, 9])
        ->and($warnings[0]['expired'])->toBeFalse();

    expect(app(CertificateExpiryAlerts::class)->warningsFor(organizationWithExpiringSaml(200)[0]))->toBe([]);
});

it('renders the expiry mail in the reader\'s language', function (): void {
    $mail = (new CertificateExpiringMail('Acme', 'Okta', now()->addDays(7)->toImmutable(), 7))->locale('sv');

    expect($mail->render())->toContain('Okta')
        ->and($mail->render())->toContain(trans('mail.certificate_expiring.what_to_do', [], 'sv'));
});
