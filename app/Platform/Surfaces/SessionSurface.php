<?php

declare(strict_types=1);

namespace App\Platform\Surfaces;

use App\Http\Middleware\Authenticate;
use App\Http\Middleware\BindSessionToSurface;
use App\Http\Middleware\UseSurfaceSessionCookie;
use Illuminate\Http\Request;

/**
 * ONE SESSION PER SURFACE, in login-host mode ({@see Surfaces}).
 *
 * The login host holds the SSO session and the app host holds the console's own; a tenant's
 * host holds that environment's. They share one session table and one encryption key, so
 * two things keep them apart:
 *
 *  - THE COOKIE NAME ({@see cookieName()}, set by {@see UseSurfaceSessionCookie}): each
 *    surface has its own, with the `__Host-` prefix, which a browser accepts only as a
 *    Secure, host-only cookie on path `/`. A sibling subdomain can neither read it nor set
 *    it, and Laravel binds an encrypted cookie's value to its name, so the value of one is
 *    not a valid value of another.
 *  - THE MARKER ({@see marker()}, kept by {@see BindSessionToSurface} and asked by
 *    {@see Authenticate} through {@see admits()}): the session itself says which surface
 *    minted it, and it is accepted on that surface only.
 *
 * In single-host mode there is one cookie and no marker, as before.
 */
final class SessionSurface
{
    /** Where a session keeps the surface that minted it. */
    public const string KEY = 'cbox.surface';

    public function __construct(private readonly Surfaces $surfaces) {}

    /**
     * The session cookie's name on this request's surface, or null to leave the configured
     * name alone (single-host mode, and the surfaces that hold no session).
     *
     * `__Host-` needs TLS. A plain-http request (a local install) gets the same name
     * without the prefix, which a browser on https never sends back as the prefixed one.
     */
    public function cookieName(Request $request): ?string
    {
        $name = match ($this->surfaces->current($request)) {
            Surface::Login => 'cbox-sso',
            Surface::Console => 'cbox-console',
            Surface::Tenant => 'cbox-session',
            default => null,
        };

        if ($name === null) {
            return null;
        }

        return $request->isSecure() ? '__Host-'.$name : $name;
    }

    /**
     * What a session minted on this request's surface is marked with, or null where
     * sessions are not marked. A tenant's marker names its host: two environments are two
     * surfaces.
     */
    public function marker(Request $request): ?string
    {
        $surface = $this->surfaces->current($request);

        return match ($surface) {
            Surface::Login, Surface::Console => $surface->value,
            Surface::Tenant => $surface->value.':'.mb_strtolower($request->getHost()),
            default => null,
        };
    }

    /** Whether this request's session was minted on the surface the request is on. */
    public function admits(Request $request): bool
    {
        $marker = $this->marker($request);

        if ($marker === null || ! $request->hasSession()) {
            return true;
        }

        return $request->session()->get(self::KEY) === $marker;
    }

    /**
     * Mark a new session with this surface, and start over when the session belongs to
     * another one: everything it held is dropped and it gets a new id.
     */
    public function bind(Request $request): void
    {
        $marker = $this->marker($request);

        if ($marker === null || ! $request->hasSession()) {
            return;
        }

        $session = $request->session();
        $held = $session->get(self::KEY);

        if ($held === $marker) {
            return;
        }

        // Unmarked and empty is a session that has just begun. Unmarked with anything in
        // it was minted before the surfaces were split, and is nobody's here.
        $new = $held === null && array_diff_key($session->all(), ['_token' => true]) === [];

        if (! $new) {
            $session->invalidate();
        }

        $session->put(self::KEY, $marker);
    }
}
