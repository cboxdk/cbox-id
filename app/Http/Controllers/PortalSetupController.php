<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Portal\PortalController;
use App\Platform\Enums\PortalIntent;
use App\Platform\Portal\PortalProgress;
use Illuminate\Http\RedirectResponse;
use Inertia\Response;

/**
 * THE ADMIN PORTAL'S HOME — the checklist an external IT administrator lands on after
 * opening their link: one card per thing the link lets them set up, each saying how far
 * along it is, read from the system rather than from a box anybody ticked
 * ({@see PortalProgress}).
 *
 * The work itself is on each intent's own page (the controllers under Portal\); this
 * is where they start, where they come back to, and where they finish — finishing ENDS the
 * session, so it hands over to a page outside it.
 */
final readonly class PortalSetupController extends PortalController
{
    /** Each intent's own page. */
    private const array PAGES = [
        'sso' => 'portal.sso',
        'dsync' => 'portal.directories',
        'domain_verification' => 'portal.domains',
        'log_streams' => 'portal.log-streams',
        'certificate_renewal' => 'portal.certificates',
    ];

    public function show(): Response
    {
        $organizationId = $this->organizationId();

        $tasks = array_map(function (PortalIntent $intent) use ($organizationId): array {
            $progress = $this->progress->for($intent, $organizationId);

            return [
                'intent' => $intent->value,
                'href' => route(self::PAGES[$intent->value]),
                ...$progress,
            ];
        }, $this->portal->usableIntents());

        return $this->portalPage('portal/setup', __('portal.setup.title'), [
            'tasks' => $tasks,
            'finishHref' => route('portal.finish'),
        ]);
    }

    /**
     * Close the link.
     *
     * The session is cleared by `complete()`, so this cannot re-render the setup screen —
     * the next request would bounce to the expired page, which is the wrong sentence for
     * somebody who just finished. It hands over to a page of its own instead, carrying the
     * organization's name because the session that knew it is gone.
     */
    public function finish(): RedirectResponse
    {
        // Belt-and-braces with the middleware: only a live session may finish.
        abort_unless($this->portal->sessionValid(), 403);

        $name = $this->organizationName();

        $this->portal->complete();

        $this->inertia->flash('portalOrganization', $name);

        return redirect()->route('portal.done');
    }

    /** "All set" — outside the portal session, because finishing ends it. */
    public function done(): Response
    {
        return $this->page('portal/done', __('portal.done.title'));
    }

    /** The friendly refusal for a link that has expired or was already used. */
    public function expired(): Response
    {
        return $this->page('portal/expired', __('portal.expired.title'));
    }
}
