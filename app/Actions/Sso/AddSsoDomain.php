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
use App\Platform\Enterprise\EnterpriseReach;
use Cbox\Id\Federation\Contracts\DomainVerification;
use Cbox\Id\Federation\Exceptions\DomainAlreadyClaimed;

/**
 * Claim an email domain for an organization and mint the DNS TXT record that proves it.
 * Nothing routes to it until the record is published and {@see VerifySsoDomain} finds it.
 *
 * NORMALIZED FIRST, THEN CHECKED: `ACME.com` is not a malformed domain, it is the same
 * domain with the shift key held. A domain another organization already claimed is
 * refused — whoever proves it first owns it.
 */
#[AsAction(
    name: 'sso.domains.create',
    summary: 'Claim an email domain for an organization\'s single sign-on, and get the DNS TXT record to publish to prove it.',
    scope: 'sso:write',
    danger: Danger::Write,
    schema: 'SsoDomain',
    tag: 'Enterprise SSO',
    rest: ['POST', '/sso/domains'],
    status: 201,
    consoleRoutes: ['connections.domains.store', 'environment.connections.domains.store'],
    consoleGate: ConsoleGate::Administer,
)]
final readonly class AddSsoDomain implements Action, Preflight
{
    /** A real, dotted hostname — no scheme, no path, no '@'. */
    private const string HOSTNAME = '/^([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/';

    public function __construct(private DomainVerification $domains) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('organization_id')->required()->max(64)->describe('The organization claiming it.'),
            Field::string('domain')->required()->max(253)->describe('The email domain, e.g. acme.com.'),
        ]);
    }

    /** Whose it is, whether they have SSO, and whether the domain is one — before any approval. */
    public function preflight(ActionContext $context): void
    {
        self::checked($context);
    }

    public function handle(ActionContext $context): ActionResult
    {
        [$organizationId, $domain] = self::checked($context);

        try {
            $record = $this->domains->add($organizationId, $domain);
        } catch (DomainAlreadyClaimed) {
            throw ActionRefused::because('domain_claimed', 'That domain is already claimed by another organization.', 'domain');
        }

        $record->refresh();

        return ActionResult::item($record, SsoFields::presentDomain($record));
    }

    /**
     * The organization and the domain, normalized — refused when either cannot be claimed.
     *
     * @return array{string, string}
     *
     * @throws ActionRefused
     */
    private static function checked(ActionContext $context): array
    {
        $organizationId = EnterpriseReach::requiredOrganization($context);

        EnterpriseReach::assertEntitled($organizationId, 'sso');

        $domain = strtolower(trim($context->string('domain')));

        if (preg_match(self::HOSTNAME, $domain) !== 1) {
            throw ActionRefused::because('invalid_domain', 'Enter a valid domain, e.g. acme.com.', 'domain');
        }

        return [$organizationId, $domain];
    }
}
