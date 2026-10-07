<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionRefused;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;
use App\Platform\Erasure\AppErasureSteps;
use Cbox\Id\Identity\Contracts\SubjectEraser;
use Cbox\Id\Identity\Exceptions\ErasureRefused;
use Cbox\Id\Identity\Models\User;
use Cbox\Id\Identity\ValueObjects\ErasureReceipt;

/**
 * Erase a person — GDPR Art. 17, the right to erasure — from every store this platform
 * keeps them in, in one transaction, and hand back the receipt.
 *
 * WHAT HAPPENS is the framework's {@see SubjectEraser}: sessions and tokens revoked (relying
 * parties get back-channel logout), credentials, memberships, role grants, API tokens,
 * vault secrets and stored payload copies deleted, the user row pseudonymised in place (the
 * id is kept; email and name become placeholders), a `user.erased` tombstone on the trail
 * and a `user.erased` event that downstream SCIM answers with a DELETE. The stores this app
 * adds on top — devices, sign-in tickets, onboarding state, risk records — are erasure
 * steps of their own ({@see AppErasureSteps}), so they go in the same call and roll back
 * with it.
 *
 * WHAT IT DOES NOT DO is rewrite the audit trail: every column of an entry is inside its
 * hash, so past entries keep the person's opaque id, which identifies nobody once the row
 * it points at is pseudonymised. The chain still verifies afterwards.
 *
 * CRITICAL, and on a scope of its own (`users:erase`) rather than `users:write`: a key that
 * can create and deactivate people has no business being able to make one disappear, and
 * nothing undoes this — not a reactivation, not anything short of a database restore.
 *
 * REFUSED for the only owner of an organization: erasing them would leave it with nobody
 * who can invite, transfer or archive. Transfer ownership first. Running it again on a
 * person already erased changes nothing and returns a fresh receipt.
 */
#[AsAction(
    name: 'users.erase',
    summary: 'Erase a person (GDPR Art. 17): revoke their sessions and tokens, delete their credentials, memberships and personal data, and pseudonymise their account. Cannot be undone.',
    scope: 'users:erase',
    danger: Danger::Critical,
    schema: 'ErasureReceipt',
    tag: 'Users',
    rest: ['POST', '/users/{id}/erase'],
    consoleRoutes: ['environment.users.erase'],
)]
final readonly class EraseUser implements Action
{
    public function __construct(private SubjectEraser $eraser) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('id')->inPath()->describe('The user\'s id.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        // Environment-scoped: an id from another environment is a 404, never an erasure.
        $user = User::query()->whereKey($context->string('id'))->first() ?? throw ActionRefused::notFound('user');

        try {
            $receipt = $this->eraser->erase($user->id, $context->actor());
        } catch (ErasureRefused $refused) {
            throw new ActionRefused(
                'last_owner',
                'This person is the only owner of '.count($refused->organizationIds()).' organization(s): '
                    .implode(', ', $refused->organizationIds()).'. Transfer ownership before erasing them.',
                409,
            );
        }

        return ActionResult::item($receipt, self::present($receipt));
    }

    /**
     * The receipt — `ErasureReceipt` in the spec. No personal data: the opaque id, the
     * stores each step touched, in numbers.
     *
     * The counts are an OBJECT on the wire even when a step found nothing: PHP encodes an
     * empty map as `[]`, and a client reading `counts.passkeys` off a list gets nothing.
     *
     * @return array<string, mixed>
     */
    public static function present(ErasureReceipt $receipt): array
    {
        $data = $receipt->toArray();

        $data['steps'] = array_map(static fn (array $step): array => [
            'step' => $step['step'],
            'counts' => (object) $step['counts'],
            'note' => $step['note'],
        ], $data['steps']);

        return $data;
    }
}
