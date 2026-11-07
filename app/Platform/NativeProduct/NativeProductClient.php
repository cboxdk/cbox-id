<?php

declare(strict_types=1);

namespace App\Platform\NativeProduct;

use App\Platform\Actions\ActionRefused;
use Cbox\Id\AccessControl\Contracts\AppManifests;
use Cbox\Id\AccessControl\Manifest\ManifestSyncResult;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\Enums\ClientType;
use Cbox\Id\OAuthServer\Models\Client;
use Cbox\Id\OAuthServer\ValueObjects\NewClient;
use Cbox\Id\Organization\Enums\EnvironmentStatus;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Platform\PlatformRoot;
use Illuminate\Support\Facades\DB;

final readonly class NativeProductClient
{
    public function __construct(private PlatformRoot $root, private ClientRegistry $clients) {}

    /** Read only. Missing installation never creates an app or assigns a role during a request. */
    public function clientId(): string
    {
        $root = $this->root->model() ?? throw new ActionRefused('native_product_unavailable', 'The ID product authorization catalog is not installed.', 503);
        if ($root->status !== EnvironmentStatus::Active) {
            throw new ActionRefused('native_product_unavailable', 'The ID product authorization catalog is not available.', 503);
        }

        return $this->root->run(function () use ($root): string {
            $client = Client::query()->whereIn('client_id', NativeProductRegistration::query()
                ->whereKey('id')->where('environment_id', $root->id)->select('client_id'))->first();
            if ($client === null
                || $client->environment_id !== $root->id || $client->organization_id !== null || ! $client->first_party
                || $client->isDynamicallyRegistered()) {
                throw new ActionRefused('native_product_unavailable', 'The ID product authorization catalog is not installed.', 503);
            }

            return $client->client_id;
        }) ?? throw new ActionRefused('native_product_unavailable', 'The ID product authorization catalog is not installed.', 503);
    }

    /** Only the release command calls this. A root row lock serializes first installation. */
    public function synchronize(AppManifests $manifests, NativeProductManifest $definition): ManifestSyncResult
    {
        $root = $this->root->model() ?? throw new ActionRefused('native_product_unavailable', 'Install the platform root before publishing its product catalog.', 503);

        return $this->root->run(fn (): ManifestSyncResult => DB::transaction(function () use ($root, $manifests, $definition): ManifestSyncResult {
            Environment::query()->whereKey($root->id)->lockForUpdate()->firstOrFail();
            if (! NativeProductRegistration::query()->whereKey('id')->exists()) {
                // No browser redirect or OAuth privileges: this real client identifies the
                // native app's catalog. Its generated secret is never used or exposed.
                $registered = $this->clients->register(new NewClient(name: 'Cbox ID', type: ClientType::Confidential, redirectUris: [], grantTypes: ['client_credentials'], scopes: [], firstParty: true));
                NativeProductRegistration::query()->create(['product' => 'id', 'environment_id' => $root->id, 'client_id' => $registered->client->client_id]);
            }

            return $manifests->sync($this->clientId(), $definition->manifest());
        })) ?? throw new ActionRefused('native_product_unavailable', 'The ID product authorization catalog is not installed.', 503);
    }
}
