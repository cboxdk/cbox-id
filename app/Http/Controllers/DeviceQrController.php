<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Platform\OAuth\DeviceUserCode;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Cbox\Id\Kernel\Tenancy\Contracts\IssuerResolver;
use Cbox\Id\OAuthServer\Contracts\DeviceAuthorization;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `GET /oauth/device/qr?user_code=BCDF-GHJK` — the device grant's `verification_uri_complete`
 * as an SVG QR code, for a TV or a kiosk to put on screen beside the code.
 *
 * A TV app should not need a QR library to offer the one sign-in a living room actually
 * wants: point a phone at the screen. It asks the authorization server for the picture of
 * the link the server already gave it, and draws it as an `<img>`.
 *
 * WHAT IT DISCLOSES: nothing that is not already on the TV's screen. The picture encodes
 * this environment's own `/device?user_code=…`, and only for a code that is PENDING — an
 * unknown, expired or decided code is a 404, so this is not a QR generator for arbitrary
 * text on our domain. No session, no cookie, no person: it is asked for by a device that
 * has not signed anybody in yet. Throttled per address on the route, like the endpoint that
 * issued the code, so it is no faster a way to test guesses than the approval page itself.
 *
 * CACHE-SAFE: the picture depends on the code alone, so it may be cached publicly — but no
 * longer than the code lives, after which the link it shows leads nowhere.
 */
final class DeviceQrController
{
    /** Pixels on a side. A TV scales it; a phone camera reads it from across a room. */
    private const SIZE = 320;

    public function __invoke(Request $request, DeviceAuthorization $devices, IssuerResolver $issuer): Response
    {
        $typed = $request->query('user_code');
        $code = is_string($typed) ? DeviceUserCode::normalize($typed) : null;
        $pending = $code !== null ? $devices->pending($code) : null;

        abort_if($code === null || $pending === null, 404);

        $link = $issuer->issuer().'/device?user_code='.rawurlencode($code);

        $svg = (new Writer(new ImageRenderer(new RendererStyle(self::SIZE, 1), new SvgImageBackEnd)))
            ->writeString($link);

        $ttl = max(0, $pending->expiresAt->getTimestamp() - time());

        return response($svg, 200, [
            'Content-Type' => 'image/svg+xml',
            'Content-Disposition' => 'inline; filename="device-sign-in.svg"',
            'Cache-Control' => 'public, max-age='.$ttl,
            // An SVG is a document: served on its own it runs nothing and loads nothing.
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; sandbox",
            'X-Content-Type-Options' => 'nosniff',
            // A TV app on any origin draws it.
            'Access-Control-Allow-Origin' => '*',
            'Cross-Origin-Resource-Policy' => 'cross-origin',
        ]);
    }
}
