<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console\Organization;

use App\Http\Props\Shared\SupportSessionProps;
use App\Platform\SupportAccess\Contracts\SupportAccess;
use Inertia\Response;

/**
 * AN ORGANIZATION › SUPPORT — somebody signed in to an app as one of its people, right now.
 *
 * The organization's own activity log records each one with its reason; this is where the
 * environment's administrators see them while they are open, and end them
 * (`support_sessions.end`). A session is started from a person's page under Users, which is
 * where the person it acts as is chosen.
 */
final readonly class OrganizationSupportController extends OrganizationTabController
{
    public function index(SupportAccess $support): Response
    {
        $organization = $this->organization();

        return $this->page('environment/organizations/tabs/support', $organization->name.' · Support', [
            'supportSessions' => SupportSessionProps::list($support->activeForOrganization($organization->id)),
            'usersHref' => route('environment.users'),
        ]);
    }
}
