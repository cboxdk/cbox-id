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
use App\Platform\Passkeys\PasskeyName;
use App\Platform\Passkeys\RecordsPasskeyUse;
use Carbon\CarbonInterface;
use Cbox\Id\Identity\Models\WebAuthnCredential;
use Cbox\Id\Kernel\Audit\Contracts\AuditLog;
use Cbox\Id\Kernel\Audit\Enums\ActorType;
use Cbox\Id\Kernel\Audit\ValueObjects\AuditEvent;

/**
 * Rename one of your passkeys — "Work laptop", "Phone", "YubiKey on my keyring".
 *
 * A label and nothing more: it changes no credential and grants nothing, so it asks for no
 * fresh password in the console (the way your display name does not), and a token holding
 * `account:sign_in:write` may do it. Looked up WITH you in the query, like removing one,
 * so somebody else's passkey is not found.
 *
 * The name is trimmed and held to {@see PasskeyName}'s rule — one to sixty-four
 * characters, no control characters — at every door, and recorded in the audit trail with
 * the name it replaced.
 */
#[AsAction(
    name: 'account.passkeys.rename',
    summary: 'Rename one of your passkeys.',
    scope: 'account:sign_in:write',
    danger: Danger::Write,
    plane: ActionPlane::Account,
    rest: ['PATCH', '/passkeys/{passkey_id}'],
    consoleRoutes: ['account.passkeys.update'],
    consoleGate: ConsoleGate::Person,
    schema: 'Passkey',
    tag: 'Sign-in methods',
)]
final readonly class RenamePasskey implements Action
{
    public function __construct(private AuditLog $audit) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('passkey_id')->inPath(),
            Field::string('name')->required()->min(1)->max(PasskeyName::MAX)
                ->describe('What to call the passkey: one to '.PasskeyName::MAX.' characters, no line breaks.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $subjectId = AsPerson::subjectId($context->principal);
        $name = trim($context->string('name'));
        $problem = PasskeyName::problem($name);

        if ($problem !== null) {
            throw ActionRefused::because('invalid_name', $problem, 'name');
        }

        $passkey = WebAuthnCredential::query()
            ->where('user_id', $subjectId)
            ->whereKey($context->string('passkey_id'))
            ->first();

        if ($passkey === null) {
            throw ActionRefused::notFound('passkey');
        }

        $previous = $passkey->name;

        if ($previous !== $name) {
            $passkey->forceFill(['name' => $name])->save();

            $this->audit->record(new AuditEvent(
                action: 'user.passkey_renamed',
                actorType: ActorType::User,
                actorId: $subjectId,
                targetType: 'user',
                targetId: $subjectId,
                context: ['passkey_id' => $passkey->id, 'from' => $previous, 'to' => $name],
            ));
        }

        $created = $passkey->getAttribute('created_at');

        return ActionResult::item($passkey, [
            'id' => $passkey->id,
            'name' => $name,
            'created_at' => $created instanceof CarbonInterface ? $created->toIso8601String() : null,
            'last_used_at' => RecordsPasskeyUse::lastUsedAt($passkey)?->toIso8601String(),
        ]);
    }
}
