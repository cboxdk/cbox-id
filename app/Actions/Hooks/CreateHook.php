<?php

declare(strict_types=1);

namespace App\Actions\Hooks;

use App\Http\Resources\Environment\InlineHookResource;
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
use App\Platform\Integrations\IntegrationAudit;
use App\Platform\Integrations\IntegrationReach;
use App\Platform\Integrations\OutboundUrl;
use Cbox\Id\ExternalActions\Contracts\ExternalActions;
use Cbox\Id\ExternalActions\Enums\HookPoint;
use Cbox\Id\ExternalActions\Exceptions\UnsafeActionUrl;

/**
 * Register a hook — an endpoint this platform calls SYNCHRONOUSLY at a hook point,
 * while somebody waits at a sign-in screen, and whose answer can add claims or refuse the
 * operation — and hand over its signing secret, once.
 *
 * CRITICAL: it puts an outside endpoint inside the sign-in path, and registration is the
 * only way to its secret (there is no rotation on this resource). An environment-wide hook
 * runs for EVERY organization, and at most points can refuse their sign-ins, so an
 * organization's administrator may never create one ({@see IntegrationReach::owner()}).
 *
 * {@see ExternalActions} records the registration on the trail as the system; this records
 * who asked for it, as `inline_hook.created`.
 */
#[AsAction(
    name: 'hooks.create',
    summary: 'Register a hook at a hook point (token minting, login, registration, password change). Returns its signing secret once.',
    scope: 'hooks:write',
    danger: Danger::Critical,
    schema: 'InlineHook',
    tag: 'Hooks',
    rest: ['POST', '/hooks'],
    status: 201,
    consoleRoutes: ['hooks.store', 'environment.hooks.store'],
    consoleGate: ConsoleGate::Administer,
    redact: ['secret'],
)]
final readonly class CreateHook implements Action, Preflight
{
    public function __construct(
        private ExternalActions $hooks,
        private IntegrationAudit $audit,
    ) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('hook_point')->required()->oneOf(array_map(static fn (HookPoint $point): string => $point->value, HookPoint::cases()))->describe('When it is called.'),
            Field::string('url')->required()->max(500)->format('uri')->describe('A public HTTPS URL, called in the middle of the operation.'),
            ...IntegrationReach::ownerFields(),
        ]);
    }

    /** Whose it is and whether the URL may be called — before anyone approves it. */
    public function preflight(ActionContext $context): void
    {
        IntegrationReach::owner($context);
        OutboundUrl::assertHook(trim($context->string('url')));
    }

    public function handle(ActionContext $context): ActionResult
    {
        $organizationId = IntegrationReach::owner($context);
        $point = HookPoint::from($context->string('hook_point'));
        $url = trim($context->string('url'));

        IntegrationReach::assertUrl($url);

        try {
            // Two calls rather than one with a nullable argument: "for every tenant here"
            // is not an organization that happens to be null.
            $registered = $organizationId === null
                ? $this->hooks->registerForEnvironment($point, $url)
                : $this->hooks->register($point, $url, $organizationId);
        } catch (UnsafeActionUrl) {
            throw ActionRefused::because('unsafe_url', 'That URL is not allowed — it must be a public HTTPS endpoint.', 'url');
        }

        $endpoint = $registered->endpoint;

        $this->audit->record(IntegrationAudit::HOOK_CREATED, 'inline_hook', $endpoint->id, $endpoint->organization_id, $context->actor(), [
            'hook_point' => $point->value,
            'url' => $endpoint->url,
        ]);

        return ActionResult::item($registered, InlineHookResource::from($endpoint, $registered->secret));
    }
}
