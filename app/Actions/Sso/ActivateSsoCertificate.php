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
use App\Platform\Enterprise\EnterpriseAudit;
use App\Platform\Enterprise\EnterpriseReach;
use App\Platform\Sso\ConnectionCertificates;

/**
 * Finish a renewal: make a staged signing certificate the connection's primary, and stop
 * trusting the one it replaces ({@see ConnectionCertificates::promote()}).
 *
 * Run it once the identity provider signs with the new certificate. Until then both are
 * trusted and nothing has to happen in any order; after it, an assertion signed with the old
 * certificate is refused — which is the point, since the old one is the one about to expire
 * or the one that leaked.
 */
#[AsAction(
    name: 'sso.connections.certificates.activate',
    summary: 'Make a staged SAML signing certificate the connection\'s primary. The certificate it replaces stops being trusted.',
    scope: 'sso:write',
    danger: Danger::Critical,
    schema: 'SsoCertificates',
    tag: 'Enterprise SSO',
    rest: ['POST', '/sso/connections/{id}/certificates/activate'],
    consoleGate: ConsoleGate::Administer,
)]
final readonly class ActivateSsoCertificate implements Action
{
    public function __construct(
        private ConnectionCertificates $certificates,
        private EnterpriseAudit $audit,
    ) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('id')->inPath()->describe('The connection\'s id.'),
            EnterpriseReach::narrowField(),
            Field::string('fingerprint_sha256')->required()->max(200)->describe('The staged certificate\'s SHA-256 fingerprint, as the certificate list gives it.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $connection = SsoCertificateFields::samlConnection($context);
        $fingerprint = strtoupper(trim($context->string('fingerprint_sha256')));

        $previous = null;

        foreach ($this->certificates->all($connection) as $entry) {
            if ($entry['role'] === 'primary') {
                $previous = $entry['certificate']?->fingerprint;
            }
        }

        if (! $this->certificates->promote($connection, $fingerprint)) {
            throw ActionRefused::because('certificate_not_staged', 'No staged certificate on this connection has that fingerprint. Stage it first.', 'fingerprint_sha256');
        }

        $connection->refresh();

        $this->audit->record(EnterpriseAudit::SSO_CERTIFICATE_ACTIVATED, $context->actor(), $connection->organization_id, 'connection', $connection->id, array_filter([
            'name' => $connection->name,
            'fingerprint' => $fingerprint,
            'replaced' => $previous,
        ], static fn (?string $value): bool => $value !== null));

        return ActionResult::item($connection, SsoCertificateFields::present($connection, $this->certificates));
    }
}
