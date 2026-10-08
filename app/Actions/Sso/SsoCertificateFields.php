<?php

declare(strict_types=1);

namespace App\Actions\Sso;

use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionRefused;
use App\Platform\Sso\ConnectionCertificates;
use Cbox\Id\Federation\Enums\ConnectionType;
use Cbox\Id\Federation\Models\Connection;

/**
 * The SAML-certificate actions' shared lookup and answer — `SsoCertificates` in the spec. A
 * helper, not an action.
 */
final class SsoCertificateFields
{
    /**
     * The SAML connection the id names, within what the caller may reach — a 404 otherwise,
     * and a refusal for an OIDC one, which has no certificate to renew.
     *
     * @throws ActionRefused
     */
    public static function samlConnection(ActionContext $context, bool $changing = true): Connection
    {
        $connection = $changing ? SsoFields::changeable($context) : SsoFields::connection($context);

        if ($connection->type !== ConnectionType::Saml) {
            throw ActionRefused::because('not_saml', 'Only a SAML connection has signing certificates to renew.');
        }

        return $connection;
    }

    /**
     * @param  list<array{check: string, passed: bool}>  $checks
     * @return array<string, mixed>
     */
    public static function present(Connection $connection, ConnectionCertificates $certificates, array $checks = []): array
    {
        $effective = $certificates->effectiveExpiry($connection);

        return [
            'connection_id' => $connection->id,
            'organization_id' => $connection->organization_id,
            'expires_at' => $effective?->notAfter->toIso8601ZuluString(),
            'days_remaining' => $effective?->daysRemaining(),
            'certificates' => ConnectionCertificates::present($certificates->all($connection)),
            ...$checks === [] ? [] : ['checks' => $checks],
        ];
    }
}
