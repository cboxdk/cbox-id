<?php

declare(strict_types=1);

namespace App\Platform;

use App\Http\Middleware\AuthenticateAccountApi;
use Cbox\Id\Platform\Enums\AccountRole;
use Cbox\Id\Platform\Models\AccountApiKey;

/**
 * The account API key authenticated for the current request — the machine
 * equivalent of an account member's session, on the global management plane.
 * Deliberately NOT environment-scoped: an account key operates above every
 * environment its account owns. Bound per-request (scoped) and populated by
 * {@see AuthenticateAccountApi}.
 */
final class AccountApiContext
{
    private ?AccountApiKey $key = null;

    public function set(AccountApiKey $key): void
    {
        $this->key = $key;
    }

    public function key(): ?AccountApiKey
    {
        return $this->key;
    }

    public function accountId(): ?string
    {
        return $this->key?->account_id;
    }

    public function role(): ?AccountRole
    {
        return $this->key?->role;
    }
}
