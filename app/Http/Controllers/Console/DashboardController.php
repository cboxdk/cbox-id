<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Http\Props\Shared\HelpProps;
use App\Platform\AppLauncher;
use App\Platform\AuditNames;
use App\Platform\Console\DashboardCards;
use App\Platform\CurrentUser;
use App\Platform\Help\HelpTopic;
use App\Platform\Onboarding\SetupChecklist;
use App\Platform\Onboarding\SetupProgress;
use App\Platform\Onboarding\SetupStep;
use Cbox\Id\Federation\Contracts\Connections;
use Cbox\Id\Kernel\Audit\Models\AuditEntry;
use Cbox\Id\Organization\Contracts\Memberships;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Inertia\Response;

/**
 * WHERE EVERYBODY LANDS.
 *
 * Two pages in one, and the split is AUTHORIZATION rather than layout: an administrator
 * gets the organization's numbers, its activity feed, the module cards and the setup
 * checklist; a plain member gets their apps and a nudge towards their own security, because
 * they neither manage nor need to see the rest.
 *
 * THE MODULE CARDS SIT INSIDE THE ADMIN BRANCH FOR THE SAME REASON. A card is arbitrary
 * module code reading whatever it decides to read, for whoever this page renders for. Each
 * one also narrows to the acting organization itself — four of them did not, and showed one
 * tenant the whole environment's sign-in volume, risk events and audit backlog — but this
 * branch is the layer that keeps a plain member out of the row entirely.
 */
final readonly class DashboardController extends ConsoleController
{
    /** Enough recent activity to recognise the shape of a day; the log is a click away. */
    private const RECENT = 6;

    public function index(
        AppLauncher $launcher,
        Memberships $memberships,
        Connections $connections,
        SetupChecklist $checklist,
        AuditNames $names,
        DashboardCards $cards,
    ): Response {
        $me = app(CurrentUser::class);
        $organizationId = $me->organizationId();
        $isAdmin = $me->isAdmin();

        /** @var Collection<int, AuditEntry> $recent */
        $recent = $organizationId !== null && $isAdmin
            ? AuditEntry::query()
                ->where('organization_id', $organizationId)
                ->orderByDesc('sequence')
                ->limit(self::RECENT)
                ->get()
            : new Collection;

        /*
         * DISMISSAL FIRST. The checklist costs a query per step, and an admin who put it
         * away was paying for all of them on every page load, forever. Only an admin can act
         * on any of the steps, so only an admin is measured at all.
         */
        $shows = $organizationId !== null
            && $isAdmin
            && ! $checklist->isDismissed($organizationId, $me->id());

        $progress = $shows ? $checklist->for($organizationId) : null;

        // The card earns its place only while there is something left to do.
        $progress = $progress !== null && ! $progress->isComplete() ? $progress : null;

        $organization = $me->organization();

        /*
         * "OVERVIEW" IN THE TAB, "Welcome back, Ada" ON THE PAGE — and they are different on
         * purpose. The controller's title is what the browser tab and the nav entry say, and
         * a person restoring twenty tabs needs the word the rail uses; the heading is a
         * greeting, which is the wrong thing to read off a tab strip. Every other page in
         * this console states one string and uses it for both.
         */
        return $this->page('console/dashboard', 'Overview', [
            'heading' => 'Welcome back, '.Str::before($me->name(), ' '),
            /*
             * THE SUBTITLE NAMES THE ORGANIZATION, or says plainly that there is not one.
             * "Here's what's happening across your organization" was the fallback, and it is
             * a sentence about something the reader does not have — harmless while this page
             * was only ever reached by members, and untrue now that the console is served on
             * the platform root, where holding no membership is the ordinary state.
             */
            'greeting' => $organization !== null
                ? 'Here\'s what\'s happening across '.$organization->name.'.'
                : 'You\'re signed in. You don\'t belong to an organization here yet — your account and security settings are all yours.',
            'isAdmin' => $isAdmin,
            'apps' => $launcher->apps(),
            'role' => $me->role()?->label() ?? 'Member',
            'organizationName' => $organization?->name,
            'memberCount' => $organizationId !== null ? $memberships->countForOrganization($organizationId) : 0,
            'ssoActive' => $organizationId !== null && $connections->forOrganization($organizationId) !== null,
            'recent' => $this->recentProps($recent, $names),
            'cards' => $isAdmin ? $cards->resolve() : [],
            'checklist' => $progress === null ? null : $this->checklistProps($progress),
            'help' => HelpProps::for(HelpTopic::Overview),
            'urls' => [
                'audit' => route('audit'),
                'account' => route('account'),
                'getStarted' => route('get-started'),
                'dismissChecklist' => route('dashboard.checklist.dismiss'),
            ],
        ]);
    }

    /**
     * Put the setup checklist away for this admin.
     *
     * Progress itself is never stored, so an admin who dismisses it and later opens the
     * setup guide sees exactly where the organization actually stands — nothing was "reset"
     * by hiding the card.
     */
    public function dismissChecklist(SetupChecklist $checklist): RedirectResponse
    {
        $me = app(CurrentUser::class);
        $organizationId = $me->organizationId();

        if ($organizationId === null || ! $me->isAdmin()) {
            return back();
        }

        $checklist->dismiss($organizationId, $me->id());

        return back()->with('status', 'Setup guide hidden — reach it any time from Settings.');
    }

    /**
     * @param  Collection<int, AuditEntry>  $recent
     * @return list<array<string, mixed>>
     */
    private function recentProps($recent, AuditNames $names): array
    {
        /*
         * Ids resolved to human names so the feed reads like a story ("member added · Ada
         * Lovelace") rather than a wall of ULIDs. Resolved in ONE call for the page, shared
         * with the activity log which needs the identical mapping — this page used to carry
         * its own copy that resolved targets only, and one query per row.
         */
        $labels = $names->for($recent);

        $rows = [];

        foreach ($recent as $entry) {
            $targetId = $entry->target_id;

            $rows[] = [
                'id' => $entry->id,
                'action' => str_replace(['.', '_'], [' · ', ' '], (string) $entry->action),
                // The resolved name, or the type and a truncated id — never a bare ULID
                // with nothing to say what kind of thing it names.
                'subject' => $labels[$targetId] ?? ($targetId === null
                    ? null
                    : Str::headline((string) $entry->target_type).' '.Str::limit((string) $targetId, 24)),
                'when' => $entry->recorded_at?->diffForHumans(),
            ];
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    private function checklistProps(SetupProgress $progress): array
    {
        return [
            'completed' => $progress->completed(),
            'total' => $progress->total(),
            'percent' => $progress->percent(),
            // Lower-cased for the sentence it lands in, which reads "starting with …".
            'nextTitle' => $progress->next() === null ? null : Str::lcfirst($progress->next()->title()),
            'steps' => array_map(static fn (SetupStep $step): array => [
                'key' => $step->key->value,
                'title' => $step->title(),
                'href' => route($step->route()),
                'done' => $step->done,
            ], $progress->steps),
        ];
    }
}
