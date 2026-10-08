<?php

declare(strict_types=1);

namespace App\Actions\Sso;

use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\ConsoleGate;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;
use App\Platform\Enterprise\EnterpriseReach;
use App\Platform\Sso\ConnectionCertificates;

/**
 * A SAML connection's signing certificates — the primary it trusts and any staged beside it
 * for a rollover — each with its subject, fingerprint and expiry. Never the certificate
 * itself: the identity provider has it, and it is not this API's to hand round.
 *
 * `expires_at` on the answer is when the CONNECTION stops working if nothing is done: the
 * latest expiry among the certificates it trusts ({@see ConnectionCertificates::effectiveExpiry()}).
 */
#[AsAction(
    name: 'sso.connections.certificates.list',
    summary: 'List a SAML connection\'s signing certificates — primary and staged — with each one\'s fingerprint and expiry.',
    scope: 'sso:read',
    danger: Danger::Read,
    schema: 'SsoCertificates',
    tag: 'Single sign-on',
    rest: ['GET', '/sso/connections/{id}/certificates'],
    consoleGate: ConsoleGate::Administer,
)]
final readonly class ListSsoCertificates implements Action
{
    public function __construct(private ConnectionCertificates $certificates) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('id')->inPath()->describe('The connection\'s id.'),
            EnterpriseReach::narrowField(),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $connection = SsoCertificateFields::samlConnection($context, changing: false);

        return ActionResult::item($connection, SsoCertificateFields::present($connection, $this->certificates));
    }
}
