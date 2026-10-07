<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Actions\Platform\Operators\CreateOperator;
use App\Actions\Platform\Operators\SetOperatorStatus;
use App\Http\Props\Shared\HelpProps;
use App\Http\Requests\Console\CreateOperatorRequest;
use App\Platform\Console\LikeTerm;
use App\Platform\Help\HelpTopic;
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
            'help' => HelpProps::for(HelpTopic::Operators),
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

    /** Add an operator through {@see CreateOperator}, the action the operator API runs too. */
    public function store(CreateOperatorRequest $request): RedirectResponse
    {
        $this->assertOperator();

        $result = $this->act(CreateOperator::class, [
            'name' => $request->name(),
            'email' => $request->email(),
            'password' => $request->password(),
        ], ['name' => 'name', 'email' => 'email', 'password' => 'password'], 'email');

        return $result instanceof RedirectResponse ? $result : back()->with('status', 'Operator created.');
    }

    /**
     * Suspend an operator, or bring one back — through {@see SetOperatorStatus}, asked for
     * the opposite of the state the row shows.
     *
     * BOTH REFUSALS ARE SPELT OUT, by the action, because both are recoverable states
     * somebody has to understand rather than errors: suspending yourself would lock you out
     * of the console you are standing in, and suspending the last active operator would
     * lock everyone out of it permanently.
     */
    public function toggle(string $operator): RedirectResponse
    {
        $this->assertOperator();

        $model = PlatformOperator::query()->find($operator);

        abort_if($model === null, 404);

        $result = $this->act(SetOperatorStatus::class, [
            'operator_id' => $model->id,
            'status' => $model->isActive() ? 'suspended' : 'active',
        ], fallback: 'operator');

        if ($result instanceof RedirectResponse) {
            return $result;
        }

        return back()->with('status', $model->isActive() ? 'Operator suspended.' : 'Operator reactivated.');
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
