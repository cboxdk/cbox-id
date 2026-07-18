<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Account;

use App\Http\Controllers\Controller;
use App\Platform\AccountApiContext;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Platform\Contracts\Accounts;
use Illuminate\Http\JsonResponse;

/**
 * Account plane › the account itself. Returns the account and its plan usage — the
 * programmatic view of the workspace console's billing summary.
 */
final class AccountController extends Controller
{
    public function show(AccountApiContext $context, Accounts $accounts): JsonResponse
    {
        $account = $context->key()?->account;

        if ($account === null) {
            return response()->json(['error' => 'not_found', 'message' => 'Account not found.'], 404);
        }

        $payload = [
            'id' => $account->id,
            'name' => $account->name,
            'status' => $account->status,
        ];

        // The plan/usage block is billing data — included only for a key whose role
        // may read billing (a Developer/CI key gets the account identity, not the plan).
        if ($context->role()?->canReadBilling() ?? false) {
            $used = Environment::query()->where('account_id', $account->id)->count();

            $payload['plan'] = [
                'environment_limit' => $account->environment_limit,
                'environments_used' => $used,
                'environments_remaining' => $accounts->remainingEnvironments($account),
            ];
        }

        return response()->json(['data' => $payload]);
    }
}
