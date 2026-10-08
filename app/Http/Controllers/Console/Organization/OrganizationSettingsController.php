<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console\Organization;

use App\Actions\Organizations\OrganizationFields;
use Inertia\Response;

/**
 * AN ORGANIZATION › SETTINGS — its name, URL handle and the metadata an integrator keeps on
 * it; suspending and reactivating it; deleting it.
 *
 * Every write is an action (`organizations.update`, `.suspend`, `.reactivate`, `.delete`) on
 * the organization's own URL. The environment console's general Settings page is about the
 * ENVIRONMENT, and no longer renames whichever organization the header had been pointed at.
 */
final readonly class OrganizationSettingsController extends OrganizationTabController
{
    public function show(): Response
    {
        $organization = $this->organization();
        $ids = ['organization' => $organization->id];

        $metadata = [];

        foreach (OrganizationFields::metadataOf($organization) as $key => $value) {
            $metadata[] = ['key' => $key, 'value' => $value];
        }

        return $this->page('environment/organizations/tabs/settings', $organization->name.' · Settings', [
            'organization' => [
                'id' => $organization->id,
                'name' => $organization->name,
                'slug' => (string) $organization->slug,
                'status' => $organization->status->value,
                'metadata' => $metadata,
            ],
            'urls' => [
                'update' => route('environment.organizations.update', $ids),
                'suspend' => route('environment.organizations.suspend', $ids),
                'reactivate' => route('environment.organizations.reactivate', $ids),
                'destroy' => route('environment.organizations.destroy', $ids),
            ],
        ]);
    }
}
