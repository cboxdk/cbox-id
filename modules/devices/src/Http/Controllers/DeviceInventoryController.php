<?php

declare(strict_types=1);

namespace Cbox\Id\Devices\Http\Controllers;

use App\Http\Controllers\Console\ConsoleController;
use App\Http\Props\Shared\HelpProps;
use App\Http\Props\Shared\PaginationProps;
use App\Platform\Help\HelpTopic;
use Cbox\Id\Devices\Enums\DeviceStatus;
use Cbox\Id\Devices\Enums\NotificationStatus;
use Cbox\Id\Devices\Models\Device;
use Cbox\Id\Devices\Models\PushNotification;
use Cbox\Id\Organization\Contracts\Memberships;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Route;
use Inertia\Response;

/**
 * CONSOLE › TRUSTED DEVICES — the handsets enrolled in the authenticator app.
 *
 * THE READ GATE IS HERE, not on the route. This page shows every user's handsets and the
 * errors their pushes produced, which is precisely the reconnaissance an attacker wants:
 * who is enrolled, on what, and which devices are currently failing. The route's middleware
 * proves a session exists and checks a feature flag; neither is a role check, so without
 * this any ordinary member could read the lot by typing the URL.
 */
final readonly class DeviceInventoryController extends ConsoleController
{
    private const PER_PAGE = 25;

    /** Enough recent pushes to see whether delivery is healthy. */
    private const RECENT = 25;

    public function __invoke(Memberships $memberships): Response
    {
        $this->scope->assertMayAdminister();

        $organizationId = $this->scope->organizationId();

        /*
         * IDS ONLY, and resolved ONCE. The page reads devices and then the pushes sent to
         * them, and the narrowing this list performs is needed by both — hydrating a model
         * per member to reduce it to ids that are then thrown away, twice per render, is what
         * this replaces.
         *
         * Fetched through the contract with an explicit organization id rather than as a
         * subquery on the model: `Membership` carries a tenant scope that denies by default
         * with no tenant context set, so a subquery silently returns nothing and the page
         * shows an empty inventory — a filter that fails to a blank screen rather than to a
         * refusal, and one that rests on ambient state a security decision should not.
         */
        $memberIds = $organizationId === null ? null : $memberships->userIdsForOrganization($organizationId);

        /*
         * PAGINATED, NOT TRUNCATED. This took the 100 most recently seen devices and called
         * itself the device inventory. An admin looking for the handset somebody enrolled
         * last spring — which is the reason to open this page during an incident — scrolled
         * to the bottom, found it absent, and had nothing telling them the list was cut. A
         * silent truncation on a security page answers "no such device" to a question nobody
         * asked.
         */
        $page = $this->scoped(Device::query(), $memberIds)
            ->orderByDesc('last_seen_at')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        // Same confinement: a push record carries the device it went to and the error text
        // if it failed.
        $recent = PushNotification::query()
            ->whereIn('device_id', $this->scoped(Device::query(), $memberIds)->select('id'))
            ->latest('created_at')
            ->limit(self::RECENT)
            ->get();

        /*
         * `devices.mine` is the ORGANIZATION plane's page — a subject's own handsets — and
         * does not exist on the other one, so linking to it unconditionally would throw a
         * RouteNotFoundException and 500 the page for the very administrator this merge is
         * for. An environment administrator is not a subject of this environment and has no
         * page here to be sent to, so the link is absent rather than broken.
         */
        $personal = $this->scope->routeName('devices.mine');

        return $this->page('devices::index', 'Trusted devices', [
            'devices' => array_map(static fn (Device $device): array => [
                'id' => $device->id,
                'name' => $device->name,
                'platform' => $device->platform->label(),
                'status' => $device->status->label(),
                'active' => $device->status === DeviceStatus::Active,
                'lastSeen' => $device->last_seen_at?->diffForHumans(),
                'health' => $device->circuit_opened_at !== null
                    ? 'Paused after '.$device->consecutive_failures.' failures'
                    : ($device->consecutive_failures > 0
                        ? $device->consecutive_failures.' recent failures'
                        : 'Healthy'),
                'healthy' => $device->circuit_opened_at === null && $device->consecutive_failures === 0,
            ], $page->items()),
            'pagination' => PaginationProps::from($page),
            'recent' => $recent->map(static fn (PushNotification $push): array => [
                'id' => $push->id,
                'when' => $push->created_at?->diffForHumans(),
                'kind' => $push->kind->label(),
                'status' => $push->status->label(),
                'delivered' => $push->status === NotificationStatus::Delivered,
                'terminal' => $push->status->isTerminal(),
                'attempts' => $push->attempt,
                // The provider's own error, verbatim: a delivery that failed is only
                // actionable if the reason survives to the screen.
                'detail' => $push->last_error,
            ])->all(),
            'personalPage' => Route::has($personal) ? route($personal) : null,
            'wholeEnvironment' => $organizationId === null,
            'help' => HelpProps::for(HelpTopic::TrustedDevices),
        ]);
    }

    /**
     * Narrow a device query to the acting organization's members.
     *
     * `Device` is keyed by SUBJECT, so an unqualified read on an organization-gated page
     * handed an admin of one tenant every other tenant's handset names, models, OS versions
     * and health.
     *
     * With no organization resolved — reachable only by an administrator who holds the
     * environment — the estate is the environment's own, which `Device`'s environment scope
     * still bounds. That is not reachable from the organization plane: the scope refuses
     * rather than answering null there.
     *
     * @param  Builder<Device>  $query
     * @param  list<string>|null  $memberIds  null on the environment plane with no
     *                                        organization chosen — see above
     * @return Builder<Device>
     */
    private function scoped(Builder $query, ?array $memberIds): Builder
    {
        return $memberIds === null ? $query : $query->whereIn('subject_id', $memberIds);
    }
}
