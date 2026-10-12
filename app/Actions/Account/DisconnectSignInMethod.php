<?php

declare(strict_types=1);

namespace App\Actions\Account;

use App\Platform\AccountLinking\SignInIdentities;
use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionPlane;
use App\Platform\Actions\ActionRefused;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\ConsoleGate;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;
use Cbox\Id\Kernel\Audit\Contracts\AuditLog;
use Cbox\Id\Kernel\Audit\Enums\ActorType;
use Cbox\Id\Kernel\Audit\ValueObjects\AuditEvent;

/**
 * Disconnect ONE linked sign-in identity — "Google (sn@cbox.dk)" — from your account.
 *
 * By the identity's id, not by provider. {@see UnlinkSocialAccount} disconnects by provider
 * key, which is the right address for the deployment operator's own buttons (one per
 * provider) and the wrong one for everything else: an environment's Google and an
 * organization's own Google are both `oidc` identities, and a provider-keyed delete removed
 * every one of them. That action stays, for the clients that already call it; this is what
 * My account › Sign-in methods uses.
 *
 * LAST-WAY-IN GUARD: never strip the only remaining way to sign in. Somebody left with no
 * password, no passkey and no other linked identity cannot get back in, so that is refused
 * with a sentence that says what to add first. An organization's own single sign-on is
 * refused too — it is how the organization knows them, and the next sign-in through it
 * would bring it straight back. CRITICAL, because it changes how you sign in.
 */
#[AsAction(
    name: 'account.sign_in_methods.disconnect',
    summary: 'Disconnect one linked sign-in identity (Google, GitHub, …) from your account — never the last way you can sign in.',
    scope: 'account:sign_in:write',
    danger: Danger::Critical,
    plane: ActionPlane::Account,
    rest: ['DELETE', '/sign-in-methods/{identity_id}'],
    status: 204,
    consoleRoutes: ['account.sign-in-methods.destroy'],
    consoleGate: ConsoleGate::Person,
    tag: 'Sign-in methods',
)]
final readonly class DisconnectSignInMethod implements Action
{
    public function __construct(
        private SignInIdentities $identities,
        private AuditLog $audit,
    ) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('identity_id')->inPath()->describe('The linked identity\'s id, as My account lists it.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $subjectId = AsPerson::subjectId($context->principal);
        $identity = $this->identities->find($subjectId, $context->string('identity_id'));

        if ($identity === null) {
            throw ActionRefused::notFound('sign-in method');
        }

        if (! $identity->removable) {
            throw ActionRefused::because(
                'managed_by_organization',
                $identity->label.' is your organization\'s single sign-on. It is how your organization knows you, so it cannot be disconnected here.',
                'identity_id',
            );
        }

        if ($this->identities->isLastWayIn($subjectId, $identity->id)) {
            throw ActionRefused::because(
                'last_sign_in_method',
                $identity->label.' is your only way to sign in. Set a password or add a passkey first, then disconnect it.',
                'identity_id',
            );
        }

        $this->identities->remove($subjectId, $identity->id);

        $this->audit->record(new AuditEvent(
            action: 'user.identity_unlinked',
            actorType: ActorType::User,
            actorId: $subjectId,
            targetType: 'user',
            targetId: $subjectId,
            context: ['provider' => $identity->providerKey, 'identity_id' => $identity->id],
        ));

        return ActionResult::none();
    }
}
