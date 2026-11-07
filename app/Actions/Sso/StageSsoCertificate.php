<?php

declare(strict_types=1);

namespace App\Actions\Sso;

use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionMessage;
use App\Platform\Actions\ActionRefused;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\ConsoleGate;
use App\Platform\Actions\Danger;
use App\Platform\Actions\FrameworkMessage;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;
use App\Platform\Enterprise\EnterpriseAudit;
use App\Platform\Enterprise\EnterpriseReach;
use App\Platform\Sso\ConnectionCertificates;
use App\Platform\Sso\SamlCertificate;
use Cbox\Id\Federation\Exceptions\SamlMetadataImportFailed;
use Cbox\Id\Federation\Exceptions\UnsafeFederationUrl;
use Cbox\Id\Federation\Saml\SamlMetadataImporter;
use Cbox\Id\Federation\ValueObjects\ImportedIdpMetadata;

/**
 * Trust an identity provider's NEW signing certificate beside the current one — the first
 * step of a renewal with no outage in it ({@see ConnectionCertificates}).
 *
 * From a PEM, or from the identity provider's metadata (XML, or the https URL it is
 * published at) — what an IdP's "certificate renewal" screen hands out. From metadata, every
 * certificate it lists that is not already on file is staged, and the metadata must be the
 * SAME identity provider's: an entity id that is not the connection's is somebody else's
 * certificate, refused rather than trusted.
 *
 * TESTED BEFORE IT IS TRUSTED, and the answer carries what was tested (`checks`): it reads
 * as an X.509 certificate, it has not expired, its key is not too weak to sign with, and it
 * is not already on file. Not yet valid is allowed — identity providers publish the next
 * certificate ahead of using it — and said in the checks.
 *
 * Critical: a trusted signing certificate is what an assertion's signature is checked
 * against, so whoever can add one can sign people in.
 */
#[AsAction(
    name: 'sso.connections.certificates.stage',
    summary: 'Stage a SAML connection\'s new IdP signing certificate (PEM or IdP metadata) beside the current one, so a renewal has no outage. Activate it once the IdP signs with it.',
    scope: 'sso:write',
    danger: Danger::Critical,
    schema: 'SsoCertificates',
    tag: 'Enterprise SSO',
    rest: ['POST', '/sso/connections/{id}/certificates'],
    consoleGate: ConsoleGate::Administer,
)]
final readonly class StageSsoCertificate implements Action
{
    /** The weakest RSA key a SAML signature may be made with. */
    private const int MIN_RSA_BITS = 2048;

    public function __construct(
        private ConnectionCertificates $certificates,
        private SamlMetadataImporter $importer,
        private EnterpriseAudit $audit,
    ) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('id')->inPath()->describe('The connection\'s id.'),
            EnterpriseReach::narrowField(),
            Field::string('certificate')->nullable()->max(20000)->describe('The new signing certificate, PEM. Send this or metadata.'),
            Field::string('metadata')->nullable()->max(500000)->describe('The identity provider\'s metadata XML, or the https URL it is published at. Send this or certificate.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $connection = SsoCertificateFields::samlConnection($context);
        $pem = $context->nullableString('certificate');
        $metadata = $context->nullableString('metadata');

        if (($pem === null) === ($metadata === null)) {
            throw ActionRefused::because('certificate_required', new ActionMessage('console.send_the_new_certificate_pem_or_the_identity_provider_s_0e07e791'), 'certificate');
        }

        $candidates = $pem !== null ? [$pem] : $this->fromMetadata($metadata ?? '', SsoFields::config($connection));

        $onFile = array_filter(array_map(
            static fn (array $entry): ?string => $entry['certificate']?->fingerprint,
            $this->certificates->all($connection),
        ));

        $staged = [];
        $checks = [];

        foreach ($candidates as $candidate) {
            $certificate = SamlCertificate::parse($candidate);

            if ($certificate === null) {
                throw ActionRefused::because('invalid_certificate', new ActionMessage('console.that_is_not_an_x_509_certificate_paste_the_pem_block_be_00de892f'), 'certificate');
            }

            if (in_array($certificate->fingerprint, $onFile, true)) {
                continue;
            }

            $checks = $this->test($certificate);

            if (! $this->certificates->stage($connection, $certificate)) {
                throw ActionRefused::because('unreadable_config', new ActionMessage('console.this_connection_s_settings_could_not_be_read_so_nothing_0ab248ca'));
            }

            $connection->refresh();
            $onFile[] = $certificate->fingerprint;
            $staged[] = $certificate->fingerprint;
        }

        if ($staged === []) {
            throw ActionRefused::because('certificate_already_present', new ActionMessage('console.that_certificate_is_already_on_file_for_this_connection_c93a9731'), 'certificate');
        }

        $this->audit->record(EnterpriseAudit::SSO_CERTIFICATE_STAGED, $context->actor(), $connection->organization_id, 'connection', $connection->id, [
            'name' => $connection->name,
            'fingerprints' => $staged,
        ]);

        return ActionResult::item($connection, SsoCertificateFields::present($connection, $this->certificates, $checks));
    }

    /**
     * The certificates a metadata document lists, refused unless it describes the identity
     * provider this connection already trusts.
     *
     * @param  array<string, mixed>  $config
     * @return list<string>
     *
     * @throws ActionRefused
     */
    private function fromMetadata(string $metadata, array $config): array
    {
        $metadata = trim($metadata);

        try {
            $imported = str_starts_with($metadata, 'https://') || str_starts_with($metadata, 'http://')
                ? $this->importer->fromUrl($metadata)
                : $this->importer->fromXml($metadata);
        } catch (SamlMetadataImportFailed|UnsafeFederationUrl $e) {
            throw ActionRefused::because('invalid_metadata', FrameworkMessage::from($e), 'metadata');
        }

        $entityId = $config['idp_entity_id'] ?? null;

        if (is_string($entityId) && $entityId !== '' && $imported->entityId !== $entityId) {
            throw ActionRefused::because('entity_mismatch', new ActionMessage('console.that_metadata_is_for_part1_not_this_connection_s_identi_be23f5a3', ['part1' => (string) ($imported->entityId), 'part2' => (string) ($entityId)]), 'metadata');
        }

        return self::listed($imported);
    }

    /**
     * @return list<string>
     */
    private static function listed(ImportedIdpMetadata $metadata): array
    {
        return array_values(array_filter([$metadata->x509cert, ...$metadata->extraCertificates], static fn (string $value): bool => $value !== ''));
    }

    /**
     * What a certificate must be to be trusted, refused on the first that fails — and the
     * list of what it passed, for the answer.
     *
     * @return list<array{check: string, passed: bool}>
     *
     * @throws ActionRefused
     */
    private function test(SamlCertificate $certificate): array
    {
        if ($certificate->isExpired()) {
            throw ActionRefused::because('certificate_expired', new ActionMessage('console.that_certificate_has_already_expired_part1_download_the_9adb4bed', ['part1' => (string) ($certificate->notAfter->toDateString())]), 'certificate');
        }

        if ($certificate->keyType === 'RSA' && $certificate->keyBits !== null && $certificate->keyBits < self::MIN_RSA_BITS) {
            throw ActionRefused::because('weak_key', new ActionMessage('console.that_certificate_s_key_is_part1_bit_rsa_use_a_key_of_at_897f80d2', ['part1' => (string) ($certificate->keyBits), 'part2' => (string) (self::MIN_RSA_BITS)]), 'certificate');
        }

        return [
            ['check' => 'readable', 'passed' => true],
            ['check' => 'not_expired', 'passed' => true],
            ['check' => 'key_strength', 'passed' => true],
            ['check' => 'not_on_file', 'passed' => true],
            // Allowed either way — a provider publishes the next one ahead of using it.
            ['check' => 'valid_now', 'passed' => ! $certificate->notBefore->isFuture()],
        ];
    }
}
