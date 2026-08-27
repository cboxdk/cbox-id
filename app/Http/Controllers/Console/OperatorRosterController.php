<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Http\Requests\Console\CreateOperatorRequest;
use App\Platform\Console\LikeTerm;
use Cbox\Id\Platform\Contracts\PlatformOperators;
use Cbox\Id\Platform\Exceptions\CannotSuspendLastOperator;
use Cbox\Id\Platform\Models\PlatformOperator;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;

/**
 * PLATFORM › OPERATORS — the identities above every environment.
 *
 * Operators are never environment-owned, so this list is global: there is no scope to
 * suspend it by, and no tenant whose policy governs them.
 *
 * SEARCH, BUT NO PAGING. The roster is the people who run the deployment — a handful, not a
 * population — so page links would be chrome over a single page. The search earns its place
 * because an operator looks a colleague up by name or address.
 */
final readonly class OperatorRosterController extends ConsoleController
{
    public function index(Request $request): Response
    {
        $this->assertOperator();

        $term = trim($request->string('q')->toString());

        $operators = PlatformOperator::query()
            ->when($term !== '', function (Builder $query) use ($term): void {
                // Grouped, so the predicate cannot be stranded behind the OR — and through
                // LikeTerm, because an address is the one column almost guaranteed to carry
                // a literal underscore.
                $like = LikeTerm::containing($term);

                $query->where(function (Builder $inner) use ($like): void {
                    $inner->whereRaw($like->sqlFor('name'), [$like->pattern])
                        ->orWhereRaw($like->sqlFor('email'), [$like->pattern]);
                });
            })
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $currentId = $this->scope->operator()?->id;

        return $this->page('console/platform/operators', 'Operators', [
            'operators' => $operators->map(fn (PlatformOperator $operator): array => [
                'id' => $operator->id,
                'name' => $operator->name,
                'email' => $operator->email,
                'active' => $operator->isActive(),
                'lastLogin' => $operator->last_login_at?->diffForHumans(),
                // The row's own answer. You cannot suspend the operator you are signed in
                // as, and the control is not drawn for it — the write refuses too.
                'isSelf' => $operator->id === $currentId,
                'toggleHref' => route('platform.operators.toggle', $operator->id),
            ])->all(),
            'search' => $term,
            'storeHref' => route('platform.operators.store'),
        ]);
    }

    public function store(CreateOperatorRequest $request, PlatformOperators $operators): RedirectResponse
    {
        $this->assertOperator();

        if ($operators->findByEmail($request->email()) !== null) {
            return back()->withInput()->withErrors(['email' => 'An operator with that email already exists.']);
        }

        $operators->create($request->email(), $request->password(), $request->name());

        return back()->with('status', 'Operator created.');
    }

    /**
     * Suspend an operator, or bring one back.
     *
     * BOTH REFUSALS ARE SPELT OUT, because both are recoverable states somebody has to
     * understand rather than errors: suspending yourself would lock you out of the console
     * you are standing in, and suspending the last active operator would lock everyone out
     * of it permanently.
     */
    public function toggle(string $operator, PlatformOperators $operators): RedirectResponse
    {
        $this->assertOperator();

        $actorId = $this->scope->operator()?->id;

        abort_if($actorId === null, 403);

        $model = PlatformOperator::query()->find($operator);

        if ($model === null) {
            return back();
        }

        if (! $model->isActive()) {
            $operators->reactivate($model->id, $actorId);

            return back()->with('status', 'Operator reactivated.');
        }

        // Checked BEFORE the contract call, so it cannot be reached at all.
        if ($model->id === $actorId) {
            return back()->withErrors([
                'operator' => 'You cannot suspend the operator you are currently signed in as.',
            ]);
        }

        try {
            // Through the contract so the change is audited; it refuses to suspend the final
            // active operator on its own terms.
            $operators->suspend($model->id, $actorId);
        } catch (CannotSuspendLastOperator) {
            return back()->withErrors([
                'operator' => 'You cannot suspend the last active operator — the console would lock everyone out.',
            ]);
        }

        return back()->with('status', 'Operator suspended.');
    }

    /**
     * 404, not 403: the platform console does not confirm to a stranger that this
     * deployment has a staff console at that address.
     */
    private function assertOperator(): void
    {
        abort_unless($this->scope->isPlatformOperator(), 404);
    }
}
