<?php

declare(strict_types=1);

namespace Cbox\Id\Compliance\Http\Controllers;

use App\Http\Controllers\Console\ConsoleController;
use App\Platform\Console\ConsolePlane;
use App\Platform\OrganizationActivity;
use Cbox\Id\Compliance\Dsr\SubjectDataExport;
use Cbox\Id\Compliance\Models\AuditExportRun;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CONSOLE › EXPORTS & RETENTION — one page, both planes.
 *
 * The RUN HISTORY is environment bookkeeping: an {@see AuditExportRun} records how one
 * scheduled export went across every chain in the environment, and carries no organization.
 * So it is shown on the plane that owns the environment and withheld from the plane that
 * owns one tenant — not because a tenant admin is untrusted, but because "17 scopes scanned"
 * describes other tenants' activity and answers nothing about their own.
 *
 * The DATA-SUBJECT EXPORT is the opposite: it is bounded by an organization, and is the half
 * of this page a tenant admin actually needs.
 */
final readonly class ExportsController extends ConsoleController
{
    /** Enough run history to see whether the schedule is healthy. */
    private const RUNS = 20;

    public function index(Request $request): Response
    {
        $this->scope->assertMayAdminister();

        $organizationId = $this->scope->organizationId();

        /*
         * A run row has no organization — one run walks every chain in the environment — so
         * there is nothing to scope it BY, and a page that cannot scope a list must decide
         * who may see the whole of it. That is the environment's administrator.
         */
        $showsRuns = $this->scope->plane() === ConsolePlane::Environment;

        /** @var Collection<int, AuditExportRun> $runs */
        $runs = $showsRuns
            ? AuditExportRun::query()->latest('id')->limit(self::RUNS)->get()
            : new Collection;

        $subjectId = trim($request->string('subject')->toString());

        /*
         * HOW MUCH THERE IS, not the bundle itself. The screen renders one number, and
         * building the bundle to get it meant two cursor sweeps of the subject's entire audit
         * history into memory — on a field that queries as somebody types, so a half-typed
         * subject id did it too. The export is still exact and still built by the run that
         * produces it.
         */
        $count = $subjectId === '' || $organizationId === null
            ? null
            : app(SubjectDataExport::class)->countFor($subjectId, $organizationId);

        return $this->page('compliance::exports', 'Exports & retention', [
            'showsRuns' => $showsRuns,
            'runs' => $runs->map(static fn (AuditExportRun $run): array => [
                'id' => $run->id,
                'when' => $run->finished_at?->diffForHumans() ?? $run->created_at?->diffForHumans(),
                'status' => $run->status,
                'entries' => $run->entries_exported,
                'scopes' => $run->scopes_scanned,
                'sink' => $run->sink === null ? '—' : class_basename($run->sink),
            ])->all(),
            /*
             * The view half of the scoping decision. Without it the data-subject field renders
             * on the environment plane with no organization chosen, accepts a subject id and
             * silently answers nothing — a control that looks broken rather than one that says
             * what it needs.
             */
            'needsOrganization' => $organizationId === null,
            'subjectId' => $subjectId,
            'subjectEntryCount' => $count,
            'downloadHref' => $this->url('compliance.data-exports.download'),
        ]);
    }

    /**
     * Produce the bundle and hand it over as a file.
     *
     * THE PAGE PROMISED THIS AND DID NOT DO IT. The section is titled "Data-subject export
     * (GDPR access)", the empty state says "to run a data-subject export", and there was no
     * button, no route and no command anywhere in the product — the export was reachable from
     * tests alone. A compliance officer answering a subject access request could learn how
     * many entries there were and then had nowhere to go.
     *
     * Streamed rather than stored: the bundle is one person's audit history, and writing it
     * to disk to serve it would create a second copy of exactly the data a subject access
     * request exists to hand over once.
     */
    public function download(Request $request, OrganizationActivity $activity): StreamedResponse|RedirectResponse
    {
        $this->scope->assertMayAdminister();

        $subjectId = trim($request->string('subject')->toString());
        $organizationId = $this->scope->organizationId();

        if ($subjectId === '' || $organizationId === null) {
            return back()->withErrors([
                'subject' => 'Choose an organization and name a subject before exporting.',
            ]);
        }

        $bundle = app(SubjectDataExport::class)->forSubject($subjectId, $organizationId);

        // RECORDED, because handing one person's audit trail to somebody is itself an event
        // that belongs in the trail. Who asked, for whom, and how much.
        $activity->record(
            $organizationId,
            'compliance.subject_export',
            $this->scope->actorId(),
            targetType: 'user',
            targetId: $subjectId,
            context: ['entries' => $bundle->auditEntryCount()],
            request: $request,
        );

        return response()->streamDownload(
            fn () => print json_encode($bundle->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            'subject-'.preg_replace('/[^A-Za-z0-9_-]/', '', $subjectId).'.json',
            ['Content-Type' => 'application/json'],
        );
    }
}
