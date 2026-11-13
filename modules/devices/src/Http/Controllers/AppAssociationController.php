<?php

declare(strict_types=1);

namespace Cbox\Id\Devices\Http\Controllers;

use Cbox\Id\Devices\Support\AuthenticatorLinks;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The documents iOS and Android fetch to let the Authenticator claim
 * `/authenticator/approve/*`, and the page that same URL shows when it was not claimed.
 *
 * Apple's CDN and Android's verifier follow no redirect and want `application/json`
 * with a 200, so these answer on every host straight from config, without a session.
 */
final class AppAssociationController
{
    public function apple(AuthenticatorLinks $links): JsonResponse
    {
        return $this->document($links->appleAssociation());
    }

    public function android(AuthenticatorLinks $links): JsonResponse
    {
        return $this->document($links->androidAssociation());
    }

    /**
     * The app did not open: it is not installed here, or the system kept the link in the
     * browser. The page names no account and looks nothing up, so an id typed into the
     * address bar teaches nobody whether it exists.
     */
    public function fallback(Request $request, string $id): View
    {
        abort_unless(preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/i', $id) === 1, 404);

        $store = config('id-devices.app_store_url');

        return view('authenticator-approve', [
            // The explicit tap is the one place a custom scheme is fair: the person asked
            // for the app, so the system's prompt (or its "cannot open") is an answer.
            'appUrl' => 'com.cboxid.authenticator://authenticator/approve/'.strtoupper($id),
            'storeUrl' => is_string($store) && str_starts_with($store, 'https://') ? $store : null,
            'phone' => AuthenticatorLinks::isPhone($request),
        ]);
    }

    /** @param array<array-key, mixed>|null $document */
    private function document(?array $document): JsonResponse
    {
        abort_if($document === null, 404);

        return new JsonResponse($document, 200, [
            'Content-Type' => 'application/json',
            'Cache-Control' => 'public, max-age=3600',
        ], JSON_UNESCAPED_SLASHES);
    }
}
