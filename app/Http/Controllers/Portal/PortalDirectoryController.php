<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Actions\Directories\RegisterScimDirectory;
use App\Actions\Directories\RotateDirectoryToken;
use App\Platform\Enums\PortalIntent;
use App\Platform\Portal\DirectoryUpdates;
use App\Platform\Portal\PortalGuides;
use Cbox\Id\Directory\Enums\DirectoryProvider;
use Cbox\Id\Directory\Enums\DirectoryStatus;
use Cbox\Id\Directory\Models\Directory;
use Cbox\Id\Federation\ValueObjects\ServiceProviderValues;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;

/**
 * DIRECTORY SYNC IN THE ADMIN PORTAL — register a SCIM directory, and get the two values
 * the customer's directory needs: the SCIM base URL, and a bearer token shown once.
 *
 * With a guide per directory ({@see PortalGuides::directories()}) saying where in Okta,
 * Entra ID, OneLogin or JumpCloud each value goes. A lost token is not a dead end: rotating
 * mints a new one and the old one stops working.
 *
 * THE TOKEN TRAVELS THE FLASH CHANNEL. It authenticates every provisioning call for this
 * organization, and this page is handed to a third party — so it is shown on the render
 * that answered and never written into their browser's history as a prop.
 */
final readonly class PortalDirectoryController extends PortalController
{
    public function show(Request $request, DirectoryUpdates $updates): Response
    {
        $this->requireIntent(PortalIntent::Dsync);

        $directories = Directory::query()
            ->where('organization_id', $this->organizationId())
            ->where('provider', DirectoryProvider::Scim)
            ->orderByDesc('created_at')
            ->get();

        $received = $updates->lastReceived($directories);
        $chosen = $request->string('provider')->toString();

        return $this->portalPage('portal/directory-sync', __('portal.directory.title'), [
            'guides' => PortalGuides::directories(),
            'provider' => in_array($chosen, PortalGuides::directoryKeys(), true) ? $chosen : null,
            'scimBaseUrl' => url('/scim/v2'),
            // Every form of it a guide may ask for — Oracle wants the host and the path as
            // two fields. The token is the page's own, from the flash that revealed it.
            'scimValues' => PortalGuides::values(new ServiceProviderValues(scimBaseUrl: url('/scim/v2'))),
            'directories' => $directories->map(static fn (Directory $directory): array => [
                'id' => $directory->id,
                'name' => $directory->name,
                'active' => $directory->status === DirectoryStatus::Active,
                // The last write the identity provider made, for a SCIM directory as well as
                // a pulled one — `last_synced_at` is only ever stamped by a pull.
                'lastSyncedAt' => ($received[$directory->id] ?? null)?->toIso8601String(),
                'rotateHref' => route('portal.directories.rotate', $directory->id),
            ])->values()->all(),
            'urls' => [
                'self' => route('portal.directories'),
                'create' => route('portal.directories.store'),
                // The same intent's other way in: an HR system the platform pulls from.
                'hris' => route('portal.hris'),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->requireIntent(PortalIntent::Dsync);

        $result = $this->act(RegisterScimDirectory::class, [
            'organization_id' => $this->organizationId(),
            'name' => trim($request->string('name')->toString()),
        ], ['name' => 'name'], 'name');

        if ($result instanceof RedirectResponse) {
            return $result;
        }

        $this->flashToken($result->payload ?? []);

        return back();
    }

    public function rotate(string $directory): RedirectResponse
    {
        $this->requireIntent(PortalIntent::Dsync);

        $result = $this->act(RotateDirectoryToken::class, [
            'id' => $directory,
            'organization_id' => $this->organizationId(),
        ], [], 'directory');

        if ($result instanceof RedirectResponse) {
            return $result;
        }

        $this->flashToken($result->payload ?? []);

        return back();
    }

    /**
     * @param  array<mixed>  $payload
     */
    private function flashToken(array $payload): void
    {
        $this->inertia->flash([
            'newToken' => is_string($payload['bearer_token'] ?? null) ? $payload['bearer_token'] : null,
            'newTokenName' => is_string($payload['name'] ?? null) ? $payload['name'] : null,
        ]);
    }
}
