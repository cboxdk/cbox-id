<?php

declare(strict_types=1);

namespace App\Platform\Actions\Approvals;

use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\Enums\ClientType;
use Cbox\Id\OAuthServer\Models\Client;
use Cbox\Id\OAuthServer\ValueObjects\NewClient;
use Cbox\Id\Platform\PlatformRoot;
use Illuminate\Support\Facades\Cache;

/**
 * The platform's own first-party client that action approvals are filed under — the name
 * the approving person sees on their device ("Cbox ID step-up").
 *
 * It lives in the platform ROOT, where the people who own management keys are subjects and
 * where their Authenticator is enrolled. It holds no usable secret and is never redeemable
 * for tokens: an action approval is spent by the server, not exchanged at the token endpoint.
 */
final readonly class StepUpClient
{
    public const string NAME = 'Cbox ID step-up';

    public function __construct(
        private PlatformRoot $platformRoot,
        private ClientRegistry $clients,
    ) {}

    /** The client, registered on first use. Call inside the platform root. */
    public function ensure(): Client
    {
        $existing = Client::query()->where('name', self::NAME)->whereNull('organization_id')->first();

        if ($existing !== null) {
            return $existing;
        }

        $client = Cache::lock('step-up-client:register', 10)->block(5, function (): Client {
            return Client::query()->where('name', self::NAME)->whereNull('organization_id')->first()
                ?? $this->clients->register(new NewClient(
                    self::NAME,
                    ClientType::Confidential,
                    grantTypes: ['urn:openid:params:grant-type:ciba'],
                    firstParty: true,
                ))->client;
        });

        return $client instanceof Client ? $client : throw new \RuntimeException('Could not register the step-up client.');
    }

    public function platformRoot(): PlatformRoot
    {
        return $this->platformRoot;
    }
}
