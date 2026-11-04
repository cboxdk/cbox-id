<?php

declare(strict_types=1);

namespace App\Actions\Account;

use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionPlane;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\ConsoleGate;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;
use App\Platform\AppCatalog\AppCatalog;
use Illuminate\Auth\Access\AuthorizationException;

#[AsAction(name: 'account.apps.list', summary: 'List the published apps visible to you in this environment. Optional organization context must be one you actively belong to.', scope: 'account:apps:read', danger: Danger::Read, plane: ActionPlane::Account, rest: ['GET', '/apps'], consoleGate: ConsoleGate::Person, tag: 'App catalog')]
final readonly class ListMyApps implements Action
{
    public function __construct(private AppCatalog $catalog) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([Field::string('organization_id')->nullable()->max(64)]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $organization = $context->nullableString('organization_id');
        $confined = $context->principal->confinedToOrganization();
        if ($confined !== null && $organization !== null && $confined !== $organization) {
            throw new AuthorizationException('This token cannot select another organization.');
        }
        $apps = $this->catalog->forUser(AsPerson::subjectId($context->principal), $confined ?? $organization);

        return ActionResult::page($apps, $apps, ['revision' => hash('sha256', json_encode($apps, JSON_THROW_ON_ERROR)), 'refresh_after' => 60]);
    }
}
