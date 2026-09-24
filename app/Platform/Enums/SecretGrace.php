<?php

declare(strict_types=1);

namespace App\Platform\Enums;

use Cbox\Id\OAuthServer\Contracts\ClientRegistry;

/**
 * How long an app's current secret keeps working after it is rotated.
 *
 * A rotation used to be a cut-over: the new secret existed and the old one was dead in the
 * same instant, so every deployment still holding it failed authentication until somebody
 * redeployed — which for an app with several servers, a queue worker and a cron box is not
 * one redeploy. {@see ClientRegistry::rotateSecret()} keeps the old secret alive for a
 * grace period instead; these are the periods a person is offered.
 *
 * FOUR CHOICES, NOT A NUMBER FIELD. What somebody is deciding is "how long do my
 * deployments need", and those are the answers people give — now, within the hour, by
 * tomorrow, by next week's release. A free field invites 30 days by reflex, and a secret
 * nobody meant to keep is a secret that leaks.
 *
 * Bounded by the install's own ceiling (`cbox-id.oauth.client_secrets.max_rotation_grace`):
 * a choice longer than it is not offered, and not accepted either, because the registry
 * refuses it — the list and the refusal read the same number.
 */
enum SecretGrace: int
{
    case Immediately = 0;
    case OneHour = 3600;
    case OneDay = 86400;
    case SevenDays = 604800;

    public function label(): string
    {
        return match ($this) {
            self::Immediately => 'Immediately',
            self::OneHour => 'After 1 hour',
            self::OneDay => 'After 24 hours',
            self::SevenDays => 'After 7 days',
        };
    }

    /** How long the replaced secret goes on working, as the end of a sentence. */
    public function overlap(): string
    {
        return match ($this) {
            self::Immediately => 'not at all',
            self::OneHour => 'for another hour',
            self::OneDay => 'for another 24 hours',
            self::SevenDays => 'for another 7 days',
        };
    }

    /**
     * The choices this install allows, shortest first. `Immediately` is always among them:
     * a leaked secret has to be stoppable whatever the ceiling says.
     *
     * @return list<self>
     */
    public static function offered(): array
    {
        $ceiling = self::ceiling();

        return array_values(array_filter(
            self::cases(),
            static fn (self $grace): bool => $grace->value <= $ceiling,
        ));
    }

    /** The longest grace this install allows, in seconds — the registry's own bound. */
    public static function ceiling(): int
    {
        $max = config('cbox-id.oauth.client_secrets.max_rotation_grace', 2_592_000);

        return is_numeric($max) ? max(0, (int) $max) : 0;
    }
}
