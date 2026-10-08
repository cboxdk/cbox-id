<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Http\Props\Shared\HelpProps;
use App\Platform\EnvironmentAdminAuth;
use App\Platform\Help\HelpTopic;
use App\Platform\Onboarding\EnvironmentChecklist;
use Cbox\Id\Directory\Models\Directory;
use Cbox\Id\Federation\Models\Connection;
use Cbox\Id\Identity\Enums\UserStatus;
use Cbox\Id\Identity\Models\User;
use Cbox\Id\OAuthServer\Models\Client;
use Cbox\Id\Organization\Enums\OrganizationStatus;
use Cbox\Id\Organization\Models\Organization;
use Inertia\Response;

/**
 * ENVIRONMENT PLANE › OVERVIEW — what is in this environment, in five numbers.
 *
 * Every count here is confined to the host-resolved environment by the framework's hard
 * scope, so it can only ever count THIS environment's resources.
 *
 * EACH TILE IS NAMED FOR THE PAGE IT LANDS ON, not for the thing it counts. "Applications"
 * over a tile that opens a page titled "Apps & API keys" is the wayfinding bug this console
 * keeps having, and the labels here are deliberately the navigation's words rather than the
 * model's.
 */
final readonly class EnvironmentHomeController extends ConsoleController
{
    public function index(EnvironmentChecklist $checklist): Response
    {
        $membership = app(EnvironmentAdminAuth::class)->membership();
        abort_if($membership === null, 403);

        $subject = app(EnvironmentAdminAuth::class)->subjectId();
        $progress = $checklist->toProps($membership->organization_id);

        return $this->page('environment/home', 'Overview', [
            /*
             * "Get started" on top until it is done or put away — the next step named, the
             * rest a click away. Null once neither applies, so the home page goes back to
             * being the numbers.
             */
            'checklist' => $progress['isComplete'] || ($subject !== null && $checklist->isDismissed($subject)) ? null : [
                ...$progress,
                'href' => route('environment.get-started'),
                'dismissHref' => route('environment.get-started.dismiss'),
            ],
            'help' => HelpProps::for(HelpTopic::EnvironmentOverview),
            'stats' => [
                [
                    'label' => 'Organizations',
                    'icon' => 'building',
                    // Soft-deleted tenants are gone from every list, so counting them here
                    // would make this number disagree with the page it opens.
                    'count' => Organization::query()
                        ->where('status', '!=', OrganizationStatus::Deleted->value)
                        ->count(),
                    'href' => route('environment.organizations'),
                ],
                [
                    'label' => 'Users',
                    'icon' => 'members',
                    'count' => User::query()->where('status', UserStatus::Active->value)->count(),
                    'href' => route('environment.users'),
                ],
                [
                    'label' => 'Enterprise SSO',
                    'icon' => 'fingerprint',
                    'count' => Connection::query()->count(),
                    'href' => route('environment.connections'),
                ],
                [
                    'label' => 'Applications',
                    'icon' => 'code',
                    'count' => Client::query()->count(),
                    'href' => route('environment.clients'),
                ],
                [
                    'label' => 'Directory Sync',
                    'icon' => 'directory',
                    'count' => Directory::query()->count(),
                    'href' => route('environment.directories'),
                ],
            ],
            'quickActions' => [
                ['label' => 'New organization', 'href' => route('environment.organizations.create')],
                ['label' => 'New user', 'href' => route('environment.users.create')],
                ['label' => 'New SSO connection', 'href' => route('environment.connections.create')],
                ['label' => 'New app', 'href' => route('environment.clients.create')],
            ],
        ]);
    }
}
