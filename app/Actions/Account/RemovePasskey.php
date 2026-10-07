<?php

declare(strict_types=1);

namespace App\Actions\Account;

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
use Cbox\Id\Identity\Models\WebAuthnCredential;

/**
 * Remove one of your passkeys — the lost phone, the retired laptop.
 *
 * CRITICAL: it changes how you sign in. Registering one stays a ceremony in the browser
 * (the authenticator itself has to take part); removing one is a plain write, behind a
 * fresh password in the console. Looked up WITH you in the query, so somebody else's
 * passkey is not found — and one already removed is not found either.
 */
#[AsAction(
    name: 'account.passkeys.remove',
    summary: 'Remove one of your passkeys.',
    scope: 'account:sign_in:write',
    danger: Danger::Critical,
    plane: ActionPlane::Account,
    rest: ['DELETE', '/passkeys/{passkey_id}'],
    status: 204,
    consoleRoutes: ['account.passkeys.destroy'],
    consoleGate: ConsoleGate::Person,
    tag: 'Sign-in methods',
)]
final readonly class RemovePasskey implements Action
{
    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('passkey_id')->inPath(),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $deleted = WebAuthnCredential::query()
            ->where('user_id', AsPerson::subjectId($context->principal))
            ->where('id', $context->string('passkey_id'))
            ->delete();

        if ($deleted === 0) {
            throw ActionRefused::notFound('passkey');
        }

        return ActionResult::none();
    }
}
