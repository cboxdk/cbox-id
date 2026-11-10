<?php

declare(strict_types=1);

namespace App\Actions\AppCatalog;

use App\Actions\Account\ListMyApps;
use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionRefused;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\ConsoleGate;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;
use App\Platform\AppCatalog\AppCatalog;
use Cbox\Id\Identity\Contracts\Subjects;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * The app catalog one person sees in one of their organizations, read by an app's backend
 * with its own client-credentials token — so an app's switcher never needs the person's
 * `account:apps:read` grant, a reconnect or a sign-in hop.
 *
 * The same items, order and envelope as {@see ListMyApps} (`GET /api/v1/me/apps`), so a
 * client parses both alike. A person who does not exist, is not active, or is not an active
 * member of the organization is one answer — 404 — so the call says nothing about which.
 *
 * A hot read: no audit row, and only local queries.
 */
#[AsAction(name: 'app_catalog.for_user', summary: 'List the published apps one person sees in one of their organizations, in catalog order. For an app\'s backend, with its own client-credentials token.', scope: 'app_catalog:resolve', danger: Danger::Read, rest: ['GET', '/app-catalog/for-user'], consoleGate: ConsoleGate::EnvironmentAdmin, tag: 'App catalog')]
final readonly class ResolveCatalogForUser implements Action
{
    public function __construct(private AppCatalog $catalog, private Subjects $subjects) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('user_id')->required()->max(64)->describe('The person, by their Cbox ID user id.'),
            Field::string('organization_id')->required()->max(64)->describe('The organization they are working in, which they must be an active member of.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $userId = $context->string('user_id');

        if (! $this->subjects->isActive($userId)) {
            throw ActionRefused::notFound('member');
        }

        try {
            $catalog = $this->catalog->catalogFor($userId, $context->string('organization_id'));
        } catch (AuthorizationException) {
            throw ActionRefused::notFound('member');
        }

        return ActionResult::page($catalog['apps'], $catalog['apps'], AppCatalog::meta($catalog));
    }
}
