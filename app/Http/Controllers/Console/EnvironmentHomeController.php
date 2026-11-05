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
        $admin = app(EnvironmentAdminAuth::class);
        abort_unless($admin->check(), 403);

        $subject = $admin->subjectId();
        // The workspace whose membership opened this console; none on the platform root's,
        // which its operators administer and no workspace owns.
        $progress = $checklist->toProps($admin->workspaceId());

        return $this->page('environment/home', __('console.overview_d4b1ea57'), [
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
                    'label' => __('console.organizations_2730183d'),
                    'icon' => 'building',
                    // Soft-deleted tenants are gone from every list, so counting them here
                    // would make this number disagree with the page it opens.
                    'count' => Organization::query()
                        ->where('status', '!=', OrganizationStatus::Deleted->value)
                        ->count(),
                    'href' => route('environment.organizations'),
                ],
                [
                    'label' => __('console.ux_active_users'),
                    'icon' => 'members',
                    'count' => User::query()->where('status', UserStatus::Active->value)->count(),
                    'href' => route('environment.users'),
                ],
                [
                    'label' => __('console.enterprise_sso_0f8d7e8e'),
                    'icon' => 'fingerprint',
                    // Enterprise connections — not the social providers stored beside them.
                    'count' => Connection::query()->whereNull('provider')->count(),
                    'href' => route('environment.connections'),
                ],
                [
                    'label' => __('console.applications_98e33b0f'),
                    'icon' => 'code',
                    'count' => Client::query()->count(),
                    'href' => route('environment.clients'),
                ],
                [
                    'label' => __('console.directory_sync_ea18bba8'),
                    'icon' => 'directory',
                    'count' => Directory::query()->count(),
                    'href' => route('environment.directories'),
                ],
            ],
            'quickActions' => [
                ['label' => __('console.new_organization_62e34b70'), 'href' => route('environment.organizations.create')],
                ['label' => __('console.new_user_6384ffa7'), 'href' => route('environment.users.create')],
                ['label' => __('console.new_sso_connection_49ecf1f1'), 'href' => route('environment.connections.create')],
                ['label' => __('console.new_app_747f1473'), 'href' => route('environment.clients.create')],
                ['label' => __('console.connectors_c3d2e79e'), 'href' => route('environment.connectors.catalog'), 'icon' => 'layers'],
            ],
        ]);
    }
}
