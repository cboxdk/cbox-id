<?php

declare(strict_types=1);

namespace Cbox\Id\Devices\Support;

use Cbox\Id\Devices\Contracts\ApprovalOriginResolver;
use Cbox\Id\Devices\Enums\ApprovalKind;
use Cbox\Id\Devices\ValueObjects\ApprovalOrigin;
use Cbox\Id\Devices\ValueObjects\PushPayload;
use DateTimeInterface;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Support\Carbon;
use Locale;
use Throwable;

/**
 * WHAT AN APPROVAL PUSH SAYS, in the approver's language.
 *
 * "Sign in to Cbox Billing?" over "Copenhagen, Denmark · Chrome on macOS · expires in
 * 5 min": what is being asked, for which app, and from roughly where — enough for a person
 * to recognise their own sign-in, or not.
 *
 * THE LOCK SCREEN IS PUBLIC. Anybody holding the phone reads the title and body, so they
 * carry the app's name, a city and country, a browser and an operating system, and nothing
 * else: no address, no email, no account identifier, and never a comparison code. The
 * sign-in code is the value the person compares between the browser and the app — printed
 * on the lock screen it would be readable without unlocking, so it is not printed. A CIBA
 * binding message is the transaction itself and is never pushed at all; the action
 * approvals the platform files show the action without its code.
 *
 * `data` repeats the same strings in structured form for the app (`purpose`,
 * `client_name`, `location`, `device`), absent when unknown. The app treats them as
 * untrusted, like the rest of the payload, and re-fetches the approval by id.
 */
final readonly class ApprovalPushText
{
    /** An app's name is chosen by whoever registered it; shown, never trusted, kept short. */
    private const int APP_MAX = 40;

    private const int ACTION_MAX = 80;

    public function __construct(private Translator $translator, private ApprovalOriginResolver $resolver) {}

    /**
     * @param  array<string, string>  $routing  kind, request id, url — passed through untouched
     * @param  string|null  $action  the binding message of a platform action approval; ignored for every other kind
     */
    public function payload(
        ApprovalOrigin $origin,
        string $locale,
        DateTimeInterface $expiresAt,
        array $routing,
        ?string $collapseKey,
        ?string $action = null,
    ): PushPayload {
        $app = $this->clean($origin->app, self::APP_MAX);
        $location = $this->location($origin, $locale);
        $device = $this->device($origin, $locale);

        $minutes = max(1, (int) ceil(Carbon::now()->diffInSeconds(Carbon::instance($expiresAt), false) / 60));
        $parts = array_values(array_filter([$location, $device], static fn (?string $part): bool => $part !== null));
        if ($parts === []) {
            $parts[] = $this->line('review', [], $locale);
        }
        $parts[] = $this->line('expires_in', ['minutes' => (string) $minutes], $locale);

        $data = $routing;
        $data['purpose'] = $origin->kind->value;
        foreach (['client_name' => $app, 'location' => $location, 'device' => $device] as $key => $value) {
            if ($value !== null) {
                $data[$key] = $value;
            }
        }

        return new PushPayload(
            title: $this->title($origin->kind, $app, $action, $locale),
            body: implode(' · ', $parts),
            data: $data,
            collapseKey: $collapseKey,
        );
    }

    /** "Copenhagen, Denmark", "Denmark", or null — the country named in $locale. */
    public function location(ApprovalOrigin $origin, string $locale): ?string
    {
        $country = $this->countryName($origin->countryCode, $locale);

        if ($country === null) {
            return null;
        }

        $city = $this->clean($origin->city, 60);

        return $city === null ? $country : $city.', '.$country;
    }

    /** "Chrome on macOS", "Chrome", or null. */
    public function device(ApprovalOrigin $origin, string $locale): ?string
    {
        ['browser' => $browser, 'platform' => $platform] = $this->resolver->device($origin->userAgent);
        $browser = $this->clean($browser, 30);
        $platform = $this->clean($platform, 30);

        if ($browser !== null && $platform !== null) {
            return $this->line('device', ['browser' => $browser, 'platform' => $platform], $locale);
        }

        return $browser ?? $platform;
    }

    public function countryName(?string $code, string $locale): ?string
    {
        if ($code === null || preg_match('/^[A-Z]{2}$/', $code) !== 1) {
            return null;
        }

        try {
            $name = class_exists(Locale::class) ? Locale::getDisplayRegion('-'.$code, $locale) : '';
        } catch (Throwable) {
            $name = '';
        }

        // ICU answers the code itself for a region it does not know.
        return is_string($name) && $name !== '' && $name !== $code ? $name : $code;
    }

    private function title(ApprovalKind $kind, ?string $app, ?string $action, string $locale): string
    {
        return match ($kind) {
            ApprovalKind::SignIn => $app !== null ? $this->line('sign_in_to', ['app' => $app], $locale) : $this->line('sign_in', [], $locale),
            ApprovalKind::StepUp => $this->line('step_up', [], $locale),
            ApprovalKind::Action => ($what = $this->clean($this->withoutCode($action), self::ACTION_MAX)) !== null
                ? $this->line('action', ['action' => $what], $locale)
                : $this->line('action_generic', [], $locale),
            ApprovalKind::Authentication => $app !== null ? $this->line('authentication', ['app' => $app], $locale) : $this->line('authentication_generic', [], $locale),
        };
    }

    /**
     * The binding message without its trailing comparison code. The platform writes them as
     * "<who> wants to run <action> · <code>": the code is compared in the app, unlocked.
     */
    private function withoutCode(?string $message): ?string
    {
        if ($message === null) {
            return null;
        }

        $cut = mb_strrpos($message, ' · ');

        return $cut === false ? $message : mb_substr($message, 0, $cut);
    }

    /** One line of text, with control characters gone and the length held. */
    private function clean(?string $value, int $max): ?string
    {
        if ($value === null || ! mb_check_encoding($value, 'UTF-8')) {
            return null;
        }

        $value = trim((string) preg_replace('/[\p{Cc}\p{Zl}\p{Zp}\s]+/u', ' ', $value));

        return $value === '' ? null : mb_strimwidth($value, 0, $max, '…');
    }

    /** @param array<string, string> $replace */
    private function line(string $key, array $replace, string $locale): string
    {
        $line = $this->translator->get('id-devices::push.'.$key, $replace, $locale);

        return is_string($line) ? $line : $key;
    }
}
