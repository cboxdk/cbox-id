<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Platform\ApiKeys\MemberApiKeys;
use App\Platform\EnvironmentAdminAuth;
use Cbox\Id\Organization\Models\Organization;
use Cbox\Id\Organization\ValueObjects\ApiKeyActor;
use Illuminate\Http\RedirectResponse;

/**
 * ENVIRONMENT CONSOLE › ORGANIZATIONS › one organization › revoking an API key one of its
 * people holds.
 *
 * The list itself is on the organization's page ({@see EnvironmentOrganizationController::show()}).
 * This is its one write, kept out of that controller because it is the only key action
 * there is: an environment administrator sees and stops keys, and — like an organization's
 * own administrators — never mints one in somebody else's name.
 *
 * The organization in the URL is resolved first and then made part of the key lookup, so a
 * key id from a different organization's page, pasted under this one, revokes nothing.
 */
final readonly class EnvironmentOrganizationApiKeyController extends ConsoleController
{
    public function destroy(string $organization, string $key, MemberApiKeys $keys): RedirectResponse
    {
        abort_if(app(EnvironmentAdminAuth::class)->membership() === null, 403);

        $model = Organization::query()->whereKey($organization)->first();

        abort_if($model === null, 404);

        $actor = $this->scope->auditActor();

        if (! $keys->revoke($model->id, $key, new ApiKeyActor($actor->type, $actor->id))) {
            return back();
        }

        return back()->with('status', 'API key revoked. Whatever was using it stops working now.');
    }
}
