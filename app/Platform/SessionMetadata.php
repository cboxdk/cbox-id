<?php

declare(strict_types=1);

namespace App\Platform;

use Cbox\Id\Identity\Models\Session;
use Illuminate\Http\Request;

final class SessionMetadata
{
    /**
     * Magic-link and federated sessions arrive without request metadata. Only call
     * this for a session this browser has authenticated; never for the other rows
     * in its session list. Preserve details already recorded at sign-in.
     */
    public static function captureMissing(Session $session, Request $request): void
    {
        if (blank($session->ip) && filled($request->ip())) {
            $session->ip = $request->ip();
        }

        if (blank($session->user_agent) && filled($request->userAgent())) {
            $session->user_agent = $request->userAgent();
        }

        // These headers belong to the visitor only on the configured ingress path.
        // Never attach today's country to an older session's different IP address.
        $geo = filled($session->ip) && $session->ip === $request->ip() ? CloudflareGeo::fromRequest($request) : null;

        if ($geo !== null) {
            if (blank($session->getAttribute('country_code'))) {
                $session->setAttribute('country_code', $geo->countryCode);
            }

            if ($session->getAttribute('country_code') === $geo->countryCode
                && blank($session->getAttribute('city'))
                && $geo->city !== null) {
                $session->setAttribute('city', $geo->city);
            }
        }

        if ($session->isDirty(['ip', 'user_agent', 'country_code', 'city'])) {
            $session->save();
        }
    }
}
