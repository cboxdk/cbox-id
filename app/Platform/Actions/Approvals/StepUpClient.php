<?php

declare(strict_types=1);

namespace App\Platform\Actions\Approvals;

use App\Platform\Actions\ActionTrail;
use App\Platform\EnvironmentApiContext;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\Enums\ClientType;
use Cbox\Id\OAuthServer\Models\Client;
use Cbox\Id\OAuthServer\ValueObjects\NewClient;
use Cbox\Id\Platform\PlatformRoot;
use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * The platform's own first-party client that action approvals are filed under — the name
 * the approving person sees on their device ("Cbox ID step-up").
 *
 * It lives where the approving person is a subject and their Authenticator is enrolled: the
 * platform ROOT for the people who own management keys, and an environment of its own for a
 * person who signed an agent in there ({@see ActionApprovalGate}) — one per place, registered
 * on first use. It holds no usable secret and is never redeemable for tokens: an action
 * approval is spent by the server, not exchanged at the token endpoint.
 */
final readonly class StepUpClient
{
    public const string NAME = 'Cbox ID step-up';

    public function __construct(
        private PlatformRoot $platformRoot,
        private ClientRegistry $clients,
    ) {}

    /** The client, registered on first use. Call inside the environment the approver belongs to. */
    public function ensure(): Client
    {
        $existing = Client::query()->where('name', self::NAME)->whereNull('organization_id')->first();

        if ($existing !== null) {
            return $existing;
        }

        $client = Cache::lock('step-up-client:register', 10)->block(5, function (): Client {
            return Client::query()->where('name', self::NAME)->whereNull('organization_id')->first()
                ?? $this->asThePlatform(fn (): Client => $this->clients->register(new NewClient(
                    self::NAME,
                    ClientType::Confidential,
                    grantTypes: ['urn:openid:params:grant-type:ciba'],
                    firstParty: true,
                ))->client);
        });

        return $client instanceof Client ? $client : throw new \RuntimeException('Could not register the step-up client.');
    }

    /**
     * Register it as the platform, not as the caller whose action first needed an approval.
     *
     * It is registered on first use — which is in the middle of somebody's action, with their
     * key on the API context and their door on the trail — so the registry's `app.created`
     * was attributed to them: the audit log said an agent's key had created a first-party
     * confidential client in the platform root, over REST. Neither half is true.
     *
     * @template T
     *
     * @param  Closure(): T  $register
     * @return T
     */
    private function asThePlatform(Closure $register): mixed
    {
        $context = app(EnvironmentApiContext::class);
        $key = $context->key();
        $person = $context->delegated();
        $context->clear();

        try {
            return app(ActionTrail::class)->outside($register);
        } finally {
            if ($key !== null) {
                $context->set($key);
            } elseif ($person !== null) {
                $context->setDelegated($person);
            }
        }
    }

    public function platformRoot(): PlatformRoot
    {
        return $this->platformRoot;
    }
}
