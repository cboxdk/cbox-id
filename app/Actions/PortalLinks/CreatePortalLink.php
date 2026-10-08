<?php

declare(strict_types=1);

namespace App\Actions\PortalLinks;

use App\Http\Resources\Environment\Timestamp;
use App\Mail\PortalLinkMail;
use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\ConsoleGate;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;
use App\Platform\AdminPortal;
use App\Platform\Enterprise\EnterpriseReach;
use App\Platform\Enums\PortalIntent;
use App\Platform\Enums\PortalScope;
use App\Platform\Locale\HostedLocale;
use App\Platform\Locale\HostedLocales;
use Carbon\CarbonImmutable;
use Cbox\Id\Organization\Models\Organization;
use Illuminate\Support\Facades\Mail;

/**
 * Mint a one-time ADMIN PORTAL link: the way an organization's own IT administrator sets up
 * what the link's INTENTS cover — single sign-on and its domains, directory sync, domain
 * verification, log streams, SAML certificate renewal ({@see PortalIntent}) — without an
 * account here. The link is the whole credential.
 *
 * Critical, and the URL is in `redact`: whoever holds it can, once, configure how a whole
 * organization signs in. It is shown in this answer only — only its hash is stored — and an
 * idempotent replay returns everything but the URL. The link is single-use, and redeeming it
 * re-checks the organization's plan, so a lapsed plan cannot be set up through a link
 * minted before it lapsed.
 *
 * HOW LONG IT WAITS is the minter's call (`expires_in_minutes`, five minutes to a week): a
 * link handed over on a call can be short, one sent by mail cannot. Once opened, the setup
 * session lasts its own window regardless ({@see AdminPortal::redeem()}).
 *
 * AND WHERE IT GOES: `email` mails it to the customer's IT contact, in `locale` — a hosted
 * mail, so it is written in their language rather than the console's. The link and the
 * trail both record the address.
 *
 * {@see AdminPortal::issue()} records `portal_link.created` with whoever minted it: the
 * person in the console, the key over the API.
 */
#[AsAction(
    name: 'organizations.portal_links.create',
    summary: 'Create a one-time Admin Portal link an organization\'s IT administrator uses to set up SSO, directory sync, domain verification, log streams or SAML certificate renewal without an account. The URL is shown once.',
    scope: 'portal_links:write',
    danger: Danger::Critical,
    schema: 'PortalLink',
    tag: 'Admin Portal',
    rest: ['POST', '/organizations/{organization_id}/portal-links'],
    status: 201,
    consoleRoutes: ['connections.invite', 'environment.connections.invite', 'directories.invite', 'environment.directories.invite', 'environment.organizations.portal-links.store'],
    consoleGate: ConsoleGate::Administer,
    redact: ['url'],
)]
final readonly class CreatePortalLink implements Action
{
    public function __construct(
        private AdminPortal $portal,
        private HostedLocales $locales,
    ) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('organization_id')->inPath()->max(64)->describe('The organization the link sets up.'),
            Field::list('intents', Field::string('intent')->oneOf(PortalIntent::values()))->required()->min(1)->distinct()
                ->describe('What the link may set up: sso (connection and email domains), dsync (directory sync over SCIM), domain_verification, log_streams, certificate_renewal (a SAML connection\'s signing certificate).'),
            Field::integer('expires_in_minutes')->nullable()->min(AdminPortal::MIN_TTL_MINUTES)->max(AdminPortal::MAX_TTL_MINUTES)
                ->describe('How long the link may wait to be opened, in minutes — 5 to 10080 (a week). Left out, the deployment\'s default (30).'),
            Field::string('email')->nullable()->max(254)->format('email')->describe('Mail the link to this address — the customer\'s IT contact. Left out, nothing is sent.'),
            Field::string('locale')->nullable()->oneOf(HostedLocale::codes())->describe('The language of that mail. Left out, the environment\'s default language.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $organizationId = EnterpriseReach::requiredOrganization($context, inPath: true);
        $scope = PortalScope::of(array_map(
            static fn (mixed $value): PortalIntent => PortalIntent::from(is_string($value) ? $value : ''),
            $context->array('intents'),
        ));

        // Every intent the link covers, not merely one of them: a link that opens a setup
        // screen the plan does not include is a dead end handed to somebody outside.
        foreach ($scope->intents as $intent) {
            if ($intent->entitlement() !== null) {
                EnterpriseReach::assertEntitled($organizationId, $intent->entitlement());
            }
        }

        $email = $context->nullableString('email');
        $minutes = $context->has('expires_in_minutes') && is_int($context->input['expires_in_minutes'])
            ? $context->input['expires_in_minutes']
            : null;

        ['link' => $link, 'token' => $token] = $this->portal->issue($organizationId, $scope, $context->principal->id(), $minutes, $email);

        $url = route('portal.enter', $token);

        if ($email !== null) {
            $this->mail($email, $organizationId, $scope, $url, $link->expires_at->toImmutable(), $context->nullableString('locale'));
        }

        return ActionResult::item($url, [
            'id' => $link->id,
            'organization_id' => $organizationId,
            'intents' => $scope->values(),
            'url' => $url,
            'expires_at' => Timestamp::of($link->expires_at),
            'emailed_to' => $email,
        ]);
    }

    /**
     * Send the link to the IT contact. Sent INSIDE the action's transaction on purpose: a
     * mail that cannot be handed to the mailer fails the whole mint, rather than leaving a
     * link that exists, is recorded as sent, and reached nobody.
     */
    private function mail(string $email, string $organizationId, PortalScope $scope, string $url, CarbonImmutable $expiresAt, ?string $locale): void
    {
        $name = Organization::query()->whereKey($organizationId)->value('name');
        $language = HostedLocale::fromTag($locale) ?? $this->locales->default();

        Mail::to($email)->locale($language->value)->send(new PortalLinkMail(
            organization: is_string($name) ? $name : '',
            url: $url,
            intents: $scope->values(),
            expiresAt: $expiresAt,
        ));
    }
}
