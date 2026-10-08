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
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Identity\Models\WebAuthnCredential;
use Cbox\Id\Identity\ValueObjects\LinkedIdentity;
use Illuminate\Database\Eloquent\Model;

/**
 * Disconnect a social account (Google, GitHub, …) from yours.
 *
 * LAST-FACTOR GUARD: never strip the only remaining way to sign in. Somebody left with no
 * password, no passkey and no linked identity cannot get back in, so that is refused —
 * add a password or a passkey first. CRITICAL, because it changes how you sign in; a
 * provider that is not linked is not found.
 */
#[AsAction(
    name: 'account.social.unlink',
    summary: 'Disconnect a social account from yours — never the last way you can sign in.',
    scope: 'account:sign_in:write',
    danger: Danger::Critical,
    plane: ActionPlane::Account,
    rest: ['DELETE', '/social/{provider}'],
    status: 204,
    consoleRoutes: ['account.social.destroy'],
    consoleGate: ConsoleGate::Person,
    tag: 'Sign-in methods',
)]
final readonly class UnlinkSocialAccount implements Action
{
    public function __construct(private Subjects $subjects) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('provider')->inPath()->describe('The provider\'s key, for example `google`.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $subjectId = AsPerson::subjectId($context->principal);
        $identity = 'social:'.$context->string('provider');
        $linked = collect($this->subjects->linkedIdentities($subjectId));

        if (! $linked->contains(static fn (LinkedIdentity $each): bool => $each->provider === $identity)) {
            throw ActionRefused::notFound('linked account');
        }

        $othersRemain = $linked->reject(static fn (LinkedIdentity $each): bool => $each->provider === $identity)->isNotEmpty();
        $hasPasskey = WebAuthnCredential::query()->where('user_id', $subjectId)->exists();

        if (! $othersRemain && ! $hasPasskey && ! self::hasPassword($subjectId)) {
            throw ActionRefused::because('last_sign_in_method', 'This is your only sign-in method — add a password or passkey before disconnecting it.', 'provider');
        }

        $this->subjects->unlink($subjectId, $identity);

        return ActionResult::none();
    }

    private static function hasPassword(string $subjectId): bool
    {
        $model = config('cbox-id.models.user');

        // config() is untyped; is_a(..., allow_string: true) is what turns the value into a
        // class-string<Model> the query builder can be resolved against.
        if (! is_string($model) || ! is_a($model, Model::class, true)) {
            return false;
        }

        return $model::query()->whereKey($subjectId)->value('password') !== null;
    }
}
