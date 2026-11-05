<?php

declare(strict_types=1);

namespace Cbox\Id\Devices\Http\Middleware;

use Cbox\Id\Devices\Enums\DeviceStatus;
use Cbox\Id\Devices\Models\Device;
use Cbox\Id\Devices\Support\AuthenticatorClient;
use Cbox\Id\Devices\Support\DeviceConfig;
use Cbox\Id\OAuthServer\ValueObjects\Introspection;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/** Runs after RequireScope has verified the token and its DPoP proof. */
final class RequireAuthenticator
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next, string $mode = 'enrolled'): Response
    {
        if (! DeviceConfig::bool('id-devices.enabled', false)) {
            return new JsonResponse(['error' => 'not_found', 'message' => 'Authenticator is unavailable.'], 404);
        }
        $token = $request->attributes->get('cbox_token');
        if (! $token instanceof Introspection || $token->subject === null || $token->clientId === null) {
            return new JsonResponse(['error' => 'invalid_token', 'message' => 'A user access token is required.'], 401);
        }
        $jkt = $token->confirmationThumbprint();
        if ($jkt === null || $jkt === '' || AuthenticatorClient::find()?->client_id !== $token->clientId) {
            return new JsonResponse(['error' => 'authenticator_required', 'message' => 'Use the registered authenticator with a device proof.'], 403);
        }

        // Keep the same lock until the decision commits. A concurrent removal either
        // precedes this action (and refuses it) or follows its completed transaction.
        return DB::transaction(function () use ($request, $next, $mode, $token, $jkt): Response {
            $devices = Device::query()->where('subject_id', $token->subject)
                ->where('dpop_jkt', $jkt)->lockForUpdate()->get();
            if ($devices->contains(fn (Device $device): bool => $device->status === DeviceStatus::Revoked)
                || ($mode !== 'enrolling' && $devices->isEmpty())) {
                return new JsonResponse(['error' => 'device_revoked', 'message' => 'This phone is no longer trusted. Connect it again from Trusted devices.'], 403);
            }

            return $next($request);
        }, 3);
    }
}
