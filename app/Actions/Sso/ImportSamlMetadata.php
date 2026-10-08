<?php

declare(strict_types=1);

namespace App\Actions\Sso;

use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionRefused;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\ConsoleGate;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;
use App\Platform\Actions\Preflight;
use App\Platform\Integrations\OutboundUrl;
use Cbox\Id\Federation\Exceptions\SamlMetadataImportFailed;
use Cbox\Id\Federation\Exceptions\UnsafeFederationUrl;
use Cbox\Id\Federation\Saml\SamlMetadataImporter;

/**
 * Read an identity provider's SAML metadata — pasted XML, or the URL it is published at —
 * into the three settings a SAML connection needs from it. Nothing is stored: the answer
 * is what a caller (or the console's form) puts into {@see CreateSsoConnection}.
 *
 * Parsed by the framework's vetted importer; a URL is fetched behind its SSRF guard. A
 * write in name only — it changes nothing — because it fetches somebody else's server on
 * the caller's behalf, which is not something a read-only key should be able to make
 * this platform do.
 *
 * The certificate in the answer is the IdP's PUBLIC signing certificate, published in its
 * own metadata; it is not a secret, unlike the one a connection holds once created.
 */
#[AsAction(
    name: 'sso.saml_metadata.import',
    summary: 'Parse an identity provider\'s SAML metadata (XML or a metadata URL) into the entity id, SSO URL and certificate a SAML connection needs. Stores nothing.',
    scope: 'sso:write',
    danger: Danger::Write,
    schema: 'SamlMetadata',
    tag: 'Enterprise SSO',
    rest: ['POST', '/sso/saml-metadata'],
    consoleRoutes: ['connections.import', 'environment.connections.import'],
    consoleGate: ConsoleGate::Administer,
)]
final readonly class ImportSamlMetadata implements Action, Preflight
{
    public function __construct(private SamlMetadataImporter $importer) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('metadata')->required()->max(500000)->describe('The IdP metadata XML, or an https URL it is published at.'),
        ]);
    }

    /**
     * Something to read, and — for a URL — an address the SSRF guard lets out: the guard
     * only, never the fetch, which is the action itself and waits for any approval.
     */
    public function preflight(ActionContext $context): void
    {
        $input = self::metadata($context);

        if (self::isUrl($input)) {
            OutboundUrl::assertFederation($input, 'invalid_metadata', 'metadata');
        }
    }

    public function handle(ActionContext $context): ActionResult
    {
        $input = self::metadata($context);

        try {
            $metadata = self::isUrl($input)
                ? $this->importer->fromUrl($input)
                : $this->importer->fromXml($input);
        } catch (SamlMetadataImportFailed|UnsafeFederationUrl $e) {
            throw ActionRefused::because('invalid_metadata', $e->getMessage(), 'metadata');
        }

        return ActionResult::item($metadata, [
            'idp_entity_id' => $metadata->entityId,
            'idp_sso_url' => $metadata->ssoUrl,
            'idp_x509cert' => $metadata->x509cert,
        ]);
    }

    /** @throws ActionRefused */
    private static function metadata(ActionContext $context): string
    {
        $input = trim($context->string('metadata'));

        if ($input === '') {
            throw ActionRefused::because('metadata_required', 'Paste the IdP metadata XML, or a metadata URL.', 'metadata');
        }

        return $input;
    }

    private static function isUrl(string $input): bool
    {
        return str_starts_with($input, 'http://') || str_starts_with($input, 'https://');
    }
}
