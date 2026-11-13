<?php

declare(strict_types=1);

namespace App\Platform\Mfa;

use App\Http\Middleware\ResolveLocale;
use App\Mail\MailBrands;
use App\Platform\CloudflareGeo;
use App\Platform\DeviceLabel;
use App\Platform\Locale\HostedLocale;
use App\Platform\Locale\HostedLocales;
use App\Platform\OAuth\AppSignIns;
use Cbox\Id\Devices\Contracts\ApprovalOriginResolver;
use Cbox\Id\Devices\Enums\ApprovalKind;
use Cbox\Id\Devices\ValueObjects\ApprovalOrigin;
use Cbox\Id\UserProperties\Contracts\UserPreferences;
use Illuminate\Http\Request;
use Throwable;

/**
 * WHERE A PHONE APPROVAL WAS ASKED FROM, for the push and the approvals API.
 *
 * Only for a request a person's browser made (sign-in, step-up). Location is Cloudflare's
 * edge headers through the trusted ingress ({@see CloudflareGeo}) and nothing else — no
 * GeoIP database, no lookup; the address is never read for it. The device is the user
 * agent, shown as "Chrome on macOS" ({@see DeviceLabel::parts()}). The app a sign-in is for
 * is the OAuth client this browser is on its way to ({@see AppSignIns::pending()}), else
 * the brand the sign-in pages wear.
 *
 * A request a server filed — a key's held action — has no "where": the kind, nothing more.
 * Every lookup fails open to "unknown".
 */
final readonly class PhoneApprovalOrigin implements ApprovalOriginResolver
{
    public function capture(ApprovalKind $kind): ApprovalOrigin
    {
        if (! $kind->fromBrowser()) {
            return new ApprovalOrigin($kind);
        }

        $request = request();
        $geo = $this->geo($request);
        $agent = trim((string) $request->userAgent());

        return new ApprovalOrigin(
            kind: $kind,
            app: $kind === ApprovalKind::SignIn ? $this->signingInTo($request) : null,
            city: $geo?->city,
            countryCode: $geo?->countryCode,
            region: $geo?->region,
            userAgent: $agent !== '' && mb_check_encoding($agent, 'UTF-8') ? $agent : null,
            host: $this->host($request),
        );
    }

    public function device(?string $userAgent): array
    {
        return DeviceLabel::parts($userAgent);
    }

    /**
     * The person's own language preference, else — when they are the one at the browser
     * that asked — the language that page was drawn in, else the environment's default.
     */
    public function localeFor(string $subjectId, ApprovalKind $kind): string
    {
        try {
            $language = app(UserPreferences::class)->read($subjectId)->data->language ?? null;
            $preferred = HostedLocale::fromTag(is_string($language) ? $language : null);

            if ($preferred !== null) {
                return $preferred->value;
            }
        } catch (Throwable) {
            // No preference store, no such person here: fall through.
        }

        if ($kind->fromBrowser()) {
            $page = request()->attributes->get(ResolveLocale::ATTRIBUTE);

            if ($page instanceof HostedLocale) {
                return $page->value;
            }
        }

        try {
            return app(HostedLocales::class)->default()->value;
        } catch (Throwable) {
            return HostedLocale::English->value;
        }
    }

    private function geo(Request $request): ?CloudflareGeo
    {
        try {
            return CloudflareGeo::fromRequest($request);
        } catch (Throwable) {
            return null;
        }
    }

    private function signingInTo(Request $request): ?string
    {
        try {
            $pending = $request->hasSession() ? app(AppSignIns::class)->pending($request) : null;

            return $pending !== null ? $pending->clientName : app(MailBrands::class)->current()->name;
        } catch (Throwable) {
            return null;
        }
    }

    private function host(Request $request): ?string
    {
        try {
            $host = $request->getHost();

            return $host !== '' ? mb_substr($host, 0, 253) : null;
        } catch (Throwable) {
            return null;
        }
    }
}
