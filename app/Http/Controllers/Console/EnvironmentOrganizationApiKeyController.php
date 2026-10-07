<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Actions\CustomerApiKeys\RevokeCustomerApiKey;
use App\Platform\EnvironmentAdminAuth;
use Cbox\Id\Organization\Models\Organization;
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
 * The same action the management API revokes one with, with the organization in the URL
 * made part of the key lookup: a key id from a different organization's page, pasted under
 * this one, is not found and revokes nothing.
 */
final readonly class EnvironmentOrganizationApiKeyController extends ConsoleController
{
    public function destroy(string $organization, string $key): RedirectResponse
    {
        abort_if(app(EnvironmentAdminAuth::class)->membership() === null, 403);
        abort_if(Organization::query()->whereKey($organization)->doesntExist(), 404);

        $result = $this->act(RevokeCustomerApiKey::class, ['id' => $key, 'organization_id' => $organization]);

        return $result instanceof RedirectResponse
            ? $result
            : back()->with('status', 'API key revoked. Whatever was using it stops working now.');
    }
}
