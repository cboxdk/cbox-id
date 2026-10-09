<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Actions\Directories\ConnectHrisDirectory;
use App\Actions\Directories\SyncDirectoryNow;
use App\Http\Resources\Environment\Timestamp;
use App\Platform\Enums\PortalIntent;
use App\Platform\Portal\PortalGuides;
use Cbox\Id\Directory\Enums\DirectoryProvider;
use Cbox\Id\Directory\Enums\DirectoryStatus;
use Cbox\Id\Directory\Models\Directory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;

/**
 * DIRECTORY SYNC FROM AN HR SYSTEM, IN THE ADMIN PORTAL — the customer's IT or HR
 * administrator connects Workday, BambooHR, Rippling, HiBob or Personio themselves: pick
 * the system, follow its guide ({@see PortalGuides::hris()}), paste what it produced.
 *
 * Under the Directory Sync intent: a link that may set up SCIM may set up this, because it
 * is the same promise — this organization's people provisioned and deprovisioned from a
 * system the customer runs — reached the other way round.
 *
 * NOTHING IS EVER READ BACK. The credentials go to the action, which shapes them, checks
 * them with the HR system, seals them, and queues the first sync; this page shows only how
 * the sync is going. A failed check returns the visitor to the form with the reason in
 * their language and every secret field empty.
 */
final readonly class PortalHrisController extends PortalController
{
    public function show(Request $request): Response
    {
        $this->requireIntent(PortalIntent::Dsync);

        $directories = Directory::query()
            ->where('organization_id', $this->organizationId())
            ->whereIn('provider', array_map(static fn (DirectoryProvider $p): string => $p->value, DirectoryProvider::hris()))
            ->orderByDesc('created_at')
            ->get();

        $chosen = $request->string('provider')->toString();

        return $this->portalPage('portal/hris-sync', __('portal.hris.title'), [
            'guides' => PortalGuides::hris(),
            'provider' => in_array($chosen, PortalGuides::hrisKeys(), true) ? $chosen : null,
            'directories' => $directories->map(static fn (Directory $directory): array => [
                'id' => $directory->id,
                'name' => $directory->name,
                'provider' => $directory->provider->value,
                'active' => $directory->status === DirectoryStatus::Active,
                'status' => $directory->last_sync_status?->value,
                'lastSyncedAt' => Timestamp::of($directory->last_synced_at),
                'failed' => is_int($directory->last_sync_stats['failed'] ?? null) ? $directory->last_sync_stats['failed'] : 0,
                'error' => $directory->last_sync_error,
                'syncHref' => route('portal.hris.sync', $directory->id),
            ])->values()->all(),
            'urls' => [
                'self' => route('portal.hris'),
                'create' => route('portal.hris.store'),
                'scim' => route('portal.directories'),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->requireIntent(PortalIntent::Dsync);

        $credentials = $request->input('credentials');
        $custom = preg_split('/[\r\n,]+/', $request->string('customAttributes')->toString());

        $result = $this->act(ConnectHrisDirectory::class, [
            'organization_id' => $this->organizationId(),
            'provider' => $request->string('provider')->toString(),
            'credentials' => is_array($credentials) ? $credentials : [],
            'custom_attributes' => array_values(array_filter(array_map('trim', is_array($custom) ? $custom : []), static fn (string $name): bool => $name !== '')),
        ], ['credentials' => 'credentials', 'provider' => 'provider', 'custom_attributes' => 'customAttributes'], 'credentials');

        if ($result instanceof RedirectResponse) {
            // Back to the form without the secrets: `withInput()` would flash them into the
            // session for the next render.
            return $result->withInput($request->except('credentials'));
        }

        return to_route('portal.hris');
    }

    public function sync(string $directory): RedirectResponse
    {
        $this->requireIntent(PortalIntent::Dsync);

        $result = $this->act(SyncDirectoryNow::class, [
            'id' => $directory,
            'organization_id' => $this->organizationId(),
        ], [], 'directory');

        return $result instanceof RedirectResponse ? $result : back();
    }
}
