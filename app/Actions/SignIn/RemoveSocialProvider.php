<?php

declare(strict_types=1);

namespace App\Actions\SignIn;

use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionRefused;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\ConsoleGate;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;
use App\Platform\Actions\OrganizationTarget;
use App\Platform\SignInAudit;
use Cbox\Id\Federation\Models\Connection;
use Illuminate\Database\Eloquent\Builder;

/**
 * Stop offering a social login provider. Anyone who signed in with it keeps their account
 * and can still use their password.
 *
 * THE OWNER IS IN THE QUERY, not in an `if` after it — the shape that once shipped a
 * cross-organization IDOR elsewhere. Only a CATALOGUE connection is reachable here: a
 * company's own SSO connection is not a social provider, and removing one through this door
 * would end a whole organization's sign-in from the wrong page. Anything else is a 404.
 */
#[AsAction(
    name: 'signin.social.delete',
    summary: 'Stop offering a social login provider. People who used it keep their accounts.',
    scope: 'signin:write',
    danger: Danger::Critical,
    tag: 'Sign-in',
    rest: ['DELETE', '/sign-in/social-providers/{id}'],
    status: 204,
    consoleRoutes: ['social-providers.destroy', 'environment.social-providers.destroy'],
    consoleGate: ConsoleGate::Administer,
)]
final readonly class RemoveSocialProvider implements Action
{
    public function __construct(private SignInAudit $audit) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('id')->inPath()->describe('The provider\'s id.'),
            Field::string('organization_id')->nullable()->max(64)->describe('Only remove it if it is this organization\'s.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $organizationId = OrganizationTarget::check($context, $context->nullableString('organization_id'));

        $connection = Connection::query()
            ->whereKey($context->string('id'))
            ->whereNotNull('provider')
            ->when($organizationId !== null, fn (Builder $query): Builder => $query->where('organization_id', $organizationId))
            ->first() ?? throw ActionRefused::notFound('social provider');

        $connection->delete();

        $this->audit->record(SignInAudit::SOCIAL_PROVIDER_REMOVED, $context->actor(), $connection->organization_id, 'connection', $connection->id, [
            'provider' => $connection->provider,
            'name' => $connection->name,
        ]);

        return ActionResult::none($connection);
    }
}
