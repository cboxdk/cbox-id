<?php

declare(strict_types=1);

namespace App\Platform\Organizations;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * The organization this browser last opened, so `/` and an old bookmark land there.
 *
 * A remembered slug, nothing more: it never authorizes anything. Whatever it names is
 * checked against the person's memberships again before anything uses it, and a slug
 * the person cannot open falls through to their first organization or the picker.
 */
final class LastUsedOrganization
{
    public const COOKIE = 'cbox_last_org';

    private const DAYS = 400;

    public static function remember(Response $response, string $slug): void
    {
        $response->headers->setCookie(Cookie::create(
            name: self::COOKIE,
            value: $slug,
            expire: now()->addDays(self::DAYS),
            path: '/',
            secure: request()->isSecure(),
            httpOnly: true,
            sameSite: Cookie::SAMESITE_LAX,
        ));
    }

    public static function read(Request $request): ?string
    {
        $slug = $request->cookie(self::COOKIE);

        return is_string($slug) && $slug !== '' && strlen($slug) <= 120 ? $slug : null;
    }

    /**
     * The organization `/` should open for this person: the remembered one when they can
     * still open it, else the one their session is in, else their first. Null when they
     * belong to none.
     */
    public static function resolve(Request $request, OrganizationDirectory $directory, string $subjectId, ?string $sessionOrganizationId): ?OrganizationEntry
    {
        $entries = $directory->forSubject($subjectId);
        $remembered = self::read($request);

        foreach ($entries as $entry) {
            if ($entry->slug === $remembered) {
                return $entry;
            }
        }

        foreach ($entries as $entry) {
            if ($entry->id === $sessionOrganizationId) {
                return $entry;
            }
        }

        return $entries[0] ?? null;
    }
}
