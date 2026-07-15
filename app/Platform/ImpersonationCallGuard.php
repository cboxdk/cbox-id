<?php

declare(strict_types=1);

namespace App\Platform;

use App\Http\Middleware\BlockDuringImpersonation;
use App\Providers\PlatformServiceProvider;

/**
 * Console-wide read-only enforcement for support impersonation.
 *
 * {@see BlockDuringImpersonation} guards a handful of ROUTES,
 * but every console mutation is a Livewire component action POSTed to the single
 * /livewire/update endpoint — route middleware never sees the individual action, so
 * a guard placed there cannot cover them. This guard closes that gap at Livewire's
 * `call` seam (wired in {@see PlatformServiceProvider}): while an
 * impersonation marker is active, EVERY component action is refused (403) unless it
 * is on a tight allowlist of read/navigation primitives.
 *
 * The design is deny-by-default: a newly added mutating action is blocked with no
 * further wiring, so no sink can be missed. That is the "can't-miss-a-sink"
 * property — correctness does not depend on enumerating every write.
 */
final class ImpersonationCallGuard
{
    /**
     * The only actions that stay callable while impersonating. Every entry is a
     * framework primitive that reads or navigates WITHOUT durably mutating tenant
     * state:
     *
     *  - Livewire magic actions — `$set`/`$sync`/`$commit` only touch public
     *    component properties (which the browser re-sends every request anyway),
     *    and `$refresh` re-renders. Every durable write lives in a NAMED action
     *    method, which stays blocked. Setting a property can never, on its own,
     *    persist anything (the codebase has no `updated()` hooks or listeners).
     *  - Paginator navigation — so a supporter can still page through read-only
     *    lists (e.g. the audit trail) via the standard WithPagination methods.
     *
     * Exiting impersonation is a plain controller POST (`impersonation.exit`), not a
     * Livewire action, so it never reaches this guard and always works.
     *
     * @var list<string>
     */
    public const READ_ONLY_ALLOWLIST = [
        '$refresh',
        '$set',
        '$sync',
        '$commit',
        'nextPage',
        'previousPage',
        'gotoPage',
        'setPage',
        'resetPage',
    ];

    public function __construct(private readonly Impersonation $impersonation) {}

    /**
     * Refuse a state-mutating component action while impersonating. A no-op when not
     * impersonating, or when the action is a read/navigation primitive.
     */
    public function guard(string $method): void
    {
        if (! $this->impersonation->isImpersonating()) {
            return;
        }

        if (in_array($method, self::READ_ONLY_ALLOWLIST, true)) {
            return;
        }

        abort(403, 'This action is not available while impersonating a user.');
    }
}
