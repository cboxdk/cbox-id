<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Http\Props\Shared\HelpProps;
use App\Platform\CurrentUser;
use App\Platform\Onboarding\SetupChecklist;
use App\Platform\Onboarding\SetupProgress;
use App\Platform\Onboarding\SetupStep;
use Illuminate\Http\RedirectResponse;
use Inertia\Response;

/**
 * THE GUIDED FIRST RUN — the same measured steps as the dashboard card, given room to
 * explain themselves.
 *
 * IT IS A GUIDE, NOT A WIZARD: every step links out to the real page that does the work,
 * and nothing is gated behind finishing the one before it. A wizard that owns the flow has
 * to reimplement each screen inside itself, drifts from the real one the moment either
 * changes, and traps somebody who only wanted step three. This stays a map — it says what
 * to do, in what order, and gets out of the way.
 *
 * NOTHING IS MARKED COMPLETE BY HAND. Each step is measured from the state it is about, so
 * the list cannot claim an organization has done something it has not.
 */
final readonly class GetStartedController extends ConsoleController
{
    public function index(SetupChecklist $checklist): Response
    {
        $me = $this->assertAdmin();

        $organizationId = $me->organizationId();
        $progress = $organizationId !== null ? $checklist->for($organizationId) : new SetupProgress([]);

        $organization = $me->organization();

        return $this->page('console/get-started', 'Set up '.($organization === null ? 'your organization' : $organization->name), [
            'eyebrow' => 'Getting started',
            'steps' => array_map(static fn (SetupStep $step): array => [
                'key' => $step->key->value,
                'title' => $step->title(),
                'description' => $step->description(),
                'href' => route($step->route()),
                'actionLabel' => $step->actionLabel(),
                'done' => $step->done,
                'help' => HelpProps::for($step->helpTopic()),
            ], $progress->steps),
            'completed' => $progress->completed(),
            'total' => $progress->total(),
            'percent' => $progress->percent(),
            'isComplete' => $progress->isComplete(),
            'nextTitle' => $progress->next()?->title(),
            'auditHref' => route('audit'),
            'governanceHref' => route('governance'),
            'dismissHref' => route('get-started.dismiss'),
        ]);
    }

    /** Put the guidance away — the dashboard card goes with it. */
    public function dismiss(SetupChecklist $checklist): RedirectResponse
    {
        $me = $this->assertAdmin();

        $organizationId = $me->organizationId();

        if ($organizationId === null) {
            return back();
        }

        $checklist->dismiss($organizationId, $me->id());

        return to_route('dashboard')->with('status', 'Setup guidance hidden.');
    }

    /** Every step is an administrative action; a plain member has nothing to do here. */
    private function assertAdmin(): CurrentUser
    {
        $me = app(CurrentUser::class);

        abort_unless($me->isAdmin(), 403);

        return $me;
    }
}
