<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Environment;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Environment\StartSupportSessionRequest;
use App\Http\Resources\Environment\SupportSessionResource;
use Cbox\Id\OAuthServer\Contracts\SupportSessions;
use Cbox\Id\OAuthServer\Enums\SupportSessionRefusal;
use Cbox\Id\OAuthServer\Exceptions\InvalidAudience;
use Cbox\Id\OAuthServer\Exceptions\SupportSessionRefused;
use Illuminate\Http\JsonResponse;

/**
 * Environment plane › support sessions — {@see SupportSessions::begin()}, with the session's
 * first authorization code when the request asks for one.
 *
 * ALWAYS AS STAFF. The actor is the person named in `actor_user_id`, and the framework
 * checks them the way it checks every staff actor: they must hold THIS app's own
 * `support:impersonate` through an environment-wide grant. The key asserts nothing on their
 * behalf — `SupportActorKind::EnvironmentAdmin`, which the framework takes on the caller's
 * word, is the console's to use, never an API key's.
 *
 * The framework audits the session on both trails (the customer's and the environment's)
 * and announces `support_session.started` to the organization's webhooks.
 */
final class SupportSessionController extends Controller
{
    use PaginatesEnvironmentResources;

    public function store(StartSupportSessionRequest $request, SupportSessions $sessions): JsonResponse
    {
        $session = $request->toSession();

        if ($this->organization($session->organizationId) === null) {
            return $this->refuse('organization_not_found', 'No organization with that organization_id exists in this environment.', 422);
        }

        $code = $request->codeRequest();

        try {
            $started = $sessions->begin($session, $code);
        } catch (InvalidAudience $audience) {
            // Scopes of two registered APIs cannot be audienced to one token, so no code
            // this session minted could be redeemed. The framework settles the session's
            // scopes to what its tokens carry and refuses this before anything is started
            // or announced — after the checks that say whether the caller may ask at all.
            return $this->refuse($audience->error, $audience->getMessage(), 422);
        } catch (SupportSessionRefused $refused) {
            return $this->refuse($refused->refusal->value, $refused->getMessage(), match ($refused->refusal) {
                SupportSessionRefusal::NotPermitted => 403,
                SupportSessionRefusal::OrganizationInactive,
                SupportSessionRefusal::SessionNotActive => 409,
                default => 422,
            });
        }

        return $this->item(SupportSessionResource::from($started->session, $started->code, $code?->redirectUri), 201);
    }
}
