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
        if ($request->isFromTrustedProxy() && filled($session->ip) && $session->ip === $request->ip()) {
            $country = strtoupper(trim((string) $request->header('CF-IPCountry')));

            // Cloudflare uses XX for an unknown country and T1 for Tor.
            if (preg_match('/^[A-Z]{2}$/', $country) === 1 && $country !== 'XX') {
                if (blank($session->getAttribute('country_code'))) {
                    $session->setAttribute('country_code', $country);
                }

                $city = trim(rawurldecode((string) $request->header('CF-IPCity')));

                if ($session->getAttribute('country_code') === $country
                    && blank($session->getAttribute('city'))
                    && $city !== '' && mb_check_encoding($city, 'UTF-8')
                    && preg_match('/[\x00-\x1F\x7F]/', $city) !== 1) {
                    $session->setAttribute('city', mb_substr($city, 0, 160));
                }
            }
        }

        if ($session->isDirty(['ip', 'user_agent', 'country_code', 'city'])) {
            $session->save();
        }
    }
}
