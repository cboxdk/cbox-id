<?php

declare(strict_types=1);

namespace App\Platform\Directory;

use Closure;

/**
 * Whether the directory push running right now may bring a disabled account back.
 *
 * A directory owns the accounts it provisions — but only the deactivations it CAUSED are
 * its to undo. The framework's sync reactivated whoever a push listed as active, so an
 * account an administrator disabled (a compromised one, say) while the directory still
 * listed the person came back on the next re-push or scheduled pull (audit finding #29).
 *
 * {@see HoldingDirectorySync} reads what the directory last said about the person BEFORE
 * the framework overwrites it, and opens this scope; {@see HeldReactivationSubjects},
 * which the framework's sync is handed instead of the plain contract, asks it.
 *
 * A bridge until cboxdk/laravel-id ships the same rule in `DatabaseDirectorySync` (it
 * reads the previous row itself there); remove these three classes when it does.
 */
final class DirectoryReactivationHold
{
    private ?bool $allowed = null;

    /**
     * Run $push with reactivation allowed or held.
     *
     * @template T
     *
     * @param  Closure(): T  $push
     * @return T
     */
    public function during(bool $allowed, Closure $push): mixed
    {
        $outer = $this->allowed;
        $this->allowed = $allowed;

        try {
            return $push();
        } finally {
            $this->allowed = $outer;
        }
    }

    /** Whether a reactivation asked for now goes ahead. Outside a push, always. */
    public function allows(): bool
    {
        return $this->allowed !== false;
    }
}
