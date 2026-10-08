<?php

declare(strict_types=1);

namespace App\Http\Controllers\Sso;

use App\Actions\Sso\SsoFields;
use App\Http\Controllers\Controller;
use Cbox\Id\Federation\Contracts\Connections;
use Cbox\Id\Federation\Enums\ConnectionType;
use Cbox\Id\Federation\Saml\SamlSettings;
use Cbox\Id\Federation\ValueObjects\SamlConnectionConfig;
use Illuminate\Http\Response;
use OneLogin\Saml2\Settings;
use Throwable;

/**
 * A SAML connection's SERVICE-PROVIDER metadata — our entity id, ACS URL and logout URL as
 * one XML document, for an identity provider that imports SP metadata (PingFederate, AD FS,
 * Entra's "Upload metadata file") instead of having them typed in one at a time.
 *
 * SHADOWS THE FRAMEWORK'S ROUTE, for one reason: the framework builds the document from the
 * connection's whole SAML config, so it answered 422 until the identity provider's half was
 * on file — and an identity provider wants our half BEFORE it hands out its own. The Admin
 * Portal and the console create the connection as a draft exactly so there is something to
 * paste into the IdP's setup screen; this is the paste that works on that draft. Only our
 * half is in the document, so only our half is needed: the IdP fields are blanks the
 * metadata never reads ({@see Settings} validates the SP side only).
 *
 * Public and unauthenticated like the framework's (an IdP's importer fetches it), and no
 * secret is in it. The connection id resolves through the environment-scoped model, so
 * another environment's id is the same 404 as a made-up one.
 */
final class SamlMetadataController extends Controller
{
    public function __construct(private readonly Connections $connections) {}

    public function __invoke(string $connection): Response
    {
        $model = $this->connections->byId($connection);

        if ($model === null || $model->type !== ConnectionType::Saml) {
            return new Response('Unknown SAML connection.', 404);
        }

        $config = SsoFields::config($model);
        $ours = SsoFields::serviceProvider($model);

        try {
            $settings = SamlSettings::for(new SamlConnectionConfig(
                idpEntityId: self::string($config, 'idp_entity_id'),
                idpSsoUrl: self::string($config, 'idp_sso_url'),
                idpCertificate: self::string($config, 'idp_x509cert'),
                // A value on file wins — somebody may have set their own — and ours fills
                // in what is not.
                spEntityId: self::string($config, 'sp_entity_id') ?: $ours['sp_entity_id'],
                spAcsUrl: self::string($config, 'sp_acs_url') ?: $ours['sp_acs_url'],
                spSlsUrl: self::string($config, 'sp_sls_url') ?: null,
            ));

            $xml = $settings->getSPMetadata();
            $errors = $settings->validateMetadata($xml);
        } catch (Throwable) {
            return new Response('SAML connection is not fully configured.', 422);
        }

        if ($errors !== []) {
            return new Response('Generated metadata is invalid.', 500);
        }

        return new Response($xml, 200, [
            'Content-Type' => 'application/samlmetadata+xml',
            'Content-Disposition' => 'attachment; filename="cbox-id-sp-metadata.xml"',
        ]);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private static function string(array $config, string $key): string
    {
        $value = $config[$key] ?? null;

        return is_string($value) ? trim($value) : '';
    }
}
